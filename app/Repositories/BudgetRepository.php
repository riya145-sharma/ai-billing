<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Ids;
use App\Storage\Collection;
use App\Storage\FileStore;

/**
 * Budgets (storage/data/budgets.json) and threshold alerts
 * (storage/data/budget_alerts/YYYY/YYYY-MM.json, keyed so each threshold
 * fires once per budget per period).
 */
final class BudgetRepository
{
    public const FILE = 'budgets.json';
    public const TYPES = ['daily_api', 'monthly_api', 'daily_image'];
    public const THRESHOLDS = [50, 75, 90, 100];

    private Collection $c;

    public function __construct(private FileStore $fs)
    {
        $this->c = new Collection($fs, self::FILE, [
            'type+scope' => fn(array $b) => isset($b['budget_type']) ? $b['budget_type'] . '|' . self::scopeKey($b) : null,
        ]);
    }

    public static function scopeKey(array $b): string
    {
        return ($b['scope'] ?? 'org') === 'user' ? 'u:' . (int)$b['user_id'] : 'org';
    }

    /** @return array<int, array> */
    public function all(): array
    {
        return $this->c->all();
    }

    /** Active budgets of a type that apply to a user (org-wide + that user's own). */
    public function applicable(string $type, ?int $userId): array
    {
        return array_values($this->c->where(fn(array $b) => $b['budget_type'] === $type && !empty($b['is_active'])
            && ($b['scope'] === 'org' || ($userId !== null && (int)$b['user_id'] === $userId))));
    }

    public function save(array $d, ?int $userId = null, ?int $id = null): array
    {
        $type = (string)($d['budget_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown budget type');
        }
        $scope = ($d['scope'] ?? 'org') === 'user' ? 'user' : 'org';
        $budgetUser = $scope === 'user' ? (int)($d['user_id'] ?? 0) : null;
        if ($scope === 'user' && $budgetUser <= 0) {
            throw new \InvalidArgumentException('A user budget needs a user');
        }
        $amount = trim((string)($d['amount_usd'] ?? ''));
        if (!preg_match('/^\d{1,9}(\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException('Budget amount must be a non-negative number');
        }
        $thresholds = array_values(array_unique(array_map('intval', (array)($d['thresholds'] ?? self::THRESHOLDS))));
        sort($thresholds);
        if (!$thresholds || array_diff($thresholds, self::THRESHOLDS)) {
            throw new \InvalidArgumentException('Thresholds must be chosen from 50, 75, 90, 100');
        }
        $row = [
            'budget_type' => $type,
            'scope'       => $scope,
            'user_id'     => $budgetUser,
            'amount_usd'  => $amount,
            'thresholds'  => $thresholds,
            'hard_limit'  => (bool)($d['hard_limit'] ?? false),
            'is_active'   => (bool)($d['is_active'] ?? true),
            'updated_by'  => $userId,
        ];
        return $id === null ? $this->c->insert($row) : $this->c->update($id, $row);
    }

    public function delete(int $id): bool
    {
        return $this->c->delete($id);
    }

    /** Period key for a budget type at "now": YYYY-MM-DD for daily, YYYY-MM for monthly (IST). */
    public static function periodKey(string $type, ?\DateTimeImmutable $at = null): string
    {
        return $type === 'monthly_api' ? Clock::billingPeriod($at) : Clock::businessDate($at);
    }

    /**
     * Record that a threshold was crossed. Returns true the first time only
     * (concurrent requests crossing the same threshold produce one alert).
     */
    public function recordAlertOnce(array $budget, string $periodKey, int $threshold, string $spentUsd): bool
    {
        $file = Ids::monthFile('budget_alerts', substr($periodKey, 0, 7));
        $key = $budget['id'] . '|' . $periodKey . '|' . $threshold;
        return $this->fs->update($file, function (array &$doc) use ($key, $budget, $periodKey, $threshold, $spentUsd) {
            $doc += ['items' => []];
            if (isset($doc['items'][$key])) {
                return false;
            }
            $doc['items'][$key] = [
                'key'             => $key,
                'budget_id'       => $budget['id'],
                'budget_type'     => $budget['budget_type'],
                'scope_key'       => self::scopeKey($budget),
                'period_key'      => $periodKey,
                'threshold_pct'   => $threshold,
                'spent_usd'       => $spentUsd,
                'budget_usd'      => $budget['amount_usd'],
                'triggered_at'    => Clock::nowIso(),
                'acknowledged_at' => null,
                'acknowledged_by' => null,
            ];
            return true;
        }, ['items' => []]);
    }

    /** Alerts for a month, newest first. */
    public function alertsForMonth(string $ym, bool $unacknowledgedOnly = false): array
    {
        $items = array_values($this->fs->read(Ids::monthFile('budget_alerts', $ym), ['items' => []])['items'] ?? []);
        if ($unacknowledgedOnly) {
            $items = array_values(array_filter($items, fn($a) => $a['acknowledged_at'] === null));
        }
        usort($items, fn($a, $b) => strcmp($b['triggered_at'], $a['triggered_at']));
        return $items;
    }

    public function acknowledge(string $ym, string $key, int $userId): bool
    {
        return $this->fs->update(Ids::monthFile('budget_alerts', $ym), function (array &$doc) use ($key, $userId) {
            if (!isset($doc['items'][$key]) || $doc['items'][$key]['acknowledged_at'] !== null) {
                return false;
            }
            $doc['items'][$key]['acknowledged_at'] = Clock::nowIso();
            $doc['items'][$key]['acknowledged_by'] = $userId;
            return true;
        }, ['items' => []]);
    }
}
