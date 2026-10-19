<?php
declare(strict_types=1);

namespace App\Services;

/**
 * A quota rule refused a request. $reason is a stable machine code for the
 * UI/API; getMessage() is safe to show to the user.
 */
final class QuotaException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
