<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\AuditLog;
use App\Repositories\UserRepository;
use App\Services\RateLimiter;

/**
 * Self-service account page: every signed-in user can change their own display
 * name and password. Email, role and status stay admin-only.
 */
final class ProfileController
{
    /** Wrong current-password attempts allowed per user per window. */
    private const PW_ATTEMPTS = 5;
    private const PW_WINDOW = 900;

    public function index(): void
    {
        Response::view('profile/index', [
            'title'       => 'My profile',
            'profile'     => App::get(UserRepository::class)->find((int)Auth::id()),
            'minPassword' => UserRepository::MIN_PASSWORD,
        ]);
    }

    public function updateDetails(): void
    {
        $uid = (int)Auth::id();
        $name = self::post('name');
        if ($name === (string)Auth::user()['name']) {
            self::back('info', 'No changes to save.', '#details');
        }
        try {
            App::get(UserRepository::class)->update($uid, ['name' => $name]);
        } catch (\InvalidArgumentException $e) {
            Session::flashInput(['name' => $name]);
            self::back('danger', $e->getMessage(), '#details');
        }
        App::get(AuditLog::class)->record('profile.name_changed', $uid, 'user', (string)$uid);
        self::back('success', 'Your name has been updated.', '#details');
    }

    public function changePassword(): void
    {
        $uid = (int)Auth::id();
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $repo = App::get(UserRepository::class);
        $limiter = new RateLimiter(App::store());
        $bucket = 'profile:password:' . $uid;

        $attempt = $limiter->attempt($bucket, self::PW_ATTEMPTS, self::PW_WINDOW);
        if (!$attempt['allowed']) {
            self::back('danger', 'Too many attempts. Try again in ' . max(1, (int)ceil($attempt['retry_after'] / 60)) . ' min.', '#password');
        }
        $user = $repo->findWithSecret($uid);
        if ($user === null || $current === '' || !$repo->verifyPassword($user, $current)) {
            App::get(AuditLog::class)->record('profile.password_change_failed', $uid, 'user', (string)$uid, ['reason' => 'wrong_current']);
            self::back('danger', 'Your current password is incorrect.', '#password');
        }
        if ($new !== $confirm) {
            self::back('danger', 'The new passwords do not match.', '#password');
        }
        if (hash_equals($current, $new)) {
            self::back('danger', 'Choose a new password that is different from your current one.', '#password');
        }
        try {
            $repo->setPassword($uid, $new);
        } catch (\InvalidArgumentException $e) {
            self::back('danger', $e->getMessage(), '#password');
        }
        $limiter->reset($bucket);
        Auth::refreshVersion();   // keep this session; every other session of this user is signed out
        App::get(AuditLog::class)->record('profile.password_changed', $uid, 'user', (string)$uid);
        self::back('success', 'Password changed. You have been signed out on all other devices.', '#password');
    }

    private static function back(string $type, string $message, string $anchor): never
    {
        Session::flash($type, $message);
        Response::redirect('/profile' . $anchor);
    }

    private static function post(string $key): string
    {
        return is_scalar($_POST[$key] ?? null) ? trim((string)$_POST[$key]) : '';
    }
}
