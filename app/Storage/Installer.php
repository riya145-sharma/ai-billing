<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Clock;
use App\Core\Config;

/**
 * Idempotent installer: safe to run again after every deploy.
 *  - creates the storage tree
 *  - writes deny-all web protection files (in case the folder is ever web-exposed,
 *    or FTP skipped dotfiles)
 *  - seeds missing JSON data files from resources/seed (never overwrites data)
 *  - creates .env from .env.example if missing
 *  - tightens permissions on Linux (no-op on Windows)
 */
final class Installer
{
    public const DIRS = ['data', 'locks', 'images', 'reports', 'logs', 'sessions', 'backups', 'branding', 'cache'];

    private const HTACCESS_DENY = <<<'TXT'
# Never web-served. Images and reports are streamed by an authorised PHP route.
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes

TXT;

    private const HTACCESS_ROOT = <<<'TXT'
# Safety net: if this project folder is ever placed inside public_html,
# deny everything except the public/ directory.
Options -Indexes
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^public/ - [L]
  RewriteRule ^ - [F]
</IfModule>
<IfModule !mod_rewrite.c>
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
</IfModule>

TXT;

    private const WEB_CONFIG_DENY = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <authorization><deny users="*" /></authorization>
    <security><requestFiltering><hiddenSegments><add segment="data" /></hiddenSegments></requestFiltering></security>
  </system.webServer>
</configuration>

XML;

    private array $log = [];
    private bool $windows;

    public function __construct(private string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->windows = DIRECTORY_SEPARATOR === '\\';
    }

    /** @return string[] human-readable steps performed */
    public function run(): array
    {
        $this->log = [];
        $storage = $this->root . '/storage';

        foreach (array_merge([''], self::DIRS) as $d) {
            $dir = $storage . ($d === '' ? '' : '/' . $d);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new StorageException("Cannot create storage/{$d} — check folder permissions");
                }
                $this->log[] = "created storage/{$d}";
            }
            if (!is_writable($dir)) {
                throw new StorageException("storage/{$d} is not writable by PHP");
            }
        }

        $this->put($storage . '/.htaccess', self::HTACCESS_DENY);
        $this->put($storage . '/web.config', self::WEB_CONFIG_DENY);
        $this->put($storage . '/index.html', '');
        $this->put($this->root . '/.htaccess', self::HTACCESS_ROOT);

        $fs = new FileStore($storage . '/data', $storage . '/locks');
        $seedDir = (string)Config::get('paths.seed', $this->root . '/resources/seed');
        foreach (glob($seedDir . '/*.json') ?: [] as $seed) {
            $name = basename($seed);
            $data = json_decode((string)file_get_contents($seed), true);
            if (!is_array($data)) {
                throw new StorageException("Seed file is invalid JSON: resources/seed/{$name}");
            }
            if ($fs->createIfMissing($name, $data)) {
                $this->log[] = "seeded storage/data/{$name}";
            } else {
                $this->mergeNewSettings($fs, $name, $data);
            }
        }

        $this->ensureEnv();
        $this->tightenPermissions($storage);

        $fs->update('installed.json', function (array &$doc) {
            $doc['installed_at'] ??= Clock::nowIso();
            $doc['last_install_run'] = Clock::nowIso();
            $doc['version'] = Config::get('app.version');
            $doc['php'] = PHP_VERSION;
        }, [], true);
        $this->log[] = 'storage ready';
        return $this->log;
    }

    public static function isInstalled(string $dataDir): bool
    {
        return is_file(rtrim($dataDir, '/') . '/installed.json');
    }

    /** New settings keys added in later versions are added to an existing settings.json; values are never overwritten. */
    private function mergeNewSettings(FileStore $fs, string $name, array $seed): void
    {
        if ($name !== 'settings.json') {
            return;
        }
        $added = $fs->update($name, function (array &$doc) use ($seed) {
            $n = 0;
            foreach ($seed['items'] ?? [] as $k => $row) {
                if (!isset($doc['items'][$k])) {
                    $doc['items'][$k] = $row;
                    $n++;
                }
            }
            return $n;
        }, ['items' => []], true);
        if ($added) {
            $this->log[] = "added {$added} new setting(s) to storage/data/settings.json";
        }
    }

    private function ensureEnv(): void
    {
        $env = $this->root . '/.env';
        $example = $this->root . '/.env.example';
        if (is_file($env) || !is_file($example)) {
            return;
        }
        if (@copy($example, $env) === false) {
            throw new StorageException('Cannot create .env — create it manually from .env.example');
        }
        if (!$this->windows) {
            @chmod($env, 0600);
        }
        $this->log[] = 'created .env from .env.example (fill in AZURE_OPENAI_API_KEY)';
    }

    private function tightenPermissions(string $storage): void
    {
        if ($this->windows) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        @chmod($storage, 0750);
        foreach ($it as $f) {
            @chmod($f->getPathname(), $f->isDir() ? 0750 : 0640);
        }
        if (is_file($this->root . '/.env')) {
            @chmod($this->root . '/.env', 0600);
        }
    }

    private function put(string $file, string $content): void
    {
        if (is_file($file) && file_get_contents($file) === $content) {
            return;
        }
        if (@file_put_contents($file, $content) === false) {
            throw new StorageException('Cannot write ' . basename($file));
        }
        $this->log[] = 'wrote ' . substr($file, strlen($this->root) + 1);
    }
}
