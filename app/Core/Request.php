<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string, string> $headers @param array<string, string> $cookies */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers = [],
        public readonly string $body = '',
        private readonly array $cookies = [],
        public readonly bool $isHttps = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }

        $https = (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['HTTPS'] ?? '') === '1')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            is_string($path) ? $path : '/',
            $headers,
            (string) file_get_contents('php://input'),
            array_filter($_COOKIE, 'is_string'),
            $https,
        );
    }

    public function header(string $name): ?string
    {
        $canonicalName = strtoupper($name);
        foreach ($this->headers as $headerName => $value) {
            if (strtoupper($headerName) === $canonicalName) {
                return $value;
            }
        }

        return null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }
}
