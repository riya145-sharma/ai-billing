<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Decimal-string arithmetic for money (8 decimal places for USD per-request
 * amounts). Uses bcmath when available, otherwise rounded float maths, which
 * is exact enough at 8 dp for the volumes this app records.
 */
final class Money
{
    public const SCALE = 8;

    public static function add(?string $a, ?string $b, int $scale = self::SCALE): string
    {
        $a = self::norm($a);
        $b = self::norm($b);
        return function_exists('bcadd') ? self::trim(bcadd($a, $b, $scale)) : self::fmt((float)$a + (float)$b, $scale);
    }

    public static function sub(?string $a, ?string $b, int $scale = self::SCALE): string
    {
        $a = self::norm($a);
        $b = self::norm($b);
        return function_exists('bcsub') ? self::trim(bcsub($a, $b, $scale)) : self::fmt((float)$a - (float)$b, $scale);
    }

    public static function mul(?string $a, ?string $b, int $scale = self::SCALE): string
    {
        $a = self::norm($a);
        $b = self::norm($b);
        return function_exists('bcmul') ? self::trim(bcmul($a, $b, $scale + 4), $scale) : self::fmt((float)$a * (float)$b, $scale);
    }

    /** $a / $b; returns "0" when $b is zero. */
    public static function div(?string $a, ?string $b, int $scale = self::SCALE): string
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if ((float)$b == 0.0) {
            return '0';
        }
        return function_exists('bcdiv') ? self::trim(bcdiv($a, $b, $scale + 4), $scale) : self::fmt((float)$a / (float)$b, $scale);
    }

    public static function cmp(?string $a, ?string $b): int
    {
        $a = self::norm($a);
        $b = self::norm($b);
        return function_exists('bccomp') ? bccomp($a, $b, self::SCALE) : ((float)$a <=> (float)$b);
    }

    public static function isDecimal(mixed $v): bool
    {
        return is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) === 1
            || is_int($v);
    }

    private static function norm(?string $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            throw new \InvalidArgumentException("Not a decimal: {$v}");
        }
        return $v;
    }

    private static function fmt(float $v, int $scale): string
    {
        return self::trim(number_format(round($v, $scale), $scale, '.', ''));
    }

    /** Round to $scale (half-up) and strip trailing zeros. */
    private static function trim(string $v, ?int $scale = null): string
    {
        if ($scale !== null && str_contains($v, '.')) {
            [$i, $f] = explode('.', $v, 2);
            if (strlen($f) > $scale) {
                $neg = str_starts_with($v, '-');
                $roundUp = (int)$f[$scale] >= 5;
                $v = $i . '.' . substr($f, 0, $scale);
                if ($roundUp && function_exists('bcadd')) {
                    $v = bcadd($v, ($neg ? '-' : '') . '0.' . str_repeat('0', $scale - 1) . '1', $scale);
                }
            }
        }
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        return $v === '-0' || $v === '' ? '0' : $v;
    }
}
