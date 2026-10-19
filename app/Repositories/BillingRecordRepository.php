<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Ids;
use App\Storage\FileStore;

/**
 * Billing rollups, one file per source per month:
 *   billing/estimated/YYYY/YYYY-MM.json     built from usage logs (app-tracked estimate)
 *   billing/azure_import/YYYY/YYYY-MM.json  figures imported from Azure Cost Management / invoices
 * The two sources are never summed together, so an estimate can't masquerade
 * as the Azure invoice. Each record is keyed by its bucket, making upserts idempotent.
 */
final class BillingRecordRepository
{
    public const SOURCES = ['estimated', 'azure_import'];
    public const GRANULARITY = ['daily', 'monthly'];

    private const NUMERIC = ['request_count', 'success_count', 'failed_count', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'image_count', 'unpriced_count'];
    private const MONEY = ['cost_usd', 'cost_inr', 'tax_usd', 'other_charges_usd', 'usd_inr_rate'];

    public function __construct(private FileStore $fs)
    {
    }

    public static function bucketKey(string $granularity, string $periodStart, int $userId, int $configId, string $model): string
    {
        return implode('|', [$granularity, $periodStart, $userId, $configId, $model]);
    }

    public function upsert(array $d): array
    {
        $source = (string)($d['source'] ?? '');
        $gran = (string)($d['granularity'] ?? '');
        if (!in_array($source, self::SOURCES, true) || !in_array($gran, self::GRANULARITY, true)) {
            throw new \InvalidArgumentException('Invalid billing source/granularity');
        }
        foreach (['period_start', 'period_end'] as $f) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d[$f] ?? ''))) {
                throw new \InvalidArgumentException("{$f} must be YYYY-MM-DD");
            }
        }
        $userId = (int)($d['user_id'] ?? 0);          // 0 = all users
        $configId = (int)($d['azure_config_id'] ?? 0); // 0 = all deployments
        $model = (string)($d['model_name'] ?? '');    // '' = all models
        $key = self::bucketKey($gran, $d['period_start'], $userId, $configId, $model);

        $row = [
            'key' => $key, 'source' => $source, 'granularity' => $gran,
            'period_start' => $d['period_start'], 'period_end' => $d['period_end'],
            'user_id' => $userId, 'azure_config_id' => $configId, 'model_name' => $model,
            'external_ref' => isset($d['external_ref']) ? mb_substr((string)$d['external_ref'], 0, 100) : null,
            'updated_at' => Clock::nowIso(),
        ];
        foreach (self::NUMERIC as $f) {
            $row[$f] = max(0, (int)($d[$f] ?? 0));
        }
        foreach (self::MONEY as $f) {
            $v = (string)($d[$f] ?? '0');
            if (!preg_match('/^\d+(\.\d+)?$/', $v)) {
                throw new \InvalidArgumentException("{$f} must be a non-negative decimal");
            }
            $row[$f] = $v;
        }

        $file = Ids::monthFile('billing/' . $source, substr($d['period_start'], 0, 7));
        return $this->fs->update($file, function (array &$doc) use ($key, $row) {
            $doc += ['items' => []];
            $row['created_at'] = $doc['items'][$key]['created_at'] ?? $row['updated_at'];
            $doc['items'][$key] = $row;
            return $row;
        }, ['items' => []]);
    }

    public function forMonth(string $source, string $ym): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid billing source');
        }
        return array_values($this->fs->read(Ids::monthFile('billing/' . $source, $ym), ['items' => []])['items'] ?? []);
    }
}
