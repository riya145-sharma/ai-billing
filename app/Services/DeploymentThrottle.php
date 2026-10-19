<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Storage\FileStore;

/**
 * Requests-per-minute guard for one Azure deployment, shared by every process
 * (web requests and cron): throttle/<sha1>.json holds the send times of the last
 * minute (sliding window) and any cooldown Azure asked for with Retry-After.
 * The limit is the deployment's rpm_limit (e.g. 3 for the tts deployment);
 * without one, only cooldowns apply.
 */
final class DeploymentThrottle
{
    public const BASE = 'throttle';
    private const WINDOW = 60;

    public function __construct(private FileStore $fs)
    {
    }

    public static function limitFor(array $config): ?int
    {
        $v = $config['rpm_limit'] ?? null;
        return is_int($v) && $v > 0 ? $v : (is_string($v) && ctype_digit($v) && (int)$v > 0 ? (int)$v : null);
    }

    /**
     * Take one request slot now, if available.
     * @return array{allowed: bool, wait: int} wait = seconds until a slot frees up
     */
    public function acquire(array $config): array
    {
        $limit = self::limitFor($config);
        $now = Clock::now()->getTimestamp();
        return $this->fs->update(self::file($config), function (array &$d) use ($limit, $now) {
            $state = self::evaluate($d, $limit, $now);
            if ($state['wait'] === 0) {
                $d['hits'][] = $now;
            }
            return ['allowed' => $state['wait'] === 0, 'wait' => $state['wait']];
        });
    }

    /** Seconds until a slot is free, without taking one. */
    public function waitTime(array $config): int
    {
        $d = $this->fs->read(self::file($config));
        return self::evaluate($d, self::limitFor($config), Clock::now()->getTimestamp())['wait'];
    }

    /** Azure said 429 / Retry-After: nobody sends to this deployment until the cooldown ends. */
    public function cooldown(array $config, int $seconds): void
    {
        $until = Clock::now()->getTimestamp() + max(1, min(3600, $seconds));
        $this->fs->update(self::file($config), function (array &$d) use ($until) {
            $d['cooldown_until'] = max((int)($d['cooldown_until'] ?? 0), $until);
        });
    }

    private static function evaluate(array &$d, ?int $limit, int $now): array
    {
        $d['hits'] = array_values(array_filter((array)($d['hits'] ?? []), fn($t) => is_int($t) && $t > $now - self::WINDOW && $t <= $now));
        $cool = (int)($d['cooldown_until'] ?? 0);
        if ($cool > $now) {
            return ['wait' => $cool - $now];
        }
        if ($limit !== null && count($d['hits']) >= $limit) {
            // +1 s margin so we never land on the boundary of Azure's own window.
            return ['wait' => max(1, min($d['hits']) + self::WINDOW + 1 - $now)];
        }
        return ['wait' => 0];
    }

    private static function file(array $config): string
    {
        return self::BASE . '/' . sha1(strtolower(rtrim((string)($config['endpoint_base'] ?? ''), '/')) . '|' . ($config['deployment_name'] ?? '')) . '.json';
    }
}
