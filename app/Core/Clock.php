<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Single source of "now" and of business-date maths.
 * Stored timestamps are UTC ISO-8601; business dates (slots, daily/monthly billing) are in the
 * configured business timezone (Asia/Kolkata by default).
 * Tests can freeze time with Clock::freeze().
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;
    private static ?DateTimeZone $tz = null;

    public static function setBusinessTimezone(string $tz): void
    {
        self::$tz = new DateTimeZone($tz);
    }

    public static function tz(): DateTimeZone
    {
        return self::$tz ??= new DateTimeZone('Asia/Kolkata');
    }

    public static function utc(): DateTimeZone
    {
        static $utc = null;
        return $utc ??= new DateTimeZone('UTC');
    }

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }

    /** Current instant in the business timezone. */
    public static function now(): DateTimeImmutable
    {
        $n = self::$frozen ?? new DateTimeImmutable('now', self::utc());
        return $n->setTimezone(self::tz());
    }

    /** UTC ISO-8601 timestamp for stored records, e.g. 2026-10-19T06:57:02Z */
    public static function nowIso(): string
    {
        return self::toIso(self::now());
    }

    /** UTC ISO-8601 with milliseconds (request timing). */
    public static function nowIsoMs(): string
    {
        return self::now()->setTimezone(self::utc())->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function toIso(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::utc())->format('Y-m-d\TH:i:s\Z');
    }

    /** Parse a stored UTC ISO timestamp back to an instant (business tz). */
    public static function parseIso(string $iso): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $iso, self::utc())
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $iso, self::utc());
        if ($dt === false) {
            throw new \InvalidArgumentException("Bad ISO timestamp: {$iso}");
        }
        return $dt->setTimezone(self::tz());
    }

    /** Business date (Y-m-d) for an instant (default: now). */
    public static function businessDate(?DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(self::tz())->format('Y-m-d');
    }

    /** Business month (Y-m) for an instant (default: now). */
    public static function billingPeriod(?DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(self::tz())->format('Y-m');
    }

    /**
     * Business-local wall-clock time on a business date → instant.
     * Accepts '24:00:00' meaning midnight at the end of that date.
     */
    public static function localAt(string $date, string $time): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
            throw new \InvalidArgumentException("Bad date/time: {$date} {$time}");
        }
        $h = (int)$m[1];
        $base = new DateTimeImmutable($date . ' 00:00:00', self::tz());
        if ($h === 24) {
            return $base->modify('+1 day');
        }
        return $base->setTime($h, (int)$m[2], (int)($m[3] ?? 0));
    }

    /** UTC ISO range [start, end) covering one business date. */
    public static function dayRangeUtc(string $date): array
    {
        $start = self::localAt($date, '00:00:00');
        return [self::toIso($start), self::toIso($start->modify('+1 day'))];
    }

    /** Business dates from $from to $to inclusive (Y-m-d). */
    public static function datesBetween(string $from, string $to): array
    {
        $out = [];
        $d = new DateTimeImmutable($from, self::tz());
        $end = new DateTimeImmutable($to, self::tz());
        for ($i = 0; $d <= $end && $i < 3700; $i++, $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }
}
