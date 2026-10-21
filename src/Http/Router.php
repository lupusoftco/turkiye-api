<?php

declare(strict_types=1);

namespace TurkiyeApi\Http;

/**
 * Minimal router. Patterns use `{name}` placeholders, optionally with a
 * regex constraint: `{code:[0-9]{2}}`.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable(Request): Response, group: string}> */
    private array $routes = [];

    /** @param callable(Request): Response $handler */
    public function add(string $method, string $pattern, callable $handler, string $group = 'default'): void
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^{}]*(?:\{[^{}]*\}[^{}]*)*))?\}/',
            static fn (array $m) => '(?P<' . $m[1] . '>' . (($m[2] ?? '') !== '' ? $m[2] : '[^/]+') . ')',
            $pattern,
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#u',
            'handler' => $handler,
            'group' => $group,
        ];
    }

    /** @param callable(Request): Response $handler */
    public function get(string $pattern, callable $handler, string $group = 'default'): void
    {
        $this->add('GET', $pattern, $handler, $group);
    }

    /** @param callable(Request): Response $handler */
    public function post(string $pattern, callable $handler, string $group = 'default'): void
    {
        $this->add('POST', $pattern, $handler, $group);
    }

    /**
     * @return array{handler: callable(Request): Response, params: array<string, string>, group: string}
     */
    public function match(string $method, string $path): array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'params' => $params, 'group' => $route['group']];
        }
        if ($allowed !== []) {
            $allowed = array_unique($allowed);
            throw new ApiException(405, 'method-not-allowed', 'Use ' . implode(' or ', $allowed) . ' for this endpoint.', [], [
                'Allow' => implode(', ', array_merge($allowed, ['OPTIONS'])),
            ]);
        }
        throw ApiException::notFound('No endpoint matches this path. See /docs for the list of endpoints.');
    }
}
