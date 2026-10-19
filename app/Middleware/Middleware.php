<?php
declare(strict_types=1);

namespace App\Middleware;

/**
 * A route guard. $arg is the part after ":" in the route spec, e.g. "role:admin" → "admin".
 * Call $next() to continue, or redirect / throw HttpException to stop.
 */
abstract class Middleware
{
    public function __construct(protected ?string $arg = null)
    {
    }

    abstract public function handle(\Closure $next): void;
}
