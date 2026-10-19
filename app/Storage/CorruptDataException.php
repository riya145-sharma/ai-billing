<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * A data file exists but is not valid JSON. The app refuses to continue
 * rather than silently resetting it (which could, e.g., wipe used quota slots).
 * Restore the file from storage/backups or fix it by hand.
 */
final class CorruptDataException extends StorageException
{
}
