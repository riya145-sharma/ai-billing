<?php
declare(strict_types=1);

namespace App\Storage;

/** A unique constraint (e.g. user email) would be violated. */
final class DuplicateException extends StorageException
{
    public function __construct(string $message, public readonly string $constraint)
    {
        parent::__construct($message);
    }
}
