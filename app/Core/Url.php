<?php
declare(strict_types=1);

namespace App\Core;

/**
 * URL helpers that work both at a domain root (Hostinger, php -S) and in a
 * sub-folder (XAMPP: http://localhost/ai-billing). The sub-folder is taken
 * from the path part of APP_URL and only applied when the request is under it.
 */
final class Url
{
    private static ?string $base = null;

    /** "" at a domain root, "/ai-billing" in a sub-folder. Never ends in "/". */
    public static function basePath(): string
    {
        if (self::$base === null) {
            $p = rtrim((string)parse_url((string)Config::get('app.url'), PHP_URL_PATH), '/');
            $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            self::$base = ($p !== '' && ($uri === $p || str_starts_with($uri, $p . '/'))) ? $p : '';
        }
        return self::$base;
    }

    /** Route path of the current request, e.g. "/login" ("/" for the home page). */
    public static function path(): string
    {
        $uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $rel = substr($uri, strlen(self::basePath()));
        return '/' . trim($rel, '/');
    }

    /** Absolute-path URL for a route: Url::to('/login') → "/ai-billing/login". */
    public static function to(string $path = '/', array $query = []): string
    {
        $url = self::basePath() . '/' . ltrim($path, '/');
        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    /** URL of a file in public/assets with a cache-busting version. */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) . '/assets/' . $path;
        $v = is_file($file) ? (string)filemtime($file) : Config::get('app.version');
        return self::to('assets/' . $path) . '?v=' . rawurlencode((string)$v);
    }

    /** Only allow same-site relative paths as redirect targets (no open redirects). */
    public static function safeRedirectPath(?string $path): ?string
    {
        if ($path === null || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }
        return preg_match('/^\/[A-Za-z0-9\/_\-.?=&%]*$/', $path) ? $path : null;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
