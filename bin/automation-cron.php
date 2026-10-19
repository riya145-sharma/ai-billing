<?php
declare(strict_types=1);

/**
 * Daily motivation automation. Schedule every 5 minutes (Windows Task Scheduler or Linux cron):
 *   php bin/automation-cron.php              process due jobs (default time budget 240 s)
 *   php bin/automation-cron.php --budget=120 shorter run
 *   php bin/automation-cron.php --status     show the last heartbeat and today's jobs (no Azure calls)
 *
 * Schedule times are IST (Asia/Kolkata) regardless of the server clock's timezone.
 * Overlapping runs are skipped with a lock; job ids are deterministic, so repeated
 * or restarted runs never duplicate jobs or Azure calls.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Repositories\AutomationJobRepository;
use App\Services\AutomationRunner;
use App\Storage\Installer;

if (!Installer::isInstalled((string)Config::get('paths.data'))) {
    fwrite(STDERR, "Storage not initialised. Run php bin/install.php\n");
    exit(1);
}

$opts = getopt('', ['budget::', 'status']);
$store = App::store();
$runner = new AutomationRunner($store);

if (isset($opts['status'])) {
    $hb = $runner->heartbeat();
    echo 'Timezone:       ' . Clock::tz()->getName() . ' (now ' . Clock::now()->format('Y-m-d H:i') . ")\n";
    echo 'Last run start: ' . ($hb['last_started_at'] ?? 'never') . "\n";
    echo 'Last run end:   ' . ($hb['last_finished_at'] ?? 'never') . ' (' . ($hb['last_status'] ?? '—') . ")\n";
    if (!empty($hb['last_error'])) {
        echo 'Last error:     ' . $hb['last_error'] . "\n";
    }
    foreach ((new AutomationJobRepository($store))->forDay(Clock::businessDate()) as $job) {
        printf("  %s  user %-4d %-13s %s\n", $job['time'], $job['user_id'], $job['status'], $job['message'] ?? '');
    }
    exit(0);
}

$budget = isset($opts['budget']) && ctype_digit((string)$opts['budget']) ? (int)$opts['budget'] : 240;
$t0 = microtime(true);
try {
    $stats = $runner->run($budget);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . Clock::now()->format('Y-m-d H:i:s') . ' IST] automation run failed: ' . Logger::redact($e->getMessage()) . PHP_EOL);
    exit(1);
}
$stamp = '[' . Clock::now()->format('Y-m-d H:i:s') . ' IST] ';
if (!empty($stats['skipped'])) {
    echo $stamp . "Another automation run is in progress; skipping.\n";
    exit(0);
}
$interesting = array_filter($stats);
if ($interesting) {
    Logger::info('automation: run finished', $interesting);
}
printf("%sautomation %s | %.0f ms\n", $stamp, json_encode($stats), (microtime(true) - $t0) * 1000);
