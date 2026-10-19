<?php
declare(strict_types=1);

/**
 * Create an administrator, or reset an existing user's password and make them admin.
 *   php bin/create-admin.php --email=you@example.com --name="Your Name"
 * The password is prompted for (hidden on Linux/macOS/Hostinger SSH) so it never
 * lands in shell history. For scripted installs set ADMIN_PASSWORD instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Config;
use App\Repositories\AuditLog;
use App\Repositories\UserRepository;
use App\Storage\Installer;

if (!Installer::isInstalled((string)Config::get('paths.data'))) {
    fwrite(STDERR, "Storage is not initialised. Run: php bin/install.php\n");
    exit(1);
}

$opts = getopt('', ['email:', 'name:']);
$email = (string)($opts['email'] ?? '');
$name = trim((string)($opts['name'] ?? 'Administrator'));
if ($email === '') {
    fwrite(STDERR, "Usage: php bin/create-admin.php --email=you@example.com [--name=\"Full Name\"]\n");
    exit(1);
}

function promptHidden(string $prompt): string
{
    echo $prompt;
    $canHide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($canHide) {
        shell_exec('stty -echo');
    }
    $v = rtrim((string)fgets(STDIN), "\r\n");
    if ($canHide) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    return $v;
}

$password = (string)getenv('ADMIN_PASSWORD');
if ($password === '') {
    if (DIRECTORY_SEPARATOR === '\\') {
        echo "(Windows: the password will be visible while typing. Clear the terminal afterwards.)\n";
    }
    $password = promptHidden('Password (min ' . UserRepository::MIN_PASSWORD . ' characters): ');
    if ($password !== promptHidden('Confirm password: ')) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(1);
    }
}

/** @var UserRepository $users */
$users = App::get(UserRepository::class);
$audit = App::get(AuditLog::class);

try {
    $existing = $users->findByEmailWithSecret($email);
    if ($existing) {
        $users->setPassword((int)$existing['id'], $password);
        $users->update((int)$existing['id'], ['role' => 'admin', 'status' => 'active']);
        $audit->record('admin.cli_password_reset', (int)$existing['id'], 'user', (string)$existing['id']);
        echo "Updated user #{$existing['id']} ({$existing['email']}): password reset, role admin, active.\n";
    } else {
        $u = $users->create($name, $email, $password, 'admin');
        $audit->record('admin.cli_created', $u['id'], 'user', (string)$u['id']);
        echo "Created admin #{$u['id']} ({$u['email']}).\n";
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
