<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Static configuration. Secrets come from the environment / .env only.
 * Admin-editable values (slot windows, prices, exchange rate, quotas) live in
 * storage/data/*.json, not here.
 *
 * All paths are derived from APP_ROOT (the project folder), never hard-coded,
 * so the same code runs on Windows, locally and on Hostinger.
 */
$storage = APP_ROOT . '/storage';

return [
    'app' => [
        'name'     => Env::get('APP_NAME', 'AI Content Generator'),
        'env'      => Env::get('APP_ENV', 'production'),        // production | local
        'debug'    => Env::bool('APP_DEBUG', false),
        'url'      => rtrim((string)Env::get('APP_URL', ''), '/'),
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'), // business tz; stored timestamps are UTC
        'version'  => '1.1.0-file-storage',
    ],

    'session' => [
        'name'           => Env::get('SESSION_NAME', 'aibill_sid'),
        'idle_minutes'   => Env::int('SESSION_IDLE_MINUTES', 60),
        'absolute_hours' => Env::int('SESSION_ABSOLUTE_HOURS', 12),
        'secure_cookie'  => Env::bool('SESSION_SECURE_COOKIE', true), // requires HTTPS
        'save_path'      => $storage . '/sessions',                  // private, not the shared /tmp
    ],

    'azure' => [
        // Fallback key name; each deployment names its own env var.
        'default_key_env' => 'AZURE_OPENAI_API_KEY',
        // Hosts we will send API keys to — blocks SSRF / key leaks if a config is tampered with.
        'allowed_host_suffixes' => ['.openai.azure.com', '.services.ai.azure.com', '.cognitiveservices.azure.com'],
    ],

    'paths' => [
        'root'     => APP_ROOT,
        'storage'  => $storage,
        'data'     => $storage . '/data',      // all JSON data files
        'locks'    => $storage . '/locks',     // flock() lock files
        'images'   => $storage . '/images',    // generated images, YYYY/MM/DD/
        'reports'  => $storage . '/reports',   // CSV / PDF statements, YYYY/MM/
        'logs'     => $storage . '/logs',
        'sessions' => $storage . '/sessions',
        'backups'  => $storage . '/backups',
        'branding' => $storage . '/branding',
        'cache'    => $storage . '/cache',
        'seed'     => APP_ROOT . '/resources/seed',
        'views'    => APP_ROOT . '/app/Views',
    ],
];
