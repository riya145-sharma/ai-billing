<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Storage\Collection;
use App\Storage\FileStore;

/**
 * Users in storage/data/users.json. Emails are unique (case-insensitive).
 * Passwords are only ever stored as password_hash() output.
 */
final class UserRepository
{
    public const FILE = 'users.json';
    public const ROLES = ['admin', 'user'];
    public const MIN_PASSWORD = 12;

    private Collection $c;

    public function __construct(private FileStore $fs)
    {
        $this->c = new Collection($fs, self::FILE, [
            'email' => fn(array $u) => isset($u['email']) ? strtolower($u['email']) : null,
        ]);
    }

    public function create(string $name, string $email, string $password, string $role = 'user'): array
    {
        $email = self::normalizeEmail($email);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Name is required (max 100 characters).');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown role: {$role}");
        }
        return self::publicView($this->c->insert([
            'role'                => $role,
            'name'                => $name,
            'email'               => $email,
            'password_hash'       => self::hash($password),
            'status'              => 'active',
            'daily_image_limit'   => null,   // null = global setting, 0 = no image access
            'failed_login_count'  => 0,
            'locked_until'        => null,
            'last_login_at'       => null,
            'last_login_ip'       => null,
            'password_changed_at' => Clock::nowIso(),
        ]));
    }

    /** Full record including password_hash — only for authentication code. */
    public function findWithSecret(int $id): ?array
    {
        return $this->c->find($id);
    }

    public function find(int $id): ?array
    {
        $u = $this->c->find($id);
        return $u ? self::publicView($u) : null;
    }

    /** Full record including password_hash — only for authentication code. */
    public function findByEmailWithSecret(string $email): ?array
    {
        $email = strtolower(trim($email));
        return $this->c->first(fn(array $u) => strtolower($u['email']) === $email);
    }

    /** @return array<int, array> */
    public function all(): array
    {
        return array_map([self::class, 'publicView'], $this->c->all());
    }

    public function countActiveAdmins(): int
    {
        return count($this->c->where(fn(array $u) => $u['role'] === 'admin' && $u['status'] === 'active'));
    }

    /**
     * Count one failed login atomically (under the users.json lock). After $max
     * failures the account is locked for $lockSec and the counter restarts.
     */
    public function recordFailedLogin(int $id, int $max, int $lockSec, ?bool &$lockedNow = null): array
    {
        $locked = false;
        $u = $this->c->update($id, function (array $u) use ($max, $lockSec, &$locked) {
            $count = (int)($u['failed_login_count'] ?? 0) + 1;
            if ($count >= $max) {
                $locked = true;
                return array_merge($u, ['failed_login_count' => 0, 'locked_until' => Clock::toIso(Clock::now()->modify("+{$lockSec} seconds"))]);
            }
            return array_merge($u, ['failed_login_count' => $count]);
        });
        $lockedNow = $locked;
        return self::publicView($u);
    }

    public function setPassword(int $id, string $password): void
    {
        $hash = self::hash($password);
        $this->c->update($id, [
            'password_hash' => $hash,
            'password_changed_at' => Clock::nowIso(),
            'failed_login_count' => 0,
            'locked_until' => null,
        ]);
    }

    public function update(int $id, array $fields): array
    {
        $allowed = ['name', 'role', 'status', 'daily_image_limit', 'failed_login_count', 'locked_until', 'last_login_at', 'last_login_ip'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if (isset($fields['role']) && !in_array($fields['role'], self::ROLES, true)) {
            throw new \InvalidArgumentException('Unknown role');
        }
        if (isset($fields['status']) && !in_array($fields['status'], ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException('Status must be active or disabled');
        }
        if (array_key_exists('daily_image_limit', $fields) && $fields['daily_image_limit'] !== null) {
            $l = filter_var($fields['daily_image_limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 24]]);
            if ($l === false) {
                throw new \InvalidArgumentException('Daily image limit must be 0–24 or empty');
            }
            $fields['daily_image_limit'] = $l;
        }
        return self::publicView($this->c->update($id, $fields));
    }

    /**
     * Verify a password; transparently upgrades the hash if PHP's preferred
     * algorithm/cost changed. Lockout/rate-limit policy lives in the auth layer.
     */
    public function verifyPassword(array $userWithSecret, string $password): bool
    {
        $hash = (string)($userWithSecret['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, self::algo())) {
            $this->c->update((int)$userWithSecret['id'], ['password_hash' => self::hash($password)]);
        }
        return true;
    }

    public static function publicView(array $u): array
    {
        unset($u['password_hash']);
        return $u;
    }

    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }
        return $email;
    }

    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function hash(string $password): string
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        if (strlen($password) > 4096) {
            throw new \InvalidArgumentException('Password is too long.');
        }
        return password_hash($password, self::algo());
    }
}
