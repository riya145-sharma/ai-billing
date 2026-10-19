<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Core\Url;
use App\Services\LoginService;

final class AuthController
{
    public function showLogin(): void
    {
        Response::view('auth/login', ['title' => 'Sign in'], 'layouts/guest');
    }

    public function login(): void
    {
        $email = (string)($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        $result = App::get(LoginService::class)->attempt($email, $password, $ip);
        if (!$result['ok']) {
            Session::flash('danger', $result['error']);
            Session::flashInput(['email' => mb_substr(trim($email), 0, 190)]);
            Response::redirect('/login');
        }

        $intended = Url::safeRedirectPath(Session::get('intended'));
        Session::forget('intended');
        Auth::login($result['user']);
        Response::redirect($intended ?? '/');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::flash('success', 'You have been signed out.');
        Response::redirect('/login');
    }
}
