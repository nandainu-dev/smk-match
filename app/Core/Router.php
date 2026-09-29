<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method . ' ' . $request->path] ?? null;
        if ($handler === null) {
            return Response::json(['status' => 'not_found'], 404);
        }

        return $handler($request);
    }
}
