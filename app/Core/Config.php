<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Static (file-based) configuration from config/app.php.
 * Runtime, admin-editable settings live in the system_settings table — see Settings.
 */
final class Config
{
    private static array $items = [];

    public static function load(string $file): void
    {
        self::$items = require $file;
    }

    /** Dot-path lookup: Config::get('db.host') */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::$items;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return $default;
            }
            $node = $node[$seg];
        }
        return $node;
    }
}
