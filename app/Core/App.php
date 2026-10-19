<?php
declare(strict_types=1);

namespace App\Core;

use App\Storage\FileStore;

/**
 * Tiny service locator. Every repository/service takes the FileStore in its
 * constructor, so tests can point the whole app at a temporary directory
 * with App::useStore().
 */
final class App
{
    private static ?FileStore $store = null;
    /** @var array<class-string, object> */
    private static array $services = [];

    public static function store(): FileStore
    {
        return self::$store ??= new FileStore(
            (string)Config::get('paths.data'),
            (string)Config::get('paths.locks')
        );
    }

    public static function useStore(FileStore $store): void
    {
        self::$store = $store;
        self::$services = [];
        Settings::flush();
    }

    /**
     * Shared instance of a class whose constructor takes (FileStore).
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    public static function get(string $class): object
    {
        return self::$services[$class] ??= new $class(self::store());
    }
}
