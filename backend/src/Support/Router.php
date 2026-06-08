<?php

namespace App\Support;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $route, callable $callback): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'route' => $route,
            'callback' => $callback,
        ];
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $route = $_GET['route'] ?? '';

        foreach ($this->routes as $r) {
            if ($r['method'] === $method && $r['route'] === $route) {
                call_user_func($r['callback']);
                return;
            }
        }

        Response::json(['message' => 'Action not found.'], 404);
    }
}