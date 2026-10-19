<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Repositories\AuditLog;

/** Applied to every POST route by the router. */
final class VerifyCsrf extends Middleware
{
    public function handle(\Closure $next): void
    {
        if (!Csrf::originAllowed() || !Csrf::verifyRequest()) {
            App::get(AuditLog::class)->record('security.csrf_rejected', Auth::id(), 'route', null, [
                'path'   => mb_substr((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), 0, 200),
                'origin' => mb_substr((string)($_SERVER['HTTP_ORIGIN'] ?? ''), 0, 200),
            ]);
            throw new HttpException(419);
        }
        $next();
    }
}
