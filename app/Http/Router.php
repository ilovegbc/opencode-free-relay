<?php

declare(strict_types=1);

namespace Relay\Http;

final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function any(string $pattern, callable $handler): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->add($method, $pattern, $handler);
        }
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = $this->compile($pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): mixed
    {
        $method = $request->method();
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $path = $request->path();
        $pathMatched = false;

        if ($method === 'OPTIONS') {
            header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            return null;
        }

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = urldecode((string) $value);
                }
            }
            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            throw new HttpException(405, 'Method not allowed.', 'method_not_allowed', ['Allow' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS']);
        }
        throw new HttpException(404, 'Not found.', 'not_found');
    }

    private function compile(string $pattern): string
    {
        $pattern = '/' . ltrim($pattern, '/');
        $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\??\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts ?: [] as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}$/', $part, $m)) {
                $name = $m[1];
                $optional = ($m[2] ?? '') === '?';
                $regex .= $optional
                    ? '(?P<' . $name . '>[^/]+)?'
                    : '(?P<' . $name . '>[^/]+)';
                continue;
            }
            $regex .= preg_quote($part, '#');
        }
        return '#^' . $regex . '$#';
    }
}
