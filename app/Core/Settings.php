<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Admin-editable runtime settings, stored in storage/data/settings.json:
 *   {"items": {"quota.scope": {"value": "user", "type": "string",
 *              "description": "…", "updated_by": null, "updated_at": "…"}}}
 * Cached per request; set() writes under an exclusive lock.
 */
final class Settings
{
    public const FILE = 'settings.json';
    private const TYPES = ['string', 'int', 'decimal', 'bool', 'json'];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $doc = App::store()->read(self::FILE, ['items' => []]);
            self::$cache = [];
            foreach ($doc['items'] ?? [] as $key => $row) {
                self::$cache[$key] = $row['value'] ?? null;
            }
        }
        return self::$cache;
    }

    /** Full rows including type/description (for the settings UI). */
    public static function rows(): array
    {
        return App::store()->read(self::FILE, ['items' => []])['items'] ?? [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    /** Decimal values are kept as strings to avoid float drift in money maths. */
    public static function decimal(string $key, string $default = '0'): string
    {
        $v = self::get($key);
        return is_numeric($v) ? (string)$v : $default;
    }

    /**
     * Update an existing setting, validating against its declared type.
     * Unknown keys are rejected so typos can't create dead settings.
     */
    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        App::store()->update(self::FILE, function (array &$doc) use ($key, $value, $userId) {
            $row = $doc['items'][$key] ?? null;
            if ($row === null) {
                throw new \InvalidArgumentException("Unknown setting: {$key}");
            }
            $type = in_array($row['type'] ?? 'string', self::TYPES, true) ? $row['type'] : 'string';
            $doc['items'][$key]['value'] = self::coerce($key, $value, $type);
            $doc['items'][$key]['updated_by'] = $userId;
            $doc['items'][$key]['updated_at'] = Clock::nowIso();
        }, ['items' => []], true);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function coerce(string $key, mixed $v, string $type): mixed
    {
        return match ($type) {
            'int' => filter_var($v, FILTER_VALIDATE_INT) !== false
                ? (int)$v : throw new \InvalidArgumentException("{$key} must be a whole number"),
            'decimal' => is_numeric($v) && preg_match('/^-?\d+(\.\d+)?$/', trim((string)$v))
                ? trim((string)$v) : throw new \InvalidArgumentException("{$key} must be a decimal number"),
            'bool' => (bool)filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'json' => is_array($v) ? $v : throw new \InvalidArgumentException("{$key} must be a list/object"),
            default => (string)$v,
        };
    }
}
