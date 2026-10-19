<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Money;
use App\Storage\FileStore;

/**
 * Every outbound Azure call.
 *
 * Storage (partitioned so files stay small as history grows)
 *   usage/YYYY/MM/DD/HH.json           {"items": {"req_…": {record}}}   one file per IST hour
 *                                      (at the deployment's 100 RPM cap an hour holds ≤ 6,000
 *                                      records, so each locked rewrite stays small)
 *   usage/summary/YYYY/YYYY-MM.json    pre-aggregated index per day: totals, per model,
 *                                      per user, per IST hour — what dashboards read
 *
 * The summary is kept in step on every write by applying (new − old)
 * contributions under the hour-file lock (lock order: hour file → summary file).
 * rebuildSummary() recomputes a month from the raw files if ever needed.
 *
 * Token fields are null when Azure returned no usage — never estimated.
 */
final class UsageLogRepository
{
    public const BASE = 'usage';
    public const TYPES = ['responses', 'chat', 'speech', 'image_generation', 'image_edit', 'verification', 'other'];
    public const FINAL = ['success', 'failed'];
    public const STATUSES = ['pending', 'success', 'failed', 'timeout', 'unknown'];
    public const COST_STATUSES = ['priced', 'no_pricing', 'no_usage', 'not_billable'];

    private const INT_FIELDS = [
        'http_status', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens',
        'total_tokens', 'image_input_tokens', 'image_output_tokens', 'image_count', 'latency_ms', 'pricing_id',
    ];
    private const DEC_FIELDS = ['cost_usd', 'usd_inr_rate', 'cost_inr', 'reserved_cost_usd'];

    public function __construct(private FileStore $fs)
    {
    }

