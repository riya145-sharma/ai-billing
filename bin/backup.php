<?php
declare(strict_types=1);

/**
 * Snapshot storage/data into storage/backups/data-YYYYMMDD-HHMMSS.zip
 * (or a plain folder copy if ZipArchive is unavailable). Keeps the newest 14.
 *   php bin/backup.php              (daily via cron)
 * Images and reports are not included — download storage/images separately
 * if you need them backed up off-server.
 * Each file is copied under its shared lock, so every file in the backup is
 * a complete version.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Config;

$keep = 14;
$data = str_replace('\\', '/', (string)Config::get('paths.data'));
$backups = (string)Config::get('paths.backups');
if (!is_dir($data)) {
    fwrite(STDERR, "Nothing to back up (storage/data missing).\n");
    exit(1);
}
@mkdir($backups, 0750, true);
$name = 'data-' . gmdate('Ymd-His');
$store = App::store();

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($data) + 1);
    if ($f->isFile() && !str_starts_with($f->getFilename(), '.~')) {
        $files[] = $rel;
    }
}
sort($files);

$read = static function (string $rel) use ($store, $data): string {
    try {
        return (string)$store->withLock($rel, false, fn() => file_get_contents($data . '/' . $rel));
    } catch (\Throwable) {
        return (string)@file_get_contents($data . '/' . $rel); // not a lockable path; copy as-is
    }
};

if (class_exists(ZipArchive::class)) {
    $zip = new ZipArchive();
    $target = "{$backups}/{$name}.zip";
    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        fwrite(STDERR, "Cannot create {$target}\n");
        exit(1);
    }
    foreach ($files as $rel) {
        $zip->addFromString($rel, $read($rel));
    }
    $zip->close();
} else {
    $target = "{$backups}/{$name}";
    foreach ($files as $rel) {
        @mkdir(dirname("{$target}/{$rel}"), 0750, true);
        file_put_contents("{$target}/{$rel}", $read($rel));
    }
}
if (DIRECTORY_SEPARATOR === '/') {
    @chmod($target, is_dir($target) ? 0750 : 0640);
}
echo 'Backed up ' . count($files) . ' file(s) to storage/backups/' . basename($target) . "\n";

// Retention
$all = glob($backups . '/data-*') ?: [];
rsort($all, SORT_STRING);
foreach (array_slice($all, $keep) as $old) {
    if (is_dir($old)) {
        $rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rit as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($old);
    } else {
        @unlink($old);
    }
}
