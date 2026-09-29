<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(public readonly string $method, public readonly string $path)
    {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($path) ? $path : '/');
    }
}
