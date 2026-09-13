<?php

declare(strict_types=1);

namespace AIPanel\Http;

use AIPanel\Support\Container;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:array{0:class-string,1:string},middleware:list<class-string>}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler @param list<class-string> $middleware */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => rtrim($pattern, '/') ?: '/',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @param array{0:class-string,1:string} $handler @param list<class-string> $middleware */
    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param array{0:class-string,1:string} $handler @param list<class-string> $middleware */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function dispatch(Request $request, Container $container): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $request->attributes = $params;

            foreach ($route['middleware'] as $middlewareClass) {
                /** @var object{handle: callable} $middleware */
                $middleware = $container->get($middlewareClass);
                $short = $middleware->handle($request);
                if ($short instanceof Response) {
                    return $short;
                }
            }

            [$class, $method] = $route['handler'];
            $controller = $container->get($class);

            return $controller->{$method}($request);
        }

        return $pathMatched
            ? Response::json(['error' => 'method_not_allowed'], 405)
            : Response::json(['error' => 'not_found', 'path' => $request->path], 404);
    }

    /** @return array<string,string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $quoted = preg_quote($pattern, '#');
        // preg_quote escapes the placeholder braces; restore them, then turn
        // {name} into a named capture group.
        $quoted = str_replace(['\\{', '\\}'], ['{', '}'], $quoted);
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $quoted) . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        return array_filter($matches, static fn ($k): bool => is_string($k), ARRAY_FILTER_USE_KEY);
    }
}
