<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Money;
use App\Repositories\AuditLog;
use App\Repositories\BudgetRepository;
use App\Repositories\ImageGenerationRepository;
use App\Repositories\UsageLogRepository;
use App\Storage\FileStore;

/**
 * Spend against budgets.
 *   daily_api   — all Azure requests today (IST)
 *   monthly_api — all Azure requests this month
 *   daily_image — image generations today
 * A budget is org-wide or for one user. Spend comes from the usage summary
 * index (API) or today's image records (images), never from raw scans.
 */
final class BudgetService
{
    public const LABELS = [
        'daily_api'   => 'Daily API spend',
        'monthly_api' => 'Monthly API spend',
        'daily_image' => 'Daily image spend',
    ];

    private BudgetRepository $budgets;

    public function __construct(private FileStore $fs)
    {
        $this->budgets = new BudgetRepository($fs);
    }

    public function spentUsd(array $budget, ?\DateTimeImmutable $at = null): string
    {
        $at ??= Clock::now();
        $uid = ($budget['scope'] ?? 'org') === 'user' ? (int)$budget['user_id'] : null;
        $date = Clock::businessDate($at);

        if ($budget['budget_type'] === 'daily_image') {
            $sum = '0';
            foreach ((new ImageGenerationRepository($this->fs))->forDay($date, $uid) as $g) {
                if ($g['status'] === 'succeeded' && $g['cost_usd'] !== null) {
                    $sum = Money::add($sum, (string)$g['cost_usd']);
                }
            }
            return $sum;
        }

        $days = (new UsageLogRepository($this->fs))->summaryForMonth(Clock::billingPeriod($at))['days'] ?? [];
        if ($budget['budget_type'] === 'daily_api') {
            $days = isset($days[$date]) ? [$date => $days[$date]] : [];
        }
        $sum = '0';
        foreach ($days as $d) {
            $bucket = $uid === null ? ($d['all'] ?? []) : ($d['users'][(string)$uid] ?? []);
            $sum = Money::add($sum, (string)($bucket['cost_usd'] ?? '0'));
        }
        return $sum;
    }

    /** Every budget with its current spend and % used (for the budgets page and dashboard). */
    public function overview(): array
    {
        $out = [];
        foreach ($this->budgets->all() as $b) {
            $spent = $this->spentUsd($b);
            $pct = Money::cmp($b['amount_usd'], '0') > 0 ? (float)Money::div(Money::mul($spent, '100'), $b['amount_usd'], 2) : 0.0;
            $out[] = $b + ['spent_usd' => $spent, 'pct' => $pct];
        }
        return $out;
    }

    /**
     * Message if an active hard-limit budget that applies to this user is
     * already used up, or null. Checked BEFORE a new request is sent.
     */
    public function blockReason(int $userId, bool $isImage): ?string
    {
        $types = $isImage ? ['daily_image', 'daily_api', 'monthly_api'] : ['daily_api', 'monthly_api'];
        foreach ($types as $type) {
            foreach ($this->budgets->applicable($type, $userId) as $b) {
                if (!empty($b['hard_limit']) && Money::cmp($this->spentUsd($b), $b['amount_usd']) >= 0) {
                    $who = $b['scope'] === 'user' ? 'your' : 'the organisation’s';
                    $when = $type === 'monthly_api' ? 'this month' : 'today';
                    return "Spending is paused: {$who} " . strtolower(self::LABELS[$type]) . " limit of $" . $b['amount_usd'] . " has been reached {$when}.";
                }
            }
        }
        return null;
    }

    /** After a priced request: record any threshold the spend has now crossed (each fires once per period). */
    public function afterSpend(?int $userId, bool $isImage): void
    {
        $types = $isImage ? ['daily_image', 'daily_api', 'monthly_api'] : ['daily_api', 'monthly_api'];
        $audit = new AuditLog($this->fs);
        foreach ($types as $type) {
            foreach ($this->budgets->applicable($type, $userId) as $b) {
                if (Money::cmp($b['amount_usd'], '0') <= 0) {
                    continue;
                }
                $spent = $this->spentUsd($b);
                $pct = (float)Money::div(Money::mul($spent, '100'), $b['amount_usd'], 4);
                foreach ($b['thresholds'] as $t) {
                    if ($pct >= $t && $this->budgets->recordAlertOnce($b, BudgetRepository::periodKey($type), (int)$t, $spent)) {
                        $audit->record('budget.threshold_crossed', $userId, 'budget', (string)$b['id'], ['threshold' => $t, 'spent_usd' => $spent]);
                    }
                }
            }
        }
    }
}
