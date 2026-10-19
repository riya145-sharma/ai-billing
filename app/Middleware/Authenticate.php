<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Core\Url;

/** Signed-in users only. Others go to /login, which returns them here afterwards. */
final class Authenticate extends Middleware
{
    public function handle(\Closure $next): void
    {
        if (Auth::check()) {
            $next();
            return;
        }
        $msg = match (Auth::endReason()) {
            'idle'    => 'You were signed out after a period of inactivity. Please sign in again.',
            'expired' => 'Your session reached its maximum length. Please sign in again.',
            'revoked' => 'Your session is no longer valid. Please sign in again.',
            default   => null,
        };
        if ($msg !== null) {
            Session::flash('warning', $msg);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $path = Url::path();
            $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
            $intended = Url::safeRedirectPath($path . ($qs !== '' ? '?' . $qs : ''));
            if ($intended !== null && $intended !== '/') {
                Session::set('intended', $intended);
            }
        }
        Response::redirect('/login');
    }
}
