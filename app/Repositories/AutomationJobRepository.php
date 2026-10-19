<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Ids;
use App\Storage\FileStore;

/**
 * Automation jobs, one file per IST day: automation/jobs/YYYY/MM/YYYY-MM-DD.json
 *   {"jobs": {"job_20261019_5_0700": {…}}}
 * A job id is deterministic (date + user + scheduled time), so however often
 * cron runs, overlaps or restarts, each user gets at most one job per slot.
 * Every state change is a read-modify-write under the day file's exclusive lock.
 */
final class AutomationJobRepository
{
    public const BASE = 'automation/jobs';
    public const AUDIO_BASE = 'automation/audio';
    public const TERMINAL = ['completed', 'failed', 'uncertain', 'skipped', 'expired'];

    public function __construct(private FileStore $fs)
    {
    }

    public static function file(string $date): string
    {
        return Ids::dayFile(self::BASE, $date);
    }

    public static function jobId(string $date, int $uid, string $time): string
    {
        return 'job_' . str_replace('-', '', $date) . '_' . $uid . '_' . str_replace(':', '', $time);
    }

    /** @return array{date: string, user_id: int, time: string}|null */
    public static function parseId(string $id): ?array
    {
        if (!preg_match('/^job_(\d{4})(\d{2})(\d{2})_([1-9]\d{0,8})_([01]\d|2[0-3])([0-5]\d)$/', $id, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return ['date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'user_id' => (int)$m[4], 'time' => "{$m[5]}:{$m[6]}"];
    }

    /** Relative storage path of a job's MP3. */
    public static function audioRel(int $uid, string $jobId): string
    {
        return self::AUDIO_BASE . '/' . $uid . '/' . $jobId . '.mp3';
    }

    /** @return array<string, array> id => job, ordered by scheduled time */
    public function forDay(string $date): array
    {
        $jobs = $this->fs->read(self::file($date), ['jobs' => []])['jobs'] ?? [];
        uasort($jobs, fn($a, $b) => [$a['time'], $a['user_id']] <=> [$b['time'], $b['user_id']]);
        return $jobs;
    }

    public function find(string $id): ?array
    {
        $p = self::parseId($id);
        return $p === null ? null : ($this->forDay($p['date'])[$id] ?? null);
    }

    /** Locked access to one day's job map. */
    public function mutateDay(string $date, callable $fn): mixed
    {
        return $this->fs->update(self::file($date), function (array &$doc) use ($fn) {
            $doc += ['jobs' => []];
            return $fn($doc['jobs']);
        }, ['jobs' => []]);
    }

    /**
     * Compare-and-set on one job: $fn gets the current job and returns the new
     * job, or null to leave it unchanged. Returns the stored job (or null if missing).
     */
    public function change(string $id, callable $fn): ?array
    {
        $p = self::parseId($id) ?? throw new \InvalidArgumentException('Invalid job id');
        return $this->mutateDay($p['date'], function (array &$jobs) use ($id, $fn) {
            if (!isset($jobs[$id])) {
                return null;
            }
            $next = $fn($jobs[$id]);
            if ($next === null) {
                return $jobs[$id];
            }
            $next['updated_at'] = Clock::nowIso();
            $jobs[$id] = $next;
            return $next;
        });
    }

    /** One user's jobs for the last $days IST days, newest first. */
    public function recentForUser(int $uid, int $days = 7): array
    {
        $out = [];
        $d = Clock::now();
        for ($i = 0; $i < $days; $i++, $d = $d->modify('-1 day')) {
            $day = array_filter($this->forDay($d->format('Y-m-d')), fn($j) => (int)$j['user_id'] === $uid);
            foreach (array_reverse($day) as $job) {
                $out[] = $job;
            }
        }
        return $out;
    }
}
