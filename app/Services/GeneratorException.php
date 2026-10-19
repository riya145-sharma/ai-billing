<?php
declare(strict_types=1);

namespace App\Services;

/** The image generator refused or failed a request. getMessage() is safe to show to users. */
final class GeneratorException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
