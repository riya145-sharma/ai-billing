<?php
declare(strict_types=1);

/**
 * Maintenance, run every 5 minutes by cron:
 *   php bin/cron.php
 *  - reconciles stale image reservations (lost responses, crashed requests)
 *  - sweeps orphaned temp files, stale lock files, old rate-limit buckets,
 *    expired session files and old logs
 * Overlapping runs are prevented with a lock.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Logger;
use App\Services\QuotaReconciler;
use App\Services\QuotaService;
use App\Storage\Installer;
use App\Storage\LockTimeoutException;

if (!Installer::isInstalled((string)Config::get('paths.data'))) {
    fwrite(STDERR, "Storage not initialised. Run php bin/install.php\n");
    exit(1);
}

$store = App::store();
$t0 = microtime(true);

try {
    $store->withLock('cron.json', true, function () use ($store, $t0) {
        // 1. Quota reconciliation
        $stats = (new QuotaService($store))->reconcile(new QuotaReconciler($store));
        if (array_sum($stats) > 0) {
            Logger::info('cron: quota reconciliation', $stats);
        }

        // 2. Sweeps
        $now = time();
        $swept = ['tmp' => 0, 'locks' => 0, 'ratelimit' => 0, 'sessions' => 0, 'logs' => 0];
        $sweep = static function (string $dir, callable $match, int $maxAgeSec, string $key) use (&$swept, $now): void {
            if (!is_dir($dir)) {
                return;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $match($f->getFilename()) && $now - $f->getMTime() > $maxAgeSec && @unlink($f->getPathname())) {
                    $swept[$key]++;
                }
            }
        };
        foreach (['data', 'images', 'reports'] as $d) {
            // Temp files left by a crash mid-write (a completed write renames them away instantly).
            $sweep(Config::get("paths.{$d}"), fn($n) => str_starts_with($n, '.~') && str_ends_with($n, '.tmp'), 3600, 'tmp');
        }
        // Lock files are touched on every exclusive use; untouched for 60 days = partition no longer written.
        $sweep(Config::get('paths.locks'), fn($n) => str_ends_with($n, '.lock') && $n !== 'cron.json.lock', 60 * 86400, 'locks');
        $sweep(Config::get('paths.data') . '/ratelimit', fn($n) => str_ends_with($n, '.json'), 2 * 86400, 'ratelimit');
        $sweep(Config::get('paths.sessions'), fn($n) => str_starts_with($n, 'sess_'), (int)Config::get('session.absolute_hours', 12) * 3600, 'sessions');
        $sweep(Config::get('paths.logs'), fn($n) => str_ends_with($n, '.log'), 90 * 86400, 'logs');

        $msg = sprintf("reconciled %s | swept %s | %.0f ms\n", json_encode($stats), json_encode($swept), (microtime(true) - $t0) * 1000);
        echo $msg;
    }, 1.0);
} catch (LockTimeoutException) {
    echo "Another cron run is in progress; skipping.\n";
}
