<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Services\SpeechGenerator;
use App\Storage\FileStore;

/**
 * Daily motivation automation settings (storage/data/automation/config.json):
 *   {"times": ["07:00","13:00","19:00"], "language": "English", "voice": "nova",
 *    "themes": ["…"], "users": {"5": {"enabled_at": "…", "enabled_by": 1}}, "updated_at": …, "updated_by": …}
 *
 * Times are IST wall-clock times. Users listed under "users" have automation on,
 * and with it the shared daily limit for manual and automatic generation.
 *
 * Repeat mode: when "interval_minutes" is set (e.g. 30) the fixed times are ignored and a
 * message is generated every N minutes, counted from "interval_since" (when the admin saved
 * it): saved at 11:52 with 30 → 12:22, 12:52, 13:22, … and on later days the same :22/:52 grid.
 */
final class AutomationConfigRepository
{
    public const FILE = 'automation/config.json';
    public const DAILY_LIMIT = 3;
    public const MAX_TIMES = 3;
    public const DEFAULT_TIMES = ['07:00', '13:00', '19:00'];
    /** Repeat intervals offered to administrators (0 = off, use the fixed times). */
    public const INTERVALS = [0, 15, 30, 60, 120];
    public const DEFAULT_LANGUAGE = 'English';
    public const DEFAULT_VOICE = 'nova';
    public const DEFAULT_THEMES = [
        'Starting the day with purpose',
        'Small steps and steady progress',
        'Resilience after setbacks',
        'Self-belief and confidence',
        'Gratitude for everyday moments',
        'Focus on what you can control',
        'Courage to try something new',
        'Kindness towards yourself',
        'Consistency over perfection',
        'Learning from mistakes',
        'Rest and recharging',
        'Celebrating small wins',
    ];

    public function __construct(private FileStore $fs)
    {
    }

    public static function defaults(): array
    {
        return ['times' => self::DEFAULT_TIMES, 'language' => self::DEFAULT_LANGUAGE, 'voice' => self::DEFAULT_VOICE,
            'themes' => self::DEFAULT_THEMES, 'users' => [], 'interval_minutes' => 0, 'interval_since' => null,
            'updated_at' => null, 'updated_by' => null];
    }

    public function get(): array
    {
        $doc = $this->fs->read(self::FILE, []);
        $cfg = array_merge(self::defaults(), array_intersect_key($doc, self::defaults()));
        $cfg['users'] = is_array($cfg['users']) ? $cfg['users'] : [];
        $cfg['interval_minutes'] = in_array((int)$cfg['interval_minutes'], self::INTERVALS, true) ? (int)$cfg['interval_minutes'] : 0;
        if ($cfg['interval_minutes'] > 0 && !is_string($cfg['interval_since'])) {
            $cfg['interval_minutes'] = 0;
        }
        return $cfg;
    }

    /** Successful content/speech generations allowed per enabled user per IST day (manual + automatic). */
    public function dailyLimit(): int
    {
        return self::limitFor($this->get());
    }

    public static function limitFor(array $cfg): int
    {
        $every = (int)($cfg['interval_minutes'] ?? 0);
        return $every > 0 ? max(self::DAILY_LIMIT, intdiv(1440, $every)) : self::DAILY_LIMIT;
    }

    /** Human description of the schedule, e.g. "every 30 minutes" or "at 07:00, 13:00 IST". */
    public static function describe(array $cfg): string
    {
        $every = (int)($cfg['interval_minutes'] ?? 0);
        if ($every > 0) {
            return 'every ' . ($every % 60 === 0 ? ($every === 60 ? 'hour' : ($every / 60) . ' hours') : "{$every} minutes");
        }
        return 'every day at ' . implode(', ', $cfg['times']) . ' IST';
    }

    /**
     * Repeat mode: every slot time (HH:MM) on an IST date, on the grid anchored at interval_since,
     * and only slots after interval_since. Empty when repeat mode is off.
     * @return list<string>
     */
    public static function intervalSlots(array $cfg, string $date): array
    {
        $every = (int)($cfg['interval_minutes'] ?? 0);
        if ($every <= 0 || !is_string($cfg['interval_since'] ?? null)) {
            return [];
        }
        $since = Clock::parseIso($cfg['interval_since']);
        $anchor = ((int)$since->format('G') * 60 + (int)$since->format('i')) % $every;
        $out = [];
        for ($m = $anchor; $m < 1440; $m += $every) {
            $time = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            if (Clock::localAt($date, $time) > $since) {
                $out[] = $time;
            }
        }
        return $out;
    }

