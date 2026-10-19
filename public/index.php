<?php
declare(strict_types=1);

/**
 * Front controller. Only this folder is web-reachable.
 *
 * Locating the private project folder:
 *  - Local / default: public/ sits inside the project → project = parent folder.
 *  - Hostinger: bin/publish-public.php copies this folder into public_html and
 *    writes public_html/app-root.php returning a path relative to __DIR__.
 */
// Local dev server (php -S … public/index.php): let it serve real asset files directly.
if (PHP_SAPI === 'cli-server') {
    $asset = realpath(__DIR__ . (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if ($asset !== false && is_file($asset) && str_starts_with($asset, __DIR__ . DIRECTORY_SEPARATOR . 'assets')) {
        return false;
    }
}

$appRoot = is_file(__DIR__ . '/app-root.php') ? (require __DIR__ . '/app-root.php') : dirname(__DIR__);
$appRoot = realpath((string)$appRoot);
if ($appRoot === false || !is_file($appRoot . '/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Application folder not found. Re-run: php bin/publish-public.php <document-root>\n");
}
define('APP_ROOT', str_replace('\\', '/', $appRoot));

require APP_ROOT . '/bootstrap.php';

use App\Core\App;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Url;
use App\Storage\Installer;

// Route path with any sub-folder from APP_URL removed (e.g. XAMPP /ai-billing/health → /health).
$path = Url::path();

// Health check: storage present, readable and writable under a lock. No internals exposed.
if ($path === '/health') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!Installer::isInstalled((string)Config::get('paths.data'))) {
        http_response_code(503);
        echo json_encode(['status' => 'not_installed']);
        exit;
    }
    try {
        App::store()->update('health.json', function (array &$d) {
            $d['checked_at'] = Clock::nowIso();
        });
        App::store()->read('settings.json');
        echo json_encode(['status' => 'ok', 'storage' => 'ok', 'time' => Clock::now()->format(DATE_ATOM)]);
    } catch (\Throwable $e) {
        Logger::error('health check failed: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['status' => 'storage_error']);
    }
    exit;
}

// Login, content generation and administration.
$routes = APP_ROOT . '/app/routes.php';
if (is_file($routes)) {
    require $routes;
    exit;
}

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Application routes are unavailable. Check the deployment files.\n";
