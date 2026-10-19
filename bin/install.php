<?php
declare(strict_types=1);

/**
 * Initialise (or repair) JSON storage. Safe to re-run: existing data is never overwritten.
 *   php bin/install.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Storage\Installer;

echo "Installing AI Usage & Billing (file storage)\n\n";

$missing = array_filter(['json', 'mbstring', 'fileinfo', 'curl'], fn($e) => !extension_loaded($e));
if ($missing) {
    fwrite(STDERR, 'Missing PHP extensions: ' . implode(', ', $missing) . "\n"
        . "Windows: enable them in php.ini (extension=curl, extension=mbstring, extension=fileinfo).\n"
        . "Hostinger: hPanel → Advanced → PHP Configuration → PHP extensions.\n");
    exit(1);
}

try {
    foreach ((new Installer(APP_ROOT))->run() as $step) {
        echo "  - {$step}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "\nInstall failed: {$e->getMessage()}\n");
    exit(1);
}

echo "\nNext:\n";
echo "  1. Put your Azure key in .env (AZURE_OPENAI_API_KEY=...)\n";
echo "  2. php bin/create-admin.php --email=you@example.com --name=\"Your Name\"\n";
echo "  3. php bin/check-env.php\n";
