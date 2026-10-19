<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Response;
use App\Repositories\AuditLog;
use App\Repositories\UserRepository;
use App\Services\LoginService;
use App\Services\RateLimiter;
use App\Storage\DuplicateException;

final class UsersController
{
    use AdminHelpers;

    public function index(): void
    {
        $users = array_values(App::get(UserRepository::class)->all());
        usort($users, fn($a, $b) => [$a['status'] !== 'active', $a['role'] !== 'admin', strtolower($a['name'])]
            <=> [$b['status'] !== 'active', $b['role'] !== 'admin', strtolower($b['name'])]);
        $editId = (int)($_GET['edit'] ?? 0);
        Response::view('admin/users', [
            'title'        => 'Users',
            'users'        => $users,
            'editing'      => $editId ? App::get(UserRepository::class)->find($editId) : null,
            'minPassword'  => UserRepository::MIN_PASSWORD,
        ]);
    }

    public function store(): void
    {
        $password = (string)($_POST['password'] ?? '');
        $generated = false;
        if ($password === '') {
            $password = self::randomPassword();
            $generated = true;
        }
        try {
            $u = App::get(UserRepository::class)->create(self::post('name'), self::post('email'), $password, self::post('role') === 'admin' ? 'admin' : 'user');
        } catch (DuplicateException) {
            self::failBack('A user with that email already exists.', '/admin/users', ['name', 'email', 'role']);
        } catch (\InvalidArgumentException $e) {
            self::failBack($e->getMessage(), '/admin/users', ['name', 'email', 'role']);
        }
        App::get(AuditLog::class)->record('admin.user_created', Auth::id(), 'user', (string)$u['id'], ['role' => $u['role']]);
        self::ok($generated
            ? "User {$u['email']} created. Temporary password: {$password} — share it securely; it is shown only once."
            : "User {$u['email']} created.", '/admin/users');
    }

    public function update(string $id): void
    {
        $id = self::intId($id);
        $repo = App::get(UserRepository::class);
        $user = $repo->find($id) ?? throw new HttpException(404);
        $role = self::post('role') === 'admin' ? 'admin' : 'user';
        $status = self::post('status') === 'disabled' ? 'disabled' : 'active';

        if ($id === Auth::id() && ($role !== 'admin' || $status !== 'active')) {
            self::failBack('You can’t remove your own admin access or disable your own account.', "/admin/users?edit={$id}");
        }
        $losesAdmin = $user['role'] === 'admin' && $user['status'] === 'active' && ($role !== 'admin' || $status !== 'active');
        if ($losesAdmin && $repo->countActiveAdmins() <= 1) {
            self::failBack('At least one active administrator is required.', "/admin/users?edit={$id}");
        }
        try {
            $repo->update($id, [
                'name'              => self::post('name'),
                'role'              => $role,
                'status'            => $status,
            ]);
        } catch (\InvalidArgumentException $e) {
            self::failBack($e->getMessage(), "/admin/users?edit={$id}");
        }
        App::get(AuditLog::class)->record('admin.user_updated', Auth::id(), 'user', (string)$id, [
            'role' => $role, 'status' => $status,
        ]);
        self::ok("Saved {$user['email']}." . ($status === 'disabled' && $user['status'] === 'active' ? ' They have been signed out.' : ''), '/admin/users');
    }

    public function resetPassword(string $id): void
    {
        $id = self::intId($id);
        $repo = App::get(UserRepository::class);
        $user = $repo->find($id) ?? throw new HttpException(404);
        $password = (string)($_POST['password'] ?? '');
        $generated = $password === '';
        if ($generated) {
            $password = self::randomPassword();
        }
        try {
            $repo->setPassword($id, $password);   // also unlocks and signs out every session of that user
        } catch (\InvalidArgumentException $e) {
            self::failBack($e->getMessage(), "/admin/users?edit={$id}");
        }
        (new RateLimiter(App::store()))->reset('login:email:' . strtolower($user['email']));
        App::get(AuditLog::class)->record('admin.password_reset', Auth::id(), 'user', (string)$id);
        if ($id === Auth::id()) {
            Auth::refreshVersion();
        }
        self::ok($generated
            ? "Password for {$user['email']} reset. Temporary password: {$password} — share it securely; it is shown only once."
            : "Password for {$user['email']} reset. They have been signed out everywhere.", '/admin/users');
    }

    public function unlock(string $id): void
    {
        $id = self::intId($id);
        $repo = App::get(UserRepository::class);
        $user = $repo->find($id) ?? throw new HttpException(404);
        $repo->update($id, ['failed_login_count' => 0, 'locked_until' => null]);
        (new RateLimiter(App::store()))->reset('login:email:' . strtolower($user['email']));
        App::get(AuditLog::class)->record('admin.user_unlocked', Auth::id(), 'user', (string)$id);
        self::ok("{$user['email']} can sign in again.", '/admin/users');
    }

    public static function isLocked(array $user): bool
    {
        return LoginService::lockedFor($user) > 0;
    }

    /** 16 characters from an unambiguous alphabet, grouped for reading aloud. */
    private static function randomPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 16; $i++) {
            $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return implode('-', str_split($s, 4));
    }
}
