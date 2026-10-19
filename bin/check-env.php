<?php
declare(strict_types=1);

/**
 * Pre-flight check:  php bin/check-env.php
 * Never prints secret values — only whether they are set.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Env;
use App\Core\Settings;
use App\Repositories\AzureConfigRepository;
use App\Repositories\PricingRepository;
use App\Repositories\SlotDefinitionRepository;
use App\Repositories\UserRepository;
use App\Storage\Installer;

$fail = 0;
$warn = 0;
$ok  = static function (string $m): void { echo "  [ OK ] {$m}\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "  [FAIL] {$m}\n"; };
$meh = static function (string $m) use (&$warn): void { $warn++; echo "  [WARN] {$m}\n"; };

echo "PHP & extensions\n";
PHP_VERSION_ID >= 80200 ? $ok('PHP ' . PHP_VERSION . ' on ' . PHP_OS_FAMILY) : $bad('PHP ' . PHP_VERSION . ' (need 8.2+)');
foreach (['json', 'curl', 'mbstring', 'fileinfo', 'openssl'] as $ext) {
    extension_loaded($ext) ? $ok("ext-{$ext}") : $bad("ext-{$ext} missing");
}
extension_loaded('gd') ? $ok('ext-gd') : $meh('ext-gd missing (optional; used for thumbnails later)');
extension_loaded('bcmath') ? $ok('ext-bcmath (exact money maths)') : $meh('ext-bcmath missing — money maths falls back to rounded floats (fine, but bcmath is better)');
function_exists('flock') ? $ok('flock() available') : $bad('flock() unavailable');

echo "\nStorage\n";
$data = (string)Config::get('paths.data');
if (!Installer::isInstalled($data)) {
    $bad('Storage not initialised — run php bin/install.php');
} else {
    $ok('Storage initialised');
}
foreach (Installer::DIRS as $d) {
    $dir = Config::get('paths.storage') . '/' . $d;
    is_dir($dir) && is_writable($dir) ? $ok("storage/{$d} writable") : $bad("storage/{$d} missing or not writable");
}
is_file(Config::get('paths.storage') . '/.htaccess') ? $ok('storage/.htaccess deny-all present') : $bad('storage/.htaccess missing — run php bin/install.php');

// Every JSON file must parse; a corrupt file is reported, never silently reset.
$corrupt = 0;
$count = 0;
if (is_dir($data)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.json') && !str_starts_with($f->getFilename(), '.~')) {
            $count++;
            json_decode((string)file_get_contents($f->getPathname()), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $corrupt++;
                $bad('Corrupt JSON: ' . substr(str_replace('\\', '/', $f->getPathname()), strlen(APP_ROOT) + 1));
            }
        }
    }
    $corrupt === 0 && $ok("{$count} data file(s) parse correctly");
}

try {
    $t = microtime(true);
    App::store()->update('health.json', function (array &$d) { $d['checked_at'] = Clock::nowIso(); });
    $ok(sprintf('Locked atomic write works (%.1f ms)', (microtime(true) - $t) * 1000));
} catch (\Throwable $e) {
    $bad('Locked write failed: ' . $e->getMessage());
}

$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
    if (DIRECTORY_SEPARATOR === '/') {
        $perm = fileperms($envFile) & 0777;
        ($perm & 0007) === 0 ? $ok(sprintf('.env present (mode %o)', $perm)) : $meh(sprintf('.env is world-readable (mode %o) — chmod 600 .env', $perm));
    } else {
        $ok('.env present');
    }
} else {
    $meh('.env not found — relying on real environment variables');
}
$real = str_replace('\\', '/', (string)realpath(APP_ROOT));
if (preg_match('#/public_html(/|$)#', $real)) {
    $bad('Project folder is inside public_html. Keep it outside and publish only public/ (php bin/publish-public.php)');
}

echo "\nConfiguration\n";
Config::get('app.debug') && Config::get('app.env') === 'production'
    ? $bad('APP_DEBUG=true with APP_ENV=production — set APP_DEBUG=false')
    : $ok('APP_DEBUG ' . (Config::get('app.debug') ? 'on (local)' : 'off'));
Config::get('session.secure_cookie') ? $ok('Secure session cookie on') : $meh('SESSION_SECURE_COOKIE=false — only for local http testing');
$ok('Business timezone ' . Clock::tz()->getName() . ', now ' . Clock::now()->format('Y-m-d H:i T'));

if (Installer::isInstalled($data)) {
    $rate = Settings::decimal('billing.usd_inr_rate', '0');
    $rate === '83.0000' ? $meh("USD→INR rate is the placeholder {$rate} — update billing.usd_inr_rate") : $ok("USD→INR rate {$rate}");

    $users = App::get(UserRepository::class);
    $admins = $users->countActiveAdmins();
    $admins > 0 ? $ok("{$admins} active admin(s)") : $bad('No admin — run php bin/create-admin.php --email=…');

    echo "\nImage slots (IST)\n";
    $slots = App::get(SlotDefinitionRepository::class)->active();
    foreach ($slots as $s) {
        $ok("{$s['label']}: {$s['start']}–{$s['end']}");
    }
    $ok('Daily limit ' . Settings::int('quota.daily_image_limit', 3) . ', scope ' . Settings::get('quota.scope', 'user'));

    echo "\nAzure deployments\n";
    $hasImage = false;
    foreach (App::get(AzureConfigRepository::class)->all() as $c) {
        if (empty($c['is_active'])) {
            continue;
        }
        $label = "{$c['purpose']}: {$c['deployment_name']} ({$c['model_name']} {$c['model_version']})" . (!empty($c['is_default']) ? ' [default]' : '');
        $hasImage = $hasImage || $c['purpose'] === 'image';
        (string)Env::get($c['api_key_env']) !== ''
            ? $ok("{$label} — {$c['api_key_env']} is set")
            : $bad("{$label} — {$c['api_key_env']} is empty in .env");
        AzureConfigRepository::hostAllowed($c['endpoint_base']) || $bad("{$label} — endpoint host not allowed");
        if (!empty($c['retirement_date'])) {
            $days = (int)Clock::now()->diff(new DateTimeImmutable($c['retirement_date'], Clock::tz()))->format('%r%a');
            if ($days < 0) {
                $bad("{$label} — RETIRED on {$c['retirement_date']}");
            } elseif ($days <= 90) {
                $meh("{$label} — retires {$c['retirement_date']} ({$days} days). Plan a replacement.");
            } else {
                $ok("{$label} — retires {$c['retirement_date']} ({$days} days)");
            }
        }
        ($c['verification_status'] ?? '') === 'ok'
            ? $ok("{$label} — verified {$c['last_verified_at']}")
            : $meh("{$label} — capabilities not verified yet (Phase 3 adds the Verify button)");
    }
    $hasImage || $meh('No image-generation deployment — the Image Generator will show a configuration error until one is added');

    $priced = count(App::get(PricingRepository::class)->all());
    $priced > 0 ? $ok("{$priced} pricing row(s)") : $meh('No model prices entered — costs show as "no pricing" until official Azure prices are added');
}

echo "\n" . ($fail ? "{$fail} failure(s), " : 'No failures, ') . "{$warn} warning(s).\n";
exit($fail ? 1 : 0);
