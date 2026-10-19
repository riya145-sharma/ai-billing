<?php
declare(strict_types=1);

namespace App\Storage;

/** A lock could not be acquired within the timeout (heavy contention or a stuck process). */
final class LockTimeoutException extends StorageException
{
}
