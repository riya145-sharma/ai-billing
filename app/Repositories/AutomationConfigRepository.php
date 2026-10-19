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
 */
final class AutomationConfigRepository
{
    public const FILE = 'automation/config.json';
    public const DAILY_LIMIT = 3;
    public const MAX_TIMES = 3;
    public const DEFAULT_TIMES = ['07:00', '13:00', '19:00'];
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
            'themes' => self::DEFAULT_THEMES, 'users' => [], 'updated_at' => null, 'updated_by' => null];
    }

    public function get(): array
    {
        $doc = $this->fs->read(self::FILE, []);
        $cfg = array_merge(self::defaults(), array_intersect_key($doc, self::defaults()));
        $cfg['users'] = is_array($cfg['users']) ? $cfg['users'] : [];
        return $cfg;
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
    public function saveSettings(array $times, string $language, string $voice, array $themes, ?int $adminId): array
    {
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
        return $this->fs->update(self::FILE, function (array &$doc) use ($times, $language, $voice, $clean, $adminId) {
            $doc = array_merge(self::defaults(), $doc, ['times' => $times, 'language' => $language, 'voice' => $voice,
                'themes' => $clean, 'updated_at' => Clock::nowIso(), 'updated_by' => $adminId]);
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
