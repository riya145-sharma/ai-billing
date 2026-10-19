<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Unique, date-addressable ids: <prefix>_<YYYYMMDD>_<16 hex>, e.g.
 *   req_20261019_9f2c4e1a7b3d5c60   (API request)
 *   img_20261019_…                  (image generation)
 *   rsv_20261019_…                  (quota reservation)
 * High-volume records (API requests) also embed the IST hour —
 *   req_2026101913_9f2c4e1a7b3d5c60 — so they live in hourly files.
 * The embedded IST date/hour tells us which partition file holds the record,
 * so no separate id → file index is needed. 64 random bits per day make
 * collisions practically impossible.
 */
final class Ids
{
    public static function make(string $prefix, ?string $date = null): string
    {
        self::assertPrefix($prefix);
        $d = str_replace('-', '', $date ?? Clock::businessDate());
        if (!preg_match('/^\d{8}$/', $d)) {
            throw new \InvalidArgumentException("Bad date for id: {$date}");
        }
        return "{$prefix}_{$d}_" . bin2hex(random_bytes(8));
    }

    /** Id that also embeds the IST hour (for hourly partitions). */
    public static function makeHourly(string $prefix, \DateTimeImmutable $at): string
    {
        self::assertPrefix($prefix);
        return $prefix . '_' . $at->setTimezone(Clock::tz())->format('YmdH') . '_' . bin2hex(random_bytes(8));
    }

    public static function isValid(string $id, string $prefix): bool
    {
        return (bool)preg_match('/^' . preg_quote($prefix, '/') . '_\d{8}(\d{2})?_[0-9a-f]{16}$/', $id);
    }

    /** IST hour ("00"–"23") embedded in an hourly id. */
    public static function hourOf(string $id, string $prefix): string
    {
        if (!preg_match('/^' . preg_quote($prefix, '/') . '_\d{8}(\d{2})_[0-9a-f]{16}$/', $id, $m) || (int)$m[1] > 23) {
            throw new \InvalidArgumentException("Invalid hourly {$prefix} id");
        }
        return $m[1];
    }

    /** Business date (Y-m-d) embedded in an id. */
    public static function dateOf(string $id, string $prefix): string
    {
        if (!preg_match('/^' . preg_quote($prefix, '/') . '_(\d{4})(\d{2})(\d{2})(?:\d{2})?_[0-9a-f]{16}$/', $id, $m)) {
            throw new \InvalidArgumentException("Invalid {$prefix} id");
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new \InvalidArgumentException("Invalid date in {$prefix} id");
        }
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /** RFC 4122 v4 UUID (used as client idempotency keys and Azure client request ids). */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Daily partition path: "<base>/YYYY/MM/YYYY-MM-DD.<ext>" */
    public static function dayFile(string $base, string $date, string $ext = 'json'): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $m)) {
            throw new \InvalidArgumentException("Bad date: {$date}");
        }
        return "{$base}/{$m[1]}/{$m[2]}/{$date}.{$ext}";
    }

    /** Monthly partition path: "<base>/YYYY/YYYY-MM.<ext>" */
    public static function monthFile(string $base, string $ym, string $ext = 'json'): string
    {
        if (!preg_match('/^(\d{4})-\d{2}$/', $ym, $m)) {
            throw new \InvalidArgumentException("Bad month: {$ym}");
        }
        return "{$base}/{$m[1]}/{$ym}.{$ext}";
    }

    private static function assertPrefix(string $p): void
    {
        if (!preg_match('/^[a-z]{2,8}$/', $p)) {
            throw new \InvalidArgumentException("Bad id prefix: {$p}");
        }
    }
}
