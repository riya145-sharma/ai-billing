<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Storage\Collection;
use App\Storage\FileStore;

/**
 * Admin-adjustable image slot windows (storage/data/slot_definitions.json),
 * as IST wall-clock times. end is exclusive: 06:00–12:00 covers 06:00:00–11:59:59.
 * "24:00" means end of day. Active windows may not overlap.
 */
final class SlotDefinitionRepository
{
    public const FILE = 'slot_definitions.json';

    private Collection $c;

    public function __construct(private FileStore $fs)
    {
        $this->c = new Collection($fs, self::FILE, [
            'code' => fn(array $r) => $r['code'] ?? null,
        ]);
    }

    /** All definitions sorted by start time. */
    public function all(): array
    {
        $rows = array_values($this->c->all());
        usort($rows, fn($a, $b) => [$a['start'], $a['code']] <=> [$b['start'], $b['code']]);
        return $rows;
    }

    /** Active definitions sorted by start time. */
    public function active(): array
    {
        return array_values(array_filter($this->all(), fn($r) => !empty($r['is_active'])));
    }

    /**
     * Change a window. Validation runs over the whole set inside the lock,
     * so two admins editing at once can't create overlapping windows.
     */
    public function updateWindow(string $code, string $start, string $end, bool $active, ?string $label = null, ?int $userId = null): array
    {
        $start = self::normalizeTime($start);
        $end = self::normalizeTime($end, true);
        if ($end <= $start) {
            throw new \InvalidArgumentException('End time must be after start time');
        }
        return $this->c->mutate(function (array &$items) use ($code, $start, $end, $active, $label, $userId) {
            $id = null;
            foreach ($items as $k => $it) {
                if ($it['code'] === $code) {
                    $id = $k;
                }
            }
            if ($id === null) {
                throw new \InvalidArgumentException("Unknown slot: {$code}");
            }
            $items[$id]['start'] = $start;
            $items[$id]['end'] = $end;
            $items[$id]['is_active'] = $active;
            if ($label !== null && trim($label) !== '') {
                $items[$id]['label'] = mb_substr(trim($label), 0, 40);
            }
            $items[$id]['updated_by'] = $userId;
            $items[$id]['updated_at'] = \App\Core\Clock::nowIso();
            self::assertNoOverlap($items);
            return $items[$id];
        });
    }

    public static function assertNoOverlap(array $items): void
    {
        $active = array_values(array_filter($items, fn($r) => !empty($r['is_active'])));
        usort($active, fn($a, $b) => $a['start'] <=> $b['start']);
        for ($i = 1; $i < count($active); $i++) {
            if ($active[$i]['start'] < $active[$i - 1]['end']) {
                throw new \InvalidArgumentException(
                    "{$active[$i - 1]['label']} ({$active[$i - 1]['start']}–{$active[$i - 1]['end']}) overlaps {$active[$i]['label']}"
                );
            }
        }
    }

    /** "6:00" / "06:00" / "06:00:00" → "06:00". "24:00" only allowed for an end time. */
    public static function normalizeTime(string $t, bool $isEnd = false): string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::00)?$/', trim($t), $m)) {
            throw new \InvalidArgumentException("Time must be HH:MM, got {$t}");
        }
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($min > 59 || $h > 24 || ($h === 24 && ($min !== 0 || !$isEnd))) {
            throw new \InvalidArgumentException("Invalid time {$t}");
        }
        return sprintf('%02d:%02d', $h, $min);
    }
}
