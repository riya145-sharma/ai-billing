<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened native PHP sessions stored in storage/sessions (not the shared /tmp).
 *  - strict mode: the server never adopts a session id it did not issue
 *  - cookie: HttpOnly, SameSite=Lax, Secure when configured, scoped to the app path
 *  - idle / absolute timeouts are enforced by Auth, not by the cookie lifetime
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (bool)Config::get('session.secure_cookie');
        if ($secure && !Url::isHttps()) {
            throw new \RuntimeException(
                'SESSION_SECURE_COOKIE=true needs HTTPS, but this request is plain HTTP, so the login cookie would be dropped. '
                . 'Use https://, or set SESSION_SECURE_COOKIE=false in .env for local http://localhost testing.'
            );
        }

        $dir = (string)Config::get('session.save_path');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', 'nocache');
        ini_set('session.gc_maxlifetime', (string)(max(1, (int)Config::get('session.absolute_hours', 12)) * 3600));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        if (PHP_VERSION_ID < 80400) {
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        session_save_path($dir);
        session_name((string)Config::get('session.name', 'aibill_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Url::basePath() ?: '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** New session id, old one deleted — call on login and periodically. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Wipe data and cookie, then start a fresh anonymous session (for flash + CSRF). */
    public static function reset(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    // ---------------------------------------------------------------- flash messages

    /** @param 'success'|'danger'|'warning'|'info' $type */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** Old form input, kept for one request after a failed POST (never passwords). */
    public static function flashInput(array $input): void
    {
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, string $default = ''): string
    {
        return (string)($_SESSION['_old_current'][$key] ?? $default);
    }

    /** Move flashed input into "current" for this request's views. */
    public static function ageInput(): void
    {
        $_SESSION['_old_current'] = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }
}
