<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Config;
use App\Storage\FileStore;
use App\Storage\StorageException;

/**
 * Generated CSV/PDF statements.
 *   storage/reports/YYYY/MM/<number>.<csv|pdf>     the files
 *   storage/data/reports/sequence.json             gap-free numbers per year
 *   storage/data/reports/index/YYYY.json           metadata per report
 */
final class ReportRepository
{
    public function __construct(private FileStore $fs)
    {
    }

    /** Next statement number, e.g. SND-USG-2026-0001 (atomic, gap-free per year). */
    public function nextNumber(string $prefix): string
    {
        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $prefix)) {
            throw new \InvalidArgumentException('Report prefix may only contain A–Z, 0–9 and -');
        }
        $year = Clock::now()->format('Y');
        $n = $this->fs->update('reports/sequence.json', function (array &$doc) use ($year) {
            $doc[$year] = ($doc[$year] ?? 0) + 1;
            return $doc[$year];
        });
        return sprintf('%s-%s-%04d', $prefix, $year, $n);
    }

    /** Store the file and its metadata. Returns the metadata row. */
    public function save(string $number, string $format, string $bytes, array $meta, ?int $userId): array
    {
        if (!preg_match('/^[A-Z0-9-]{2,20}-\d{4}-\d{4,}$/', $number) || !in_array($format, ['csv', 'pdf'], true)) {
            throw new \InvalidArgumentException('Invalid report number or format');
        }
        $now = Clock::now();
        $rel = $now->format('Y/m') . "/{$number}.{$format}";
        $this->fs->atomicPut(self::root() . '/' . $rel, $bytes);

        $row = [
            'number'       => $number,
            'format'       => $format,
            'report_type'  => (string)($meta['report_type'] ?? ''),
            'period_start' => $meta['period_start'] ?? null,
            'period_end'   => $meta['period_end'] ?? null,
            'filters'      => $meta['filters'] ?? [],
            'total_usd'    => $meta['total_usd'] ?? null,
            'total_inr'    => $meta['total_inr'] ?? null,
            'usd_inr_rate' => $meta['usd_inr_rate'] ?? null,
            'path'         => $rel,
            'bytes'        => strlen($bytes),
            'created_by'   => $userId,
            'created_at'   => Clock::nowIso(),
        ];
        $this->fs->update('reports/index/' . $now->format('Y') . '.json', function (array &$doc) use ($row) {
            $doc += ['items' => []];
            $doc['items'][$row['number'] . '.' . $row['format']] = $row;
        }, ['items' => []]);
        return $row;
    }

    public function list(string $year): array
    {
        $items = array_values($this->fs->read("reports/index/{$year}.json", ['items' => []])['items'] ?? []);
        usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $items;
    }

    /** Absolute path of a stored report, refusing anything outside storage/reports. */
    public static function resolve(string $rel): string
    {
        if (!preg_match('#^\d{4}/\d{2}/[A-Z0-9-]+-\d{4}-\d{4,}\.(csv|pdf)$#', $rel)) {
            throw new StorageException('Invalid report path');
        }
        $root = realpath(self::root());
        $path = realpath(self::root() . '/' . $rel);
        if ($root === false || $path === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root) . '/')) {
            throw new StorageException('Report not found');
        }
        return $path;
    }

    private static function root(): string
    {
        return rtrim(str_replace('\\', '/', (string)Config::get('paths.reports')), '/');
    }
}
