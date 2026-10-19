<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Repositories\AuditLog;

/**
 * "role:admin" or "role:admin,user". Always list it after "auth".
 * The role is the one re-read from storage this request, never a cached copy.
 */
final class RequireRole extends Middleware
{
    public function handle(\Closure $next): void
    {
        $roles = array_filter(array_map('trim', explode(',', (string)$this->arg)));
        if (!$roles) {
            throw new \LogicException('role middleware needs a role, e.g. role:admin');
        }
        if (!Auth::hasRole($roles)) {
            App::get(AuditLog::class)->record('auth.forbidden', Auth::id(), 'route', null, [
                'path' => mb_substr((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), 0, 200),
            ]);
            throw new HttpException(403);
        }
        $next();
    }
}
