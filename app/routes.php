<?php
declare(strict_types=1);

/**
 * Web routes. Loaded by public/index.php after bootstrap.
 *
 * Middleware: guest · auth · role:<roles> · csrf (added to every POST automatically)
 */

use App\Controllers\Admin\AzureController;
use App\Controllers\Admin\UsersController;
use App\Controllers\AuthController;
use App\Core\Config;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Url;
use App\Middleware\Authenticate;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\RequireRole;
use App\Middleware\VerifyCsrf;
use App\Core\Auth;
use App\Storage\Installer;

require __DIR__ . '/helpers.php';

Response::securityHeaders();

if (!Installer::isInstalled((string)Config::get('paths.data'))) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Storage is not initialised. Run: php bin/install.php\n");
}

Session::start();
Session::ageInput();

$r = new Router();
$r->alias('auth', Authenticate::class);
$r->alias('guest', RedirectIfAuthenticated::class);
$r->alias('role', RequireRole::class);
$r->alias('csrf', VerifyCsrf::class);

// Authentication
$r->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$r->post('/login', [AuthController::class, 'login'], ['guest']);
$r->post('/logout', [AuthController::class, 'logout'], ['auth']);

// Everyone signed in
$r->get('/', [\App\Controllers\DashboardController::class, 'index'], ['auth']);

// "Stay signed in" button on the session-timeout warning. Only a deliberate
// click calls this; the countdown itself never extends the session.
$r->post('/session/extend', function (): void {
    header('Content-Type: application/json');
    echo json_encode(['seconds_left' => Auth::secondsLeft()]);
}, ['auth']);

// My profile (own name and password)
$r->get('/profile', [\App\Controllers\ProfileController::class, 'index'], ['auth']);
$r->post('/profile', [\App\Controllers\ProfileController::class, 'updateDetails'], ['auth']);
$r->post('/profile/password', [\App\Controllers\ProfileController::class, 'changePassword'], ['auth']);

// Content generator
$r->get('/content', [\App\Controllers\ContentController::class, 'index'], ['auth']);
$r->post('/content/generate', [\App\Controllers\ContentController::class, 'generate'], ['auth']);
$r->get('/speech', [\App\Controllers\SpeechController::class, 'index'], ['auth']);
$r->post('/speech/generate', [\App\Controllers\SpeechController::class, 'generate'], ['auth']);
$r->get('/speech/audio/{id}', [\App\Controllers\SpeechController::class, 'audio'], ['auth']);

// Daily motivation automation (results are private to their owner; settings are admin-only)
$r->get('/automation', [\App\Controllers\AutomationController::class, 'index'], ['auth']);
$r->get('/automation/audio/{id}', [\App\Controllers\AutomationController::class, 'audio'], ['auth']);


// Admin
$admin = ['auth', 'role:admin'];
$r->get('/admin/azure', [AzureController::class, 'index'], $admin);
$r->post('/admin/azure', [AzureController::class, 'store'], $admin);
$r->post('/admin/azure/discover', [AzureController::class, 'discover'], $admin);
$r->post('/admin/azure/{id}', [AzureController::class, 'update'], $admin);
$r->post('/admin/azure/{id}/default', [AzureController::class, 'makeDefault'], $admin);
$r->post('/admin/azure/{id}/toggle', [AzureController::class, 'toggle'], $admin);
$r->post('/admin/azure/{id}/verify', [AzureController::class, 'verify'], $admin);
$r->post('/admin/automation/settings', [\App\Controllers\Admin\AutomationController::class, 'settings'], $admin);
$r->post('/admin/automation/users', [\App\Controllers\Admin\AutomationController::class, 'users'], $admin);
$r->post('/admin/automation/jobs/{id}/retry', [\App\Controllers\Admin\AutomationController::class, 'retry'], $admin);
$r->get('/admin/users', [UsersController::class, 'index'], $admin);
$r->post('/admin/users', [UsersController::class, 'store'], $admin);
$r->post('/admin/users/{id}', [UsersController::class, 'update'], $admin);
$r->post('/admin/users/{id}/password', [UsersController::class, 'resetPassword'], $admin);
$r->post('/admin/users/{id}/unlock', [UsersController::class, 'unlock'], $admin);

$r->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', Url::path());
