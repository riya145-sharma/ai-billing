<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection. One random token per session, sent as
 * the hidden "_csrf" form field or an X-CSRF-Token header (fetch/XHR).
 * The router checks it on every POST automatically, plus an Origin check.
 */
final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        if (!is_string($_SESSION['_csrf'] ?? null) || strlen($_SESSION['_csrf']) !== 64) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** New token (on login/logout, so a pre-login token can't be reused). */
    public static function rotate(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . self::token() . '">';
    }

    public static function verifyRequest(): bool
    {
        $sent = $_POST[self::FIELD] ?? $_SERVER[self::HEADER] ?? null;
        $real = $_SESSION['_csrf'] ?? null;
        return is_string($sent) && is_string($real) && $real !== '' && hash_equals($real, $sent);
    }

    /**
     * Defence in depth: when the browser sends Origin (all modern browsers do on
     * POST), it must be this site.
     */
    public static function originAllowed(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null || $origin === '') {
            return true;
        }
        if ($origin === 'null') {
            return false;
        }
        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $port = parse_url($origin, PHP_URL_PORT);
        $self = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $selfPort = preg_match('/:(\d+)$/', (string)($_SERVER['HTTP_HOST'] ?? ''), $m) ? (int)$m[1] : null;
        return $host !== '' && $host === $self && ($port === null || $port === $selfPort);
    }
}
