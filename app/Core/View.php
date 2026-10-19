<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates in app/Views. A view sets $title etc. and is wrapped in
 * a layout that receives the rendered body as $content.
 * Always escape output with e() — templates never echo raw user data.
 */
final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $body = self::renderFile($view, $data);
        if ($layout === null) {
            return $body;
        }
        return self::renderFile($layout, $data + ['content' => $body]);
    }

    public static function renderFile(string $view, array $data): string
    {
        if (!preg_match('/^[a-z0-9_\/-]+$/', $view)) {
            throw new \InvalidArgumentException("Bad view name: {$view}");
        }
        $file = Config::get('paths.views') . '/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    public static function partial(string $view, array $data = []): string
    {
        return self::renderFile('partials/' . $view, $data);
    }

    // ---------------------------------------------------------------- formatting

    /** Stored UTC ISO → business-timezone display, e.g. "19 Oct 2026, 14:05". */
    public static function dateTime(?string $iso, string $format = 'd M Y, H:i'): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        try {
            return Clock::parseIso($iso)->format($format);
        } catch (\Throwable) {
            return '—';
        }
    }

    /** "in 3 h 20 min" / "5 min ago" relative to now. */
    public static function relative(?string $iso): string
    {
        if (!$iso) {
            return '—';
        }
        try {
            $diff = Clock::parseIso($iso)->getTimestamp() - Clock::now()->getTimestamp();
        } catch (\Throwable) {
            return '—';
        }
        $abs = abs($diff);
        $txt = match (true) {
            $abs < 60    => 'less than a minute',
            $abs < 3600  => intdiv($abs, 60) . ' min',
            $abs < 86400 => intdiv($abs, 3600) . ' h' . (intdiv($abs % 3600, 60) ? ' ' . intdiv($abs % 3600, 60) . ' min' : ''),
            default      => intdiv($abs, 86400) . ' d',
        };
        return $diff >= 0 ? "in {$txt}" : "{$txt} ago";
    }

    public static function number(int|float|string|null $n, int $decimals = 0): string
    {
        return number_format((float)($n ?? 0), $decimals);
    }

    /** Compact count: 1,234 → "1.2K", 3,400,000 → "3.4M". */
    public static function compact(int|float|string|null $n): string
    {
        $n = (float)($n ?? 0);
        foreach ([1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $div => $suffix) {
            if (abs($n) >= $div) {
                return rtrim(rtrim(number_format($n / $div, 1), '0'), '.') . $suffix;
            }
        }
        return number_format($n);
    }

    public static function usd(?string $amount): string
    {
        $v = (float)($amount ?? 0);
        return '$' . number_format($v, $v !== 0.0 && abs($v) < 1 ? 4 : 2);
    }

    public static function inr(?string $amount): string
    {
        return '₹' . number_format((float)($amount ?? 0), 2);
    }
}
