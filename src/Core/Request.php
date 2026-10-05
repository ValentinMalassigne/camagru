<?php
// Wraps the global request state (method, path, query, post body, headers)
// so controllers never touch superglobals directly.

declare(strict_types=1);

namespace App\Core;

class Request
{
    private string $method;
    private string $path;
    /** @var array<string, mixed> */
    private array $query;
    /** @var array<string, mixed> */
    private array $post;
    /** @var array<string, string> */
    private array $server;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $server
     */
    public function __construct(string $method, string $path, array $query, array $post, array $server)
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->server = $server;
    }

    /**
     * Build a Request from the PHP global environment.
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Treat HEAD as GET for routing.
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        // Use the path only (no query string); nginx passes the full URI.
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Normalise: leading slash, no trailing slash (except root).
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get a query parameter, with an optional default.
     */
    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return $value === null ? $default : (string) $value;
    }

    /**
     * Get a POST body parameter, with an optional default.
     */
    public function post(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;
        return $value === null ? $default : (string) $value;
    }

    /**
     * Get an uploaded file entry from $_FILES, or null.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    /**
     * Read a request header (case-insensitive).
     */
    public function header(string $name): ?string
    {
        $name = strtolower($name);
        // FastCGI puts headers in HTTP_* server vars.
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$serverKey])) {
            return $this->server[$serverKey];
        }
        // Content-Type and Content-Length arrive without the HTTP_ prefix.
        $bareKey = strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$bareKey])) {
            return $this->server[$bareKey];
        }
        return null;
    }
}
