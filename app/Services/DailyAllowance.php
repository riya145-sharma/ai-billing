<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Ids;
use App\Repositories\AutomationConfigRepository;
use App\Storage\FileStore;

/**
 * Per-user daily allowance for successful content and speech generations,
 * shared by manual requests and automation. Ledger: allowance/YYYY/MM/YYYY-MM-DD.json
 *   {"users": {"5": {"content": {"<key>": {"source": "manual|auto", "status": "pending", …}}, "speech": {…}}}}
 *
 * - The IST date is the partition, so the allowance resets at midnight without deleting history.
 * - success counts as used; pending and unknown hold capacity (a concurrent or
 *   uncertain request cannot be exceeded past the limit); failed/released never count.
 * - Every request is recorded; the limit only applies to users with automation enabled.
 * - Reserving is all-or-nothing across kinds and idempotent per key, under the day file's lock.
 *
 * Lock order: automation job day file → allowance day file.
 */
final class DailyAllowance
{
    public const BASE = 'allowance';
    public const KINDS = ['content', 'speech'];
    private const HOLDING = ['pending', 'unknown'];

    public function __construct(private FileStore $fs)
    {
    }

    public function limitFor(int $uid): ?int
    {
        $config = new AutomationConfigRepository($this->fs);
        return $config->isEnabledFor($uid) ? $config->dailyLimit() : null;
    }

    public static function file(string $date): string
    {
        return Ids::dayFile(self::BASE, $date);
    }

    /** Allowance overview for one user and IST date. */
    public function status(int $uid, ?string $date = null): array
    {
        $date ??= Clock::businessDate();
        $doc = $this->fs->read(self::file($date), ['users' => []]);
        $limit = $this->limitFor($uid);
        $out = ['date' => $date, 'limit' => $limit];
        foreach (self::KINDS as $kind) {
            $out[$kind] = self::summarize($doc['users'][(string)$uid][$kind] ?? [], $limit);
        }
        return $out;
    }

    public static function summarize(array $entries, ?int $limit): array
    {
        $s = ['used' => 0, 'reserved' => 0, 'failed' => 0, 'manual_used' => 0, 'auto_used' => 0, 'manual_reserved' => 0, 'auto_reserved' => 0];
        foreach ($entries as $e) {
            $source = ($e['source'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
            if (($e['status'] ?? '') === 'success') {
                $s['used']++;
                $s[$source . '_used']++;
            } elseif (in_array($e['status'] ?? '', self::HOLDING, true)) {
                $s['reserved']++;
                $s[$source . '_reserved']++;
            } elseif (($e['status'] ?? '') === 'failed') {
                $s['failed']++;
            }
        }
        $s['remaining'] = $limit === null ? null : max(0, $limit - $s['used'] - $s['reserved']);
        return $s;
    }

    /**
     * Reserve one unit of every kind in $kinds, all or nothing. A key that already
     * holds a pending/unknown/success entry is not counted twice.
     * @return string the IST date the reservation belongs to (pass it to settle())
     * @throws AllowanceException
     */
    public function reserve(int $uid, array $kinds, string $source, string $key, ?string $date = null): string
    {
        $date ??= Clock::businessDate();
        $limit = $this->limitFor($uid);
        $this->fs->update(self::file($date), function (array &$doc) use ($uid, $kinds, $source, $key, $limit) {
            $doc += ['users' => []];
            $u = $doc['users'][(string)$uid] ?? [];
            $add = [];
            foreach ($kinds as $kind) {
                if (!in_array($kind, self::KINDS, true)) {
                    throw new \InvalidArgumentException("Unknown allowance kind: {$kind}");
                }
                $existing = $u[$kind][$key] ?? null;
                if ($existing !== null && in_array($existing['status'], ['pending', 'unknown', 'success'], true)) {
                    continue;
                }
                if ($limit !== null) {
                    $sum = self::summarize($u[$kind] ?? [], $limit);
                    if ($sum['remaining'] < 1) {
                        throw new AllowanceException($kind, $limit, $sum);
                    }
                }
                $add[] = $kind;
            }
            foreach ($add as $kind) {
                $u[$kind][$key] = ['source' => $source === 'auto' ? 'auto' : 'manual', 'status' => 'pending',
                    'reserved_at' => Clock::nowIso(), 'settled_at' => null];
            }
            $doc['users'][(string)$uid] = $u;
        }, ['users' => []]);
        return $date;
    }

    /**
     * Reservation for a manual web request: the daily allowance first, then a request
     * slot on the deployment (shared rate limit, e.g. 3/min for tts). Converts refusals
     * into user-facing GeneratorExceptions; nothing is sent to Azure when refused.
     * @return string IST date of the reservation
     */
    public function reserveManual(int $uid, string $kind, string $key, array $config): string
    {
        try {
            $date = $this->reserve($uid, [$kind], 'manual', $key);
        } catch (AllowanceException $e) {
            throw new GeneratorException('daily_limit', $e->getMessage(), 429);
        }
        $slot = (new DeploymentThrottle($this->fs))->acquire($config);
        if (!$slot['allowed']) {
            $this->settle($uid, $kind, $key, 'released', $date);
            $limit = DeploymentThrottle::limitFor($config);
            throw new GeneratorException('rate', ($limit !== null
                ? "This Azure deployment allows {$limit} requests per minute, shared with scheduled automation. "
                : 'Azure asked us to slow down. ') . "Try again in {$slot['wait']} seconds.", 429);
        }
        return $date;
    }

    /**
     * Record the outcome of a reservation: success | failed | unknown | released.
     * Final states never change; unknown can only be resolved to released/success/failed.
     */
    public function settle(int $uid, string $kind, string $key, string $status, string $date): void
    {
        if (!in_array($status, ['success', 'failed', 'unknown', 'released'], true)) {
            throw new \InvalidArgumentException("Bad allowance status: {$status}");
        }
        $this->fs->update(self::file($date), function (array &$doc) use ($uid, $kind, $key, $status) {
            $e = $doc['users'][(string)$uid][$kind][$key] ?? null;
            if ($e === null || !in_array($e['status'], self::HOLDING, true) || ($e['status'] === 'unknown' && $status === 'unknown')) {
                return;
            }
            $doc['users'][(string)$uid][$kind][$key]['status'] = $status;
            $doc['users'][(string)$uid][$kind][$key]['settled_at'] = Clock::nowIso();
        }, ['users' => []]);
    }

    /**
     * Manual reservations still pending long after any request could have finished
     * (the web process died) become unknown: they keep holding capacity, but are
     * no longer shown as in progress. Returns how many changed.
     */
    public function reconcileStale(string $date, int $olderThanSec): int
    {
        if (!$this->fs->exists(self::file($date))) {
            return 0;
        }
        $cutoff = Clock::now()->getTimestamp() - $olderThanSec;
        return $this->fs->update(self::file($date), function (array &$doc) use ($cutoff) {
            $n = 0;
            foreach ($doc['users'] ?? [] as $uid => $kinds) {
                foreach ($kinds as $kind => $entries) {
                    foreach ($entries as $key => $e) {
                        if (($e['source'] ?? '') === 'manual' && $e['status'] === 'pending'
                            && Clock::parseIso($e['reserved_at'])->getTimestamp() < $cutoff) {
                            $doc['users'][$uid][$kind][$key]['status'] = 'unknown';
                            $doc['users'][$uid][$kind][$key]['settled_at'] = Clock::nowIso();
                            $n++;
                        }
                    }
                }
            }
            return $n;
        }, ['users' => []]);
    }
}
