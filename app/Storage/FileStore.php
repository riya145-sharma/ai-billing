<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * JSON file storage with locking and atomic writes.
 *
 * Concurrency model
 *  - Every data file "x.json" is guarded by its own lock file under storage/locks.
 *    Locks live in a separate file because atomic writes replace the data file
 *    (rename), and a lock held on a replaced inode would protect nothing.
 *  - Readers take a shared lock, writers an exclusive lock (flock, which works on
 *    Linux/LiteSpeed hosting and on Windows). Lock waits are bounded by a timeout.
 *  - Writes go to a temp file in the same directory, are flushed (fsync when
 *    available) and then renamed over the target, so a crash never leaves a
 *    half-written file. Readers therefore see either the old or the new version.
 *  - Locks are re-entrant within one process (nested update() on the same file
 *    is safe). Different files may be locked together only in a fixed order;
 *    each service documents its order.
 *
 * Paths are always relative to the data root and validated, so a caller can
 * never escape storage/data.
 */
final class FileStore
{
    private string $root;
    private string $lockDir;
    private bool $windows;

    /** @var array<string, array{handle: resource, mode: int, depth: int}> */
    private static array $held = [];

    private const SAFE_PATH = '#^[A-Za-z0-9][A-Za-z0-9_\-./]*$#';

