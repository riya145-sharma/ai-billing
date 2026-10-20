<?php
declare(strict_types=1);

/** Short helpers for templates. */

use App\Core\Csrf;
use App\Core\Url;

/** HTML-escape. Use for every value echoed into a page or attribute. */
function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    return e(Url::to($path, $query));
}

function asset(string $path): string
{
    return e(Url::asset($path));
}

function csrf_field(): string
{
    return Csrf::field();
}

/** Is this nav path the current page? ("/" only matches exactly.) */
function is_active(string $path): bool
{
    $cur = Url::path();
    return $path === '/' ? $cur === '/' : ($cur === $path || str_starts_with($cur, $path . '/'));
}

/** Automation job status → [label, tone, icon]; tone maps to the .st-* status classes. */
function automation_status(string $status): array
{
    return match ($status) {
        'completed'     => ['Delivered', 'good', 'check-circle-fill'],
        'running'       => ['Generating', 'info', 'arrow-repeat'],
        'queued'        => ['Queued', 'info', 'hourglass-split'],
        'due'           => ['Due — waiting for cron', 'info', 'hourglass-split'],
        'waiting_retry' => ['Retrying', 'warn', 'arrow-clockwise'],
        'uncertain'     => ['Uncertain', 'warn', 'question-circle'],
        'blocked'       => ['Blocked', 'warn', 'slash-circle'],
        'failed'        => ['Failed', 'bad', 'x-circle'],
        'scheduled'     => ['Scheduled', 'idle', 'clock'],
        'upcoming'      => ['Upcoming', 'idle', 'clock'],
        'skipped'       => ['Skipped', 'idle', 'dash-circle'],
        'expired'       => ['Expired', 'idle', 'dash-circle'],
        default         => [ucfirst(str_replace('_', ' ', $status)), 'idle', 'circle'],
    };
}

/** Status pill with icon + label (never colour alone). */
function automation_badge(string $status): string
{
    [$label, $tone, $icon] = automation_status($status);
    return '<span class="st-pill st-' . $tone . '"><i class="bi bi-' . $icon . '" aria-hidden="true"></i>' . e($label) . '</span>';
}

/** Segmented meter for small counts (CSP forbids inline widths). */
function pips(int $filled, int $total, string $label): string
{
    $out = '<span class="pips" role="img" aria-label="' . e($label) . '">';
    for ($i = 0; $i < $total; $i++) {
        $out .= '<span class="pip' . ($i < $filled ? ' on' : '') . '"></span>';
    }
    return $out . '</span>';
}
