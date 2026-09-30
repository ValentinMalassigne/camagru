<?php
// Simple route table and matcher. Patterns use {param} placeholders that match
// a single path segment ([^/]+). Handlers are [ControllerClass, method] pairs.

declare(strict_types=1);

namespace App\Core;

class Router
{
    /**
     * @var array<int, array{method: string, pattern: string, handler: array{0: class-string, 1: string}, compiled: string}>
     */
    private array $routes = [];

    /**
     * Register a route. Pattern segments like {id} are captured into params.
     *
     * @param class-string $controller
     */
    public function add(string $method, string $pattern, string $controller, string $action): void
    {
        $method = strtoupper($method);
        // Compile the pattern into a regex: {param} => (?<param>[^/]+).
        $compiled = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m): string {
            return '(?<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'   => $method,
            'pattern'  => $pattern,
            'handler'  => [$controller, $action],
            'compiled' => '#^' . $compiled . '$#',
        ];
    }

    /**
     * Match a method + path against the route table.
     * Throws NotFoundException if nothing matches.
     *
     * @return array{handler: array{0: class-string, 1: string}, params: array<string, string>}
     */
    public function match(string $method, string $path): array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['compiled'], $path, $matches)) {
                // Keep only named captures as params.
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }
        throw new NotFoundException("No route for $method $path");
    }
}
