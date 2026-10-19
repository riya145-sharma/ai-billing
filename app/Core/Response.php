<?php
declare(strict_types=1);

namespace App\Core;

/** Output helpers: security headers, redirects, HTML and error pages. */
final class Response
{
    /** Sent on every app response. Strict CSP: no inline scripts or styles anywhere. */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
            . "font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // Pages show private data: never cache them (also stops "back" after logout).
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header_remove('X-Powered-By');
    }

    public static function html(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
    }

    public static function view(string $view, array $data = [], ?string $layout = 'layouts/app', int $status = 200): void
    {
        self::html(View::render($view, $data, $layout), $status);
    }

    /** Redirect to a route path (303 so a POST becomes a GET). */
    public static function redirect(string $path, int $status = 303): never
    {
        header('Location: ' . Url::to($path), true, $status);
        exit;
    }

    public static function back(string $fallback = '/'): never
    {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $refPath = (string)parse_url($ref, PHP_URL_PATH);
        $base = Url::basePath();
        $sameHost = strtolower((string)parse_url($ref, PHP_URL_HOST)) === strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($sameHost && ($base === '' || str_starts_with($refPath, $base))) {
            $rel = Url::safeRedirectPath('/' . ltrim(substr($refPath, strlen($base)), '/'));
            if ($rel !== null) {
                self::redirect($rel);
            }
        }
        self::redirect($fallback);
    }

    public static function error(int $status, string $message = ''): void
    {
        $titles = [
            403 => ['Access denied', 'You don’t have permission to open this page.'],
            404 => ['Page not found', 'The page you asked for doesn’t exist or has moved.'],
            405 => ['Method not allowed', 'This page can’t be used that way.'],
            419 => ['Page expired', 'Your form was open too long or your session changed. Go back, reload the page and try again.'],
            429 => ['Too many requests', 'Please wait a moment and try again.'],
        ];
        [$title, $text] = $titles[$status] ?? ['Error', 'Something went wrong.'];
        $layout = Auth::check() ? 'layouts/app' : 'layouts/guest';
        self::view('errors/error', [
            'title'   => $title,
            'status'  => $status,
            'message' => $message !== '' ? $message : $text,
        ], $layout, $status);
    }
}
