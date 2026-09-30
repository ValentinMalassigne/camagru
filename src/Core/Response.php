<?php
// Represents an HTTP response. Controllers build one and the front controller
// sends it. Keeps header and body output in one place.

declare(strict_types=1);

namespace App\Core;

class Response
{
    private int $status;
    /** @var array<string, string> */
    private array $headers;
    private string $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    /**
     * Convenience constructor for a simple status + body response.
     */
    public static function make(string $body, int $status = 200): self
    {
        return new self($body, $status, []);
    }

    /**
     * Build a redirect response. The path should be site-relative ("/login").
     */
    public static function redirect(string $path, int $status = 302): self
    {
        return new self('', $status, ['Location' => $path]);
    }

    /**
     * Build a JSON response.
     *
     * @param array<string, mixed>|array<int, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_SLASHES) ?: '', $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    /**
     * Send the response to the client: status line, headers, then body.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
