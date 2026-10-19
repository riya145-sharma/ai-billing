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
