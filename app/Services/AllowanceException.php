<?php
declare(strict_types=1);

namespace App\Services;

/** A daily content or speech allowance has no capacity left. */
final class AllowanceException extends \RuntimeException
{
    /** @param array $summary DailyAllowance summary for the exhausted kind */
    public function __construct(public readonly string $kind, public readonly int $limit, public readonly array $summary)
    {
        $label = $kind === 'speech' ? 'speech generations' : 'content generations';
        $held = $summary['reserved'] > 0 ? " ({$summary['reserved']} still processing or awaiting verification)" : '';
        parent::__construct("You have used today's {$limit} {$label}{$held}. The allowance resets at midnight IST.");
    }
}
