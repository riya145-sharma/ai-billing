<?php
declare(strict_types=1);

namespace App\Storage;

/** Any storage failure (I/O, unsafe path, invalid data). */
class StorageException extends \RuntimeException
{
}
