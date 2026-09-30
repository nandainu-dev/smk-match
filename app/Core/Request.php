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
        private readonly array $query = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $query = parse_url($uri, PHP_URL_QUERY);

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
            self::parseQuery(is_string($query) ? $query : ''),
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

    /** @return list<string|null>|null */
    public function queryValues(string $name): ?array
    {
        if (!array_key_exists($name, $this->query)) {
            return null;
        }

        $value = $this->query[$name];
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value) || !array_is_list($value)) {
            return [null];
        }

        foreach ($value as $item) {
            if ($item !== null && !is_string($item)) {
                return [null];
            }
        }

        return $value;
    }

    /** @return array<string, list<string|null>> */
    private static function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $values = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            $value = isset($parts[1]) ? urldecode($parts[1]) : '';
            if (preg_match('/^([^[]+)\[.*\]$/D', $name, $matches) === 1) {
                $values[$matches[1]][] = null;
                continue;
            }

            $values[$name][] = $value;
        }

        return $values;
    }
}