    /** Record a request before it is sent. Returns the new record (id = our request id). */
    public function start(array $d): array
    {
        $type = (string)($d['request_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown request type: {$type}");
        }
        $now = Clock::now();
        $date = Clock::businessDate($now);
        $id = Ids::makeHourly('req', $now);
        $rec = [
            'id'                  => $id,
            'client_request_id'   => Ids::uuid(),   // sent as x-ms-client-request-id
            'azure_request_id'    => null,
            'user_id'             => isset($d['user_id']) ? (int)$d['user_id'] : null,
            'azure_config_id'     => isset($d['azure_config_id']) ? (int)$d['azure_config_id'] : null,
            'deployment_name'     => (string)($d['deployment_name'] ?? ''),
            'model_name'          => $d['model_name'] ?? null,
            'endpoint_path'       => self::cleanPath((string)($d['endpoint_path'] ?? '')),
            'request_type'        => $type,
            'status'              => 'pending',
            'http_status'         => null,
            'input_tokens'        => null,
            'cached_input_tokens' => null,
            'output_tokens'       => null,
            'reasoning_tokens'    => null,
            'total_tokens'        => null,
            'image_input_tokens'  => null,
            'image_output_tokens' => null,
            'image_count'         => 0,
            'latency_ms'          => null,
            'pricing_id'          => null,
            'cost_usd'            => null,
            'usd_inr_rate'        => null,
            'cost_inr'            => null,
            'cost_status'         => 'no_usage',
            'reserved_cost_usd'   => isset($d['reserved_cost_usd']) ? (string)$d['reserved_cost_usd'] : null,
            'error_code'          => null,
            'error_message'       => null,
            'billing_date'        => $date,
            'billing_period'      => substr($date, 0, 7),
            'created_at'          => Clock::nowIsoMs(),
            'completed_at'        => null,
        ];
        $this->mutate($id, fn(?array $old) => $old === null ? $rec : throw new \LogicException('Id collision'));
        return $rec;
    }

    /**
     * Record the outcome. A request may move from pending/timeout/unknown to any
     * status; success/failed are final (use reprice() to change cost only).
     */
    public function complete(string $id, array $d): array
    {
        $status = (string)($d['status'] ?? '');
        if (!in_array($status, self::STATUSES, true) || $status === 'pending') {
            throw new \InvalidArgumentException("Invalid completion status: {$status}");
        }
        $fields = $this->clean($d);
        return $this->mutate($id, function (?array $old) use ($id, $fields) {
            if ($old === null) {
                throw new \InvalidArgumentException("Usage record {$id} not found");
            }
            if (in_array($old['status'], self::FINAL, true)) {
                throw new \LogicException("Usage record {$id} is already {$old['status']}");
            }
            return array_merge($old, $fields, ['completed_at' => Clock::nowIsoMs()]);
        });
    }

    /** Update only the cost fields of a record (pricing engine / price corrections). */
    public function reprice(string $id, array $cost): array
    {
        $fields = array_intersect_key($this->clean($cost), array_flip(['pricing_id', 'cost_usd', 'usd_inr_rate', 'cost_inr', 'cost_status']));
        return $this->mutate($id, function (?array $old) use ($id, $fields) {
            if ($old === null) {
                throw new \InvalidArgumentException("Usage record {$id} not found");
            }
            return array_merge($old, $fields);
        });
    }

    public function get(string $id): ?array
    {
        return $this->fs->read(self::fileFor($id), ['items' => []])['items'][$id] ?? null;
    }

    /** All records for one IST day, oldest first (reads up to 24 hourly files). */
    public function forDay(string $date): array
    {
        $items = [];
        foreach ($this->fs->listFiles(self::dayDir($date), '[0-2][0-9].json') as $file) {
            foreach ($this->fs->read($file, ['items' => []])['items'] ?? [] as $rec) {
                $items[] = $rec;
            }
        }
        usort($items, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return $items;
    }

    private static function dayDir(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            throw new \InvalidArgumentException("Bad date: {$date}");
        }
        return self::BASE . "/{$m[1]}/{$m[2]}/{$m[3]}";
    }

    /** Hourly partition file for a request id. */
    private static function fileFor(string $id): string
    {
        return self::dayDir(Ids::dateOf($id, 'req')) . '/' . Ids::hourOf($id, 'req') . '.json';
    }

    /** Records in a date range, optionally filtered. Reads one small file per day. */
    public function forRange(string $from, string $to, ?callable $filter = null): \Generator
    {
        foreach (Clock::datesBetween($from, $to) as $date) {
            foreach ($this->forDay($date) as $rec) {
                if ($filter === null || $filter($rec)) {
                    yield $rec;
                }
            }
        }
    }

    /** Pre-aggregated month index: ['days' => [date => ['all'=>…, 'models'=>…, 'users'=>…, 'hours'=>…]]] */
    public function summaryForMonth(string $ym): array
    {
        return $this->fs->read(Ids::monthFile(self::BASE . '/summary', $ym), ['days' => []]);
    }

    /** Recompute a month's summary from raw day files (repair / after manual edits). */
    public function rebuildSummary(string $ym): void
    {
        $file = Ids::monthFile(self::BASE . '/summary', $ym);
        $days = [];
        $first = $ym . '-01';
        $last = (new \DateTimeImmutable($first, Clock::tz()))->modify('last day of this month')->format('Y-m-d');
        foreach (Clock::datesBetween($first, $last) as $date) {
            foreach ($this->forDay($date) as $rec) {
                self::applyContribution($days, $rec, 1);
            }
        }
        $this->fs->write($file, ['days' => $days, 'rebuilt_at' => Clock::nowIso()]);
    }

    // ------------------------------------------------------------------ internals

    /**
     * Locked read-modify-write of one record + summary delta.
     * $fn(?array $old): array $new
     */
    private function mutate(string $id, callable $fn): array
    {
        $date = Ids::dateOf($id, 'req');
        $sumFile = Ids::monthFile(self::BASE . '/summary', substr($date, 0, 7));

        return $this->fs->update(self::fileFor($id), function (array &$doc) use ($id, $fn, $sumFile) {
            $doc += ['items' => []];
            $old = $doc['items'][$id] ?? null;
            $new = $fn($old);
            $doc['items'][$id] = $new;
            $this->fs->update($sumFile, function (array &$sum) use ($old, $new) {
                $sum += ['days' => []];
                if ($old !== null) {
                    self::applyContribution($sum['days'], $old, -1);
                }
                self::applyContribution($sum['days'], $new, 1);
            }, ['days' => []]);
            return $new;
        }, ['items' => []]);
    }

    private static function applyContribution(array &$days, array $r, int $sign): void
    {
        $date = $r['billing_date'];
        $hour = Clock::parseIso($r['created_at'])->format('H');
        $model = (string)($r['model_name'] ?: $r['deployment_name'] ?: 'unknown');
        $user = (string)($r['user_id'] ?? 0);
        $c = self::contribution($r);

        $days[$date] ??= ['all' => [], 'models' => [], 'users' => [], 'hours' => []];
        foreach ([['all'], ['models', $model], ['users', $user], ['hours', $hour]] as $path) {
            $bucket = &$days[$date][$path[0]];
            if (isset($path[1])) {
                $bucket[$path[1]] ??= [];
                $bucket = &$bucket[$path[1]];
            }
            foreach ($c as $k => $v) {
                if (is_string($v)) {
                    $bucket[$k] = $sign > 0 ? Money::add($bucket[$k] ?? '0', $v) : Money::sub($bucket[$k] ?? '0', $v);
                } else {
                    $bucket[$k] = ($bucket[$k] ?? 0) + $sign * $v;
                }
            }
            unset($bucket);
        }
    }

    private static function contribution(array $r): array
    {
        $priced = $r['cost_status'] === 'priced' && $r['cost_usd'] !== null;
        return [
            'requests'            => 1,
            'success'             => (int)($r['status'] === 'success'),
            'failed'              => (int)in_array($r['status'], ['failed', 'timeout'], true),
            'pending'             => (int)in_array($r['status'], ['pending', 'unknown'], true),
            'input_tokens'        => (int)($r['input_tokens'] ?? 0),
            'cached_input_tokens' => (int)($r['cached_input_tokens'] ?? 0),
            'output_tokens'       => (int)($r['output_tokens'] ?? 0),
            'total_tokens'        => (int)($r['total_tokens'] ?? 0),
            'usage_missing'       => (int)($r['status'] === 'success' && $r['total_tokens'] === null && $r['input_tokens'] === null),
            'image_count'         => (int)($r['image_count'] ?? 0),
            'unpriced'            => (int)($r['status'] === 'success' && !$priced && $r['cost_status'] !== 'not_billable'),
            'cost_usd'            => $priced ? (string)$r['cost_usd'] : '0',
            'cost_inr'            => $priced && $r['cost_inr'] !== null ? (string)$r['cost_inr'] : '0',
        ];
    }

    private function clean(array $d): array
    {
        $out = [];
        foreach (self::INT_FIELDS as $f) {
            if (array_key_exists($f, $d)) {
                $v = $d[$f];
                if ($v !== null && (filter_var($v, FILTER_VALIDATE_INT) === false || (int)$v < 0)) {
                    throw new \InvalidArgumentException("{$f} must be a non-negative integer or null");
                }
                $out[$f] = $v === null ? null : (int)$v;
            }
        }
        foreach (self::DEC_FIELDS as $f) {
            if (array_key_exists($f, $d)) {
                $v = $d[$f] === null ? null : (string)$d[$f];
                if ($v !== null && !preg_match('/^\d+(\.\d+)?$/', $v)) {
                    throw new \InvalidArgumentException("{$f} must be a non-negative decimal or null");
                }
                $out[$f] = $v;
            }
        }
        if (isset($d['status'])) {
            $out['status'] = $d['status'];
        }
        if (isset($d['cost_status'])) {
            if (!in_array($d['cost_status'], self::COST_STATUSES, true)) {
                throw new \InvalidArgumentException('Invalid cost_status');
            }
            $out['cost_status'] = $d['cost_status'];
        }
        if (array_key_exists('azure_request_id', $d)) {
            $out['azure_request_id'] = $d['azure_request_id'] === null ? null : mb_substr((string)$d['azure_request_id'], 0, 100);
        }
        if (array_key_exists('error_code', $d)) {
            $out['error_code'] = $d['error_code'] === null ? null : mb_substr((string)$d['error_code'], 0, 100);
        }
        if (array_key_exists('error_message', $d)) {
            $out['error_message'] = $d['error_message'] === null ? null : mb_substr(Logger::redact((string)$d['error_message']), 0, 1000);
        }
        return $out;
    }

    /** Keep only the URL path; query strings may carry api-version or keys. */
    private static function cleanPath(string $p): string
    {
        $path = parse_url($p, PHP_URL_PATH);
        return mb_substr(is_string($path) ? $path : '', 0, 255);
    }
}
