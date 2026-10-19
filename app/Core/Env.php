<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader (no Composer dependency required on shared hosting).
 * Values already present in the real environment win over the file, so a
 * key set in hPanel / server config is never overridden.
 */
final class Env
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($file) || !is_readable($file)) {
            return; // Real environment variables may be used instead.
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $val = trim(substr($line, $eq + 1));

            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                $quote = $val[0];
                $end = strrpos($val, $quote);
                $val = $end > 0 ? substr($val, 1, $end - 1) : substr($val, 1);
                if ($quote === '"') {
                    $val = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $val);
                }
            } else {
                $hash = strpos($val, ' #');
                if ($hash !== false) {
                    $val = rtrim(substr($val, 0, $hash));
                }
            }
            self::$values[$key] = $val;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        return self::$values[$key] ?? $default;
    }

    /** Values of every variable whose name ends in KEY/SECRET/TOKEN/PASSWORD (for log redaction). */
    public static function secretValues(): array
    {
        $out = [];
        foreach (self::$values + $_ENV + (array)getenv() as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match('/(KEY|SECRET|TOKEN|PASSWORD)$/', $k)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }
        return $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return ($v === null || !is_numeric($v)) ? $default : (int)$v;
    }
}
