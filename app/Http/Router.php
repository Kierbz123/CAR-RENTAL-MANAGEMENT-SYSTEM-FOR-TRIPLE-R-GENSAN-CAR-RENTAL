<?php
declare(strict_types=1);

namespace TripleR\Http;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $path = $path !== '/' ? rtrim($path, '/') : '/';
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method][$request->path] ?? null;
        if ($handler === null) {
            return Response::json(['error' => 'Not found'], 404);
        }
        $response = $handler($request);
        if (!$response instanceof Response) {
            throw new \LogicException('Route handlers must return a Response.');
        }
        return $response;
    }
}
