<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Ids;
use App\Storage\FileStore;

/**
 * Image generation attempts, one daily file per IST date:
 *   images/YYYY/MM/YYYY-MM-DD.json  {"items": {"img_…": {record}}}
 * (Binary images live separately in storage/images/YYYY/MM/DD/ — see ImageStore.)
 */
final class ImageGenerationRepository
{
    public const BASE = 'images';
    public const STATUSES = ['pending', 'succeeded', 'failed', 'unknown'];

    private const UPDATABLE = [
        'status', 'revised_prompt', 'image_count', 'storage_path', 'mime_type', 'file_size_bytes', 'sha256',
        'width', 'height', 'usage_log_id', 'cost_usd', 'usd_inr_rate', 'cost_inr', 'error_message', 'completed_at',
    ];

    public function __construct(private FileStore $fs)
    {
    }

    public function create(array $d): array
    {
        $prompt = trim((string)($d['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException('Prompt is required');
        }
        $date = Clock::businessDate();
        $id = Ids::make('img', $date);
        $rec = [
            'id'              => $id,
            'user_id'         => (int)($d['user_id'] ?? 0),
            'reservation_id'  => $d['reservation_id'] ?? null,
            'slot_code'       => $d['slot_code'] ?? null,
            'usage_log_id'    => $d['usage_log_id'] ?? null,
            'azure_config_id' => isset($d['azure_config_id']) ? (int)$d['azure_config_id'] : null,
            'model_name'      => $d['model_name'] ?? null,
            'prompt'          => $prompt,
            'revised_prompt'  => null,
            'size'            => $d['size'] ?? null,
            'quality'         => $d['quality'] ?? null,
            'requested_n'     => 1,
            'image_count'     => 0,
            'status'          => 'pending',
            'storage_path'    => null,
            'mime_type'       => null,
            'file_size_bytes' => null,
            'sha256'          => null,
            'width'           => null,
            'height'          => null,
            'cost_usd'        => null,
            'usd_inr_rate'    => null,
            'cost_inr'        => null,
            'error_message'   => null,
            'generation_date' => $date,
            'created_at'      => Clock::nowIso(),
            'completed_at'    => null,
        ];
        $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use ($id, $rec) {
            $doc += ['items' => []];
            $doc['items'][$id] = $rec;
        }, ['items' => []]);
        return $rec;
    }

    public function update(string $id, array $changes): array
    {
        $changes = array_intersect_key($changes, array_flip(self::UPDATABLE));
        if (isset($changes['status']) && !in_array($changes['status'], self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid image status');
        }
        $date = Ids::dateOf($id, 'img');
        return $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use ($id, $changes) {
            if (!isset($doc['items'][$id])) {
                throw new \InvalidArgumentException("Image generation {$id} not found");
            }
            $doc['items'][$id] = array_merge($doc['items'][$id], $changes);
            return $doc['items'][$id];
        }, ['items' => []]);
    }

    public function get(string $id): ?array
    {
        $date = Ids::dateOf($id, 'img');
        return $this->fs->read(Ids::dayFile(self::BASE, $date), ['items' => []])['items'][$id] ?? null;
    }

    public function forDay(string $date, ?int $userId = null): array
    {
        $items = array_values($this->fs->read(Ids::dayFile(self::BASE, $date), ['items' => []])['items'] ?? []);
        if ($userId !== null) {
            $items = array_values(array_filter($items, fn($r) => $r['user_id'] === $userId));
        }
        usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at'])); // newest first
        return $items;
    }

    /** Newest-first history across a date range. */
    public function history(string $from, string $to, ?int $userId = null, int $limit = 200): array
    {
        $out = [];
        foreach (array_reverse(Clock::datesBetween($from, $to)) as $date) {
            foreach ($this->forDay($date, $userId) as $r) {
                $out[] = $r;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /** Successful images in an IST month (up to today), optionally for one user. */
    public function countSucceededInMonth(string $ym, ?int $userId = null): int
    {
        $first = $ym . '-01';
        $last = min(
            (new \DateTimeImmutable($first, Clock::tz()))->modify('last day of this month')->format('Y-m-d'),
            Clock::businessDate()
        );
        $n = 0;
        foreach (Clock::datesBetween($first, $last) as $date) {
            foreach ($this->forDay($date, $userId) as $r) {
                if ($r['status'] === 'succeeded') {
                    $n += (int)$r['image_count'];
                }
            }
        }
        return $n;
    }
}
