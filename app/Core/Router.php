<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\Middleware;

/**
 * Minimal router: exact paths plus {param} segments, named middleware.
 *
 *   $r->get('/', [DashboardController::class, 'index'], ['auth']);
 *   $r->post('/login', [AuthController::class, 'login'], ['guest']);
 *
 * Every POST is CSRF-checked automatically — it can't be forgotten on a route.
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable|array, middleware: list<string>}>> */
    private array $routes = [];

    /** @var array<string, class-string<Middleware>> */
    private array $aliases = [];

    public function alias(string $name, string $class): void
    {
        $this->aliases[$name] = $class;
    }

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, array_merge(['csrf'], $middleware));
    }

    private function add(string $method, string $path, callable|array $handler, array $middleware): void
    {
        $this->routes[$path][$method] = ['handler' => $handler, 'middleware' => $middleware];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        try {
            [$route, $params] = $this->match($method, $path);
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                fn(\Closure $next, string $mw) => fn() => $this->middleware($mw)->handle($next),
                fn() => $this->call($route['handler'], $params)
            );
            $pipeline();
        } catch (HttpException $e) {
            Response::error($e->status, $e->getMessage());
        }
    }

    /** @return array{0: array, 1: array<string, string>} */
    private function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $pattern => $byMethod) {
            $params = self::matchPath($pattern, $path);
            if ($params === null) {
                continue;
            }
            if (isset($byMethod[$method])) {
                return [$byMethod[$method], $params];
            }
            $allowed = array_merge($allowed, array_keys($byMethod));
        }
        if ($allowed) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            throw new HttpException(405);
        }
        throw new HttpException(404);
    }

    /** @return array<string, string>|null */
    private static function matchPath(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }
        if (!str_contains($pattern, '{')) {
            return null;
        }
        $regex = '#^' . preg_replace('#\\\\\{([a-z_]+)\\\\\}#', '(?P<$1>[A-Za-z0-9_\-]+)', preg_quote($pattern, '#')) . '$#';
        if (!preg_match($regex, $path, $m)) {
            return null;
        }
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    private function middleware(string $spec): Middleware
    {
        [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);
        $class = $this->aliases[$name] ?? throw new \LogicException("Unknown middleware: {$name}");
        return new $class($arg);
    }

    private function call(callable|array $handler, array $params): void
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        $handler(...$params);
    }
}
