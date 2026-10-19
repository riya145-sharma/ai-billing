<?php
declare(strict_types=1);

/**
 * Shared bootstrap for web (public/index.php) and CLI (bin/*.php).
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('PHP 8.2 or newer is required. Change it in hPanel → Advanced → PHP Configuration.');
}

// Composer autoload if present (Phase 6 adds a PDF library); the PSR-4 fallback below
// means the app also runs without running Composer at all.
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Core\Clock;
use App\Core\Config;
use App\Core\Env;
use App\Core\Logger;

Env::load(APP_ROOT . '/.env');
Config::load(APP_ROOT . '/config/app.php');

// PHP default tz = UTC so date() calls never silently use server-local time.
date_default_timezone_set('UTC');
Clock::setBusinessTimezone((string)Config::get('app.timezone'));

$debug = (bool)Config::get('app.debug');
error_reporting(E_ALL);
ini_set('display_errors', $debug && PHP_SAPI !== 'cli' ? '1' : (PHP_SAPI === 'cli' ? '1' : '0'));
ini_set('log_errors', '1');
if (is_dir((string)Config::get('paths.logs'))) {
    ini_set('error_log', Config::get('paths.logs') . '/php-error.log');
}

// Collect every secret value so the logger can redact it anywhere it appears.
Logger::init((string)Config::get('paths.logs'), Env::secretValues());
unset($debug);

set_exception_handler(static function (\Throwable $e): void {
    Logger::error('Uncaught ' . $e::class . ': ' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, Logger::redact($e->getMessage()) . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo Config::get('app.debug')
        ? '<pre>' . htmlspecialchars(Logger::redact((string)$e), ENT_QUOTES) . '</pre>'
        : 'Something went wrong. The error has been logged.';
});