    /**
     * Times the runner should have jobs for on $date at $now. Fixed mode: all configured times
     * (missed ones are caught up). Repeat mode: only the slot currently due, so a stopped cron
     * never produces a burst of missed messages.
     * @return list<string>
     */
    public static function plannedTimes(array $cfg, string $date, \DateTimeImmutable $now): array
    {
        $every = (int)($cfg['interval_minutes'] ?? 0);
        if ($every <= 0) {
            return $cfg['times'];
        }
        $from = $now->getTimestamp() - $every * 60;
        return array_values(array_filter(self::intervalSlots($cfg, $date), function (string $t) use ($date, $now, $from) {
            $at = Clock::localAt($date, $t)->getTimestamp();
            return $at <= $now->getTimestamp() && $at > $from;
        }));
    }

    /** Next repeat-mode slot after $now (today or tomorrow), or null when repeat mode is off. */
    public static function nextIntervalSlot(array $cfg, \DateTimeImmutable $now): ?array
    {
        foreach ([0, 1] as $offset) {
            $date = $now->modify("+{$offset} day")->format('Y-m-d');
            foreach (self::intervalSlots($cfg, $date) as $t) {
                if (Clock::localAt($date, $t) > $now) {
                    return ['date' => $date, 'time' => $t];
                }
            }
        }
        return null;
    }

    public function isEnabledFor(int $uid): bool
    {
        return isset($this->get()['users'][(string)$uid]);
    }

    /** @return list<int> */
    public function enabledUserIds(): array
    {
        return array_map('intval', array_keys($this->get()['users']));
    }

    /** Validate and save schedule/content settings. Returns the saved config. */
    public function saveSettings(array $times, string $language, string $voice, array $themes, ?int $adminId, int $interval = 0): array
    {
        if (!in_array($interval, self::INTERVALS, true)) {
            throw new \InvalidArgumentException('Choose a listed repeat interval.');
        }
        $times = self::validTimes($times);
        $language = trim($language);
        if (!preg_match('/^[\p{L}][\p{L} ()\-]{1,39}$/u', $language)) {
            throw new \InvalidArgumentException('Language must be 2–40 letters, for example English or Hindi.');
        }
        if (!in_array($voice, SpeechGenerator::VOICES, true)) {
            throw new \InvalidArgumentException('Choose a listed voice.');
        }
        $clean = [];
        foreach ($themes as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', (string)$t) ?? '');
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) < 3 || mb_strlen($t) > 80) {
                throw new \InvalidArgumentException('Each theme must be 3–80 characters.');
            }
            $clean[mb_strtolower($t)] = $t;
        }
        $clean = array_values($clean);
        if (count($clean) < count($times) || count($clean) > 40) {
            throw new \InvalidArgumentException('Enter between ' . count($times) . ' and 40 different themes (one per line), so each run gets its own theme.');
        }
        return $this->fs->update(self::FILE, function (array &$doc) use ($times, $language, $voice, $clean, $adminId, $interval) {
            $doc = array_merge(self::defaults(), $doc);
            // The repeat grid starts when repeat mode is switched on or its interval changes.
            $since = $interval === 0 ? null
                : ((int)$doc['interval_minutes'] === $interval && is_string($doc['interval_since']) ? $doc['interval_since'] : Clock::nowIso());
            $doc = array_merge($doc, ['times' => $times, 'language' => $language, 'voice' => $voice,
                'themes' => $clean, 'interval_minutes' => $interval, 'interval_since' => $since,
                'updated_at' => Clock::nowIso(), 'updated_by' => $adminId]);
            return $doc;
        }, [], true);
    }

    /**
     * Replace the set of enabled users. Users who stay enabled keep their original enabled_at.
     * @param list<int> $userIds
     */
    public function setEnabledUsers(array $userIds, ?int $adminId): array
    {
        return $this->fs->update(self::FILE, function (array &$doc) use ($userIds, $adminId) {
            $doc = array_merge(self::defaults(), $doc);
            $next = [];
            foreach (array_unique(array_map('intval', $userIds)) as $uid) {
                if ($uid > 0) {
                    $next[(string)$uid] = $doc['users'][(string)$uid] ?? ['enabled_at' => Clock::nowIso(), 'enabled_by' => $adminId];
                }
            }
            ksort($next, SORT_NUMERIC);
            $doc['users'] = $next === [] ? new \stdClass() : $next;
            $doc['updated_at'] = Clock::nowIso();
            $doc['updated_by'] = $adminId;
            $result = $doc;
            $result['users'] = $next;
            return $result;
        }, [], true);
    }

    /** @return list<string> sorted unique HH:MM times */
    public static function validTimes(array $times): array
    {
        $out = [];
        foreach ($times as $t) {
            $t = trim((string)$t);
            if ($t === '') {
                continue;
            }
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t)) {
                throw new \InvalidArgumentException('Times must use the 24-hour HH:MM format, for example 07:00.');
            }
            $out[$t] = $t;
        }
        sort($out, SORT_STRING);
        if ($out === [] || count($out) > self::MAX_TIMES) {
            throw new \InvalidArgumentException('Choose between 1 and ' . self::MAX_TIMES . ' different generation times.');
        }
        return array_values($out);
    }
}
