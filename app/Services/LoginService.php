<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Settings;
use App\Repositories\AuditLog;
use App\Repositories\UserRepository;
use App\Storage\FileStore;

/**
 * Credential check with brute-force protection. No session code here, so it
 * is fully testable; App\Core\Auth turns a success into a logged-in session.
 *
 * Protection layers
 *  - per account: security.login_max_attempts failures → locked for
 *    security.login_lock_minutes (stored on the user: failed_login_count, locked_until)
 *  - per email (incl. emails that don't exist): same limit via RateLimiter, so
 *    the response for an unknown email is indistinguishable from a real one
 *  - per IP: a wider cap to slow password spraying across many accounts
 *  - unknown emails still run password_verify() so timing doesn't reveal them
 */
final class LoginService
{
    public const GENERIC_ERROR = 'Incorrect email or password.';

    private UserRepository $users;
    private RateLimiter $limiter;
    private AuditLog $audit;

    public function __construct(FileStore $fs)
    {
        $this->users = new UserRepository($fs);
        $this->limiter = new RateLimiter($fs);
        $this->audit = new AuditLog($fs);
    }

    /** @return array{ok: bool, user?: array, error?: string} */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $max = max(1, Settings::int('security.login_max_attempts', 5));
        $lockSec = max(1, Settings::int('security.login_lock_minutes', 15)) * 60;

        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => 'Enter your email address and password.'];
        }
        if (strlen($email) > 190 || strlen($password) > 4096) {
            return ['ok' => false, 'error' => self::GENERIC_ERROR];
        }

        $ipHit = $this->limiter->attempt('login:ip:' . $ip, max(20, $max * 4), $lockSec);
        if (!$ipHit['allowed']) {
            $this->audit->record('auth.login_throttled', null, 'ip', null, ['email' => self::mask($email)]);
            return ['ok' => false, 'error' => self::lockedMessage($ipHit['retry_after'])];
        }

        $user = $this->users->findByEmailWithSecret($email);

        if ($user !== null && self::lockedFor($user) > 0) {
            $this->audit->record('auth.login_locked', (int)$user['id'], 'user', (string)$user['id']);
            return ['ok' => false, 'error' => self::lockedMessage(self::lockedFor($user))];
        }

        $valid = $user !== null
            ? $this->users->verifyPassword($user, $password)
            : password_verify($password, self::dummyHash()) && false;

        if (!$valid) {
            return $this->fail($email, $user, $max, $lockSec);
        }

        if (($user['status'] ?? '') !== 'active') {
            $this->audit->record('auth.login_disabled', (int)$user['id'], 'user', (string)$user['id']);
            return ['ok' => false, 'error' => 'This account is disabled. Please contact an administrator.'];
        }

        $this->limiter->reset('login:email:' . $email);
        $fresh = $this->users->update((int)$user['id'], [
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => Clock::nowIso(),
            'last_login_ip'      => mb_substr($ip, 0, 45),
        ]);
        $this->audit->record('auth.login_success', (int)$user['id'], 'user', (string)$user['id']);
        return ['ok' => true, 'user' => $fresh];
    }

    private function fail(string $email, ?array $user, int $max, int $lockSec): array
    {
        $hit = $this->limiter->attempt('login:email:' . $email, $max, $lockSec);
        $locked = !$hit['allowed'] || $hit['remaining'] === 0;

        if ($user !== null) {
            $id = (int)$user['id'];
            $lockedNow = false;
            // Atomic increment under the users.json lock, so parallel guesses can't undercount.
            $this->users->recordFailedLogin($id, $max, $lockSec, $lockedNow);
            $locked = $locked || $lockedNow;
            $this->audit->record($locked ? 'auth.account_locked' : 'auth.login_failed', $id, 'user', (string)$id);
        } else {
            $this->audit->record('auth.login_failed', null, 'user', null, ['email' => self::mask($email)]);
        }

        return ['ok' => false, 'error' => $locked ? self::lockedMessage($lockSec) : self::GENERIC_ERROR];
    }

    /** Seconds until the account unlocks (0 = not locked). */
    public static function lockedFor(array $user): int
    {
        if (empty($user['locked_until'])) {
            return 0;
        }
        try {
            return max(0, Clock::parseIso($user['locked_until'])->getTimestamp() - Clock::now()->getTimestamp());
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function lockedMessage(int $seconds): string
    {
        $min = max(1, (int)ceil($seconds / 60));
        return "Too many failed sign-in attempts. Try again in {$min} minute" . ($min === 1 ? '' : 's') . '.';
    }

    /** "ri***@upcpl.com" — enough to spot patterns in the audit log without storing full addresses typed by strangers. */
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 2) . '***' . ($domain !== '' ? '@' . mb_substr($domain, 0, 60) : '');
    }

    /** A real hash of a random string, computed once per process, so unknown emails cost the same as real ones. */
    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash(bin2hex(random_bytes(16)), defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }
}
