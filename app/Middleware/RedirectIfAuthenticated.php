<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Response;

/** Guest-only pages (login): signed-in users go straight to the dashboard. */
final class RedirectIfAuthenticated extends Middleware
{
    public function handle(\Closure $next): void
    {
        if (Auth::check()) {
            Response::redirect('/');
        }
        $next();
    }
}
