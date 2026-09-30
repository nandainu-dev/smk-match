<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /** @var array<string, list<array{path: string, handler: callable(Request, array<string, string>): Response}>> */
    private array $parameterRoutes = [];

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function getPattern(string $path, callable $handler): void
    {
        $this->parameterRoutes['GET'][] = [
            'path' => $path,
            'handler' => $handler,
        ];
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function postPattern(string $path, callable $handler): void
    {
        $this->parameterRoutes['POST'][] = [
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method . ' ' . $request->path] ?? null;
        if ($handler === null) {
            foreach ($this->parameterRoutes[$request->method] ?? [] as $route) {
                $parameters = $this->matchPath($route['path'], $request->path);
                if ($parameters !== null) {
                    return ($route['handler'])($request, $parameters);
                }
            }

            return Response::json(['status' => 'not_found'], 404);
        }

        return $handler($request);
    }

    /** @return array<string, string>|null */
    private function matchPath(string $template, string $path): ?array
    {
        if (!str_starts_with($template, '/')) {
            throw new \InvalidArgumentException('Route patterns must begin with a slash.');
        }

        $segments = explode('/', ltrim($template, '/'));
        $patternSegments = [];
        $parameterNames = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z][A-Za-z0-9_]*)\}$/', $segment, $matches) === 1) {
                $parameterName = $matches[1];
                if (in_array($parameterName, $parameterNames, true)) {
                    throw new \InvalidArgumentException('Route patterns cannot reuse parameter names.');
                }

                $parameterNames[] = $parameterName;
                $patternSegments[] = '(?P<' . $parameterName . '>[^/]+)';
                continue;
            }

            $patternSegments[] = preg_quote($segment, '#');
        }

        $matched = preg_match('#^/' . implode('/', $patternSegments) . '$#D', $path, $matches);
        if ($matched !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($parameterNames as $parameterName) {
            $parameters[$parameterName] = $matches[$parameterName];
        }

        return $parameters;
    }
}