    public function __construct(string $root, ?string $lockDir = null)
    {
        $this->windows = DIRECTORY_SEPARATOR === '\\';
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->lockDir = rtrim(str_replace('\\', '/', $lockDir ?? dirname($this->root) . '/locks'), '/');
        $this->ensureDir($this->root);
        $this->ensureDir($this->lockDir);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function lockDir(): string
    {
        return $this->lockDir;
    }

    /** Absolute path for a validated relative path. */
    public function path(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if (
            !preg_match(self::SAFE_PATH, $rel)
            || str_contains($rel, '..')
            || str_contains($rel, '//')
            || str_ends_with($rel, '/')
        ) {
            throw new StorageException("Unsafe storage path: {$rel}");
        }
        return $this->root . '/' . $rel;
    }

    public function exists(string $rel): bool
    {
        return is_file($this->path($rel));
    }

    // ------------------------------------------------------------------ locking

    /**
     * Run $fn while holding a lock for $rel.
     * Re-entrant: if this process already holds the lock, $fn runs immediately
     * (an exclusive request inside a shared hold is refused to avoid deadlock).
     */
    public function withLock(string $rel, bool $exclusive, callable $fn, float $timeoutSec = 15.0): mixed
    {
        $this->path($rel); // validate
        $lockFile = $this->lockDir . '/' . str_replace('/', '~', $rel) . '.lock';

        if (isset(self::$held[$lockFile])) {
            if ($exclusive && self::$held[$lockFile]['mode'] !== LOCK_EX) {
                throw new StorageException("Cannot upgrade a shared lock to exclusive: {$rel}");
            }
            self::$held[$lockFile]['depth']++;
            try {
                return $fn();
            } finally {
                self::$held[$lockFile]['depth']--;
            }
        }

        $h = @fopen($lockFile, 'c');
        if ($h === false) {
            throw new StorageException("Cannot open lock file for {$rel}. Is storage/locks writable?");
        }
        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        $deadline = microtime(true) + $timeoutSec;
        $sleepUs = 1000;
        while (!flock($h, $mode | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($h);
                throw new LockTimeoutException("Timed out waiting for a lock on {$rel}");
            }
            usleep($sleepUs + random_int(0, 500));
            $sleepUs = min($sleepUs * 2, 40_000);
        }
        if ($exclusive) {
            @touch($lockFile); // mtime marks last use, for cron cleanup of stale lock files
        }

        self::$held[$lockFile] = ['handle' => $h, 'mode' => $mode, 'depth' => 1];
        try {
            return $fn();
        } finally {
            unset(self::$held[$lockFile]);
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function holdsExclusive(string $rel): bool
    {
        $lockFile = $this->lockDir . '/' . str_replace('/', '~', $rel) . '.lock';
        return isset(self::$held[$lockFile]) && self::$held[$lockFile]['mode'] === LOCK_EX;
    }

    // ------------------------------------------------------------------ JSON documents

    /** Read a JSON document under a shared lock. */
    public function read(string $rel, array $default = []): array
    {
        return $this->withLock($rel, false, fn() => $this->readUnlocked($rel, $default));
    }

    /**
     * Read-modify-write under an exclusive lock.
     * $fn receives the document by reference and may return a value, which
     * update() returns. The file is only rewritten if the document changed.
     */
    public function update(string $rel, callable $fn, array $default = [], bool $pretty = false): mixed
    {
        return $this->withLock($rel, true, function () use ($rel, $fn, $default, $pretty) {
            $data = $this->readUnlocked($rel, $default);
            $before = $data;
            $result = $fn($data);
            if (!is_array($data)) {
                throw new StorageException("Update callback must leave an array for {$rel}");
            }
            if ($data !== $before || !$this->exists($rel)) {
                $this->writeUnlocked($rel, $data, $pretty);
            }
            return $result;
        });
    }

    /** Replace a whole document (exclusive lock). */
    public function write(string $rel, array $data, bool $pretty = false): void
    {
        $this->withLock($rel, true, fn() => $this->writeUnlocked($rel, $data, $pretty));
    }

    /** Create the document only if it doesn't exist. Returns true if created. */
    public function createIfMissing(string $rel, array $data, bool $pretty = true): bool
    {
        return $this->withLock($rel, true, function () use ($rel, $data, $pretty) {
            if ($this->exists($rel)) {
                return false;
            }
            $this->writeUnlocked($rel, $data, $pretty);
            return true;
        });
    }

    public function delete(string $rel): bool
    {
        return $this->withLock($rel, true, function () use ($rel) {
            $p = $this->path($rel);
            return is_file($p) ? @unlink($p) : false;
        });
    }

    private function readUnlocked(string $rel, array $default): array
    {
        $p = $this->path($rel);
        if (!is_file($p)) {
            return $default;
        }
        $raw = @file_get_contents($p);
        if ($raw === false) {
            throw new StorageException("Cannot read {$rel}");
        }
        if ($raw === '') {
            throw new CorruptDataException("Data file is empty: storage/data/{$rel}");
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new CorruptDataException("Data file is not valid JSON: storage/data/{$rel} ({$e->getMessage()})");
        }
        if (!is_array($data)) {
            throw new CorruptDataException("Data file does not contain a JSON object/array: storage/data/{$rel}");
        }
        return $data;
    }

    private function writeUnlocked(string $rel, array $data, bool $pretty): void
    {
        if (!$this->holdsExclusive($rel)) {
            throw new StorageException("Write to {$rel} without holding its exclusive lock");
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($data, $flags) . "\n";
        $this->atomicPut($this->path($rel), $json);
    }

    /**
     * Atomically replace $target with $bytes (temp file + rename in the same dir).
     * Public so binary stores (images, reports) can reuse it.
     */
    public function atomicPut(string $target, string $bytes): void
    {
        $dir = dirname($target);
        $this->ensureDir($dir);
        $tmp = $dir . '/.~' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $h = @fopen($tmp, 'xb');
        if ($h === false) {
            throw new StorageException('Cannot create temp file in ' . $this->relative($dir));
        }
        try {
            $len = strlen($bytes);
            for ($written = 0; $written < $len; $written += $n) {
                $n = fwrite($h, substr($bytes, $written, 1 << 20));
                if ($n === false || $n === 0) {
                    throw new StorageException('Write failed (disk full or quota exceeded?) for ' . $this->relative($target));
                }
            }
            fflush($h);
            if (function_exists('fsync')) {
                @fsync($h);
            }
        } catch (\Throwable $e) {
            fclose($h);
            @unlink($tmp);
            throw $e;
        }
        fclose($h);
        if (!$this->windows) {
            @chmod($tmp, 0640);
        }

        // Windows can briefly refuse to replace a file that an antivirus/indexer has open.
        for ($attempt = 1; ; $attempt++) {
            if (@rename($tmp, $target)) {
                return;
            }
            if ($attempt >= 10) {
                @unlink($tmp);
                throw new StorageException('Cannot replace ' . $this->relative($target));
            }
            usleep(25_000 * $attempt);
        }
    }

    // ------------------------------------------------------------------ JSON Lines (append-only logs)

    /** Append one record as a JSON line (exclusive lock). */
    public function append(string $rel, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $this->withLock($rel, true, function () use ($rel, $line) {
            $p = $this->path($rel);
            $this->ensureDir(dirname($p));
            $new = !is_file($p);
            if (@file_put_contents($p, $line, FILE_APPEND) !== strlen($line)) {
                throw new StorageException("Append failed for {$rel}");
            }
            if ($new && !$this->windows) {
                @chmod($p, 0640);
            }
        });
    }

    /**
     * Read a JSON Lines file. A torn final line (crash mid-append) is skipped.
     * @return array<int, array>
     */
    public function readLines(string $rel): array
    {
        return $this->withLock($rel, false, function () use ($rel) {
            $p = $this->path($rel);
            if (!is_file($p)) {
                return [];
            }
            $out = [];
            foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            return $out;
        });
    }

    // ------------------------------------------------------------------ directory helpers

    /** Relative paths of files under $relDir matching a glob pattern (sorted). */
    public function listFiles(string $relDir, string $pattern = '*.json'): array
    {
        $dir = $this->path($relDir);
        $files = glob($dir . '/' . $pattern) ?: [];
        sort($files, SORT_STRING);
        return array_map(fn($f) => substr(str_replace('\\', '/', $f), strlen($this->root) + 1), $files);
    }

    public function ensureDir(string $abs): void
    {
        if (is_dir($abs)) {
            return;
        }
        if (!@mkdir($abs, 0750, true) && !is_dir($abs)) {
            throw new StorageException('Cannot create directory ' . $this->relative($abs) . ' — check permissions');
        }
    }

    private function relative(string $abs): string
    {
        $abs = str_replace('\\', '/', $abs);
        $base = dirname($this->root);
        return str_starts_with($abs, $base) ? 'storage' . substr($abs, strlen($base)) : basename($abs);
    }
}
