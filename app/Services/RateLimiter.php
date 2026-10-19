<?php
declare(strict_types=1);

namespace App\Services;

use App\Storage\FileStore;

/**
 * Fixed-window rate limiter on files (no Redis on shared hosting).
 * One small file per bucket: ratelimit/<sha1>.json. Old files are swept by cron.
 */
final class RateLimiter
{
    public const BASE = 'ratelimit';

    public function __construct(private FileStore $fs)
    {
    }

    /**
     * Count one hit for $bucket. Returns true if allowed, false if over the limit.
     * e.g. hit("login:ip:1.2.3.4", 10, 900)
     */
    public function hit(string $bucket, int $limit, int $windowSec): bool
    {
        return $this->attempt($bucket, $limit, $windowSec)['allowed'];
    }

    /** @return array{allowed: bool, remaining: int, retry_after: int} */
    public function attempt(string $bucket, int $limit, int $windowSec): array
    {
        $now = time();
        $window = $now - ($now % max(1, $windowSec));
        return $this->fs->update(self::file($bucket), function (array &$doc) use ($limit, $window, $windowSec, $now) {
            if (($doc['window'] ?? 0) !== $window) {
                $doc = ['window' => $window, 'hits' => 0];
            }
            if ($doc['hits'] >= $limit) {
                return ['allowed' => false, 'remaining' => 0, 'retry_after' => $window + $windowSec - $now];
            }
            $doc['hits']++;
            return ['allowed' => true, 'remaining' => $limit - $doc['hits'], 'retry_after' => 0];
        });
    }

    public function reset(string $bucket): void
    {
        $this->fs->delete(self::file($bucket));
    }

    private static function file(string $bucket): string
    {
        return self::BASE . '/' . sha1($bucket) . '.json';
    }
}
