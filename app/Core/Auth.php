<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\AuditLog;
use App\Repositories\UserRepository;

/**
 * The logged-in user for this request.
 *
 * Session keys: uid, login_at, last_seen, regen_at, auth_ver.
 * On every request the session is re-validated:
 *  - idle timeout   (SESSION_IDLE_MINUTES since the last request)
 *  - absolute limit (SESSION_ABSOLUTE_HOURS since login, however active)
 *  - the user still exists and is active, and the role is re-read from storage
 *    (a demoted or disabled user loses access on their next click)
 *  - auth_ver: a fingerprint of password_changed_at, so a password change or
 *    admin reset signs out every other session of that user
 * The session id is rotated at login and every 15 minutes.
 */
final class Auth
{
    private const REGEN_EVERY = 900;

    private static ?array $user = null;
    private static bool $resolved = false;
    private static ?string $endReason = null;

    public static function login(array $user): void
    {
        Session::regenerate();
        $keep = array_intersect_key($_SESSION, ['intended' => true]);
        $_SESSION = $keep;
        $now = time();
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['login_at'] = $now;
        $_SESSION['last_seen'] = $now;
        $_SESSION['regen_at'] = $now;
        $_SESSION['auth_ver'] = self::version($user);
        Csrf::rotate();
        self::$user = $user;
        self::$resolved = true;
    }

    public static function logout(string $reason = 'logout'): void
    {
        $id = self::$user['id'] ?? ($_SESSION['uid'] ?? null);
        if ($id !== null && $reason === 'logout') {
            App::get(AuditLog::class)->record('auth.logout', (int)$id, 'user', (string)$id);
        }
        Session::reset();
        Csrf::rotate();
        self::$user = null;
        self::$resolved = true;
    }

    /** Current user (public view, no password hash) or null. */
    public static function user(): ?array
    {
        if (!self::$resolved) {
            self::$resolved = true;
            self::$user = self::resolve();
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int)self::user()['id'] : null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** @param string|list<string> $roles */
    public static function hasRole(string|array $roles): bool
    {
        return in_array(self::role(), (array)$roles, true);
    }

    /** Why the previous session ended this request ('idle', 'expired', 'revoked'), if it did. */
    public static function endReason(): ?string
    {
        self::user();
        return self::$endReason;
    }

    /** Seconds the session has left before the idle or absolute limit, whichever is first. */
    public static function secondsLeft(): int
    {
        $idle = (int)Config::get('session.idle_minutes', 60) * 60;
        $abs = (int)Config::get('session.absolute_hours', 12) * 3600;
        $now = time();
        return max(0, min(
            (int)($_SESSION['last_seen'] ?? $now) + $idle - $now,
            (int)($_SESSION['login_at'] ?? $now) + $abs - $now
        ));
    }

    /** After this user changes their own password: keep this session valid, others die. */
    public static function refreshVersion(): void
    {
        $id = self::id();
        if ($id === null) {
            return;
        }
        $u = App::get(UserRepository::class)->find($id);
        if ($u !== null) {
            Session::regenerate();
            $_SESSION['auth_ver'] = self::version($u);
            self::$user = $u;
        }
    }

    private static function resolve(): ?array
    {
        $uid = $_SESSION['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }
        $now = time();
        $idle = max(1, (int)Config::get('session.idle_minutes', 60)) * 60;
        $abs = max(1, (int)Config::get('session.absolute_hours', 12)) * 3600;

        $reason = match (true) {
            $now - (int)($_SESSION['last_seen'] ?? 0) > $idle => 'idle',
            $now - (int)($_SESSION['login_at'] ?? 0) > $abs  => 'expired',
            default                                          => null,
        };

        $user = $reason === null ? App::get(UserRepository::class)->find($uid) : null;
        if ($reason === null && ($user === null || ($user['status'] ?? '') !== 'active'
            || !hash_equals((string)($_SESSION['auth_ver'] ?? ''), self::version($user)))) {
            $reason = 'revoked';
        }

        if ($reason !== null) {
            App::get(AuditLog::class)->record('auth.session_ended', $uid, 'user', (string)$uid, ['reason' => $reason]);
            self::logout($reason);
            self::$endReason = $reason;
            return null;
        }

        $_SESSION['last_seen'] = $now;
        if ($now - (int)($_SESSION['regen_at'] ?? 0) > self::REGEN_EVERY) {
            Session::regenerate();
            $_SESSION['regen_at'] = $now;
        }
        return $user;
    }

    private static function version(array $user): string
    {
        return substr(hash('sha256', $user['id'] . '|' . ($user['password_changed_at'] ?? '')), 0, 32);
    }
}
