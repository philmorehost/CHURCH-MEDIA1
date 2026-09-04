<?php
declare(strict_types=1);

/**
 * Lightweight router for the public site. Explicit routes are tried first
 * (registered in core/routes.php), then two catch-alls hand off to the flat,
 * single-segment file layout used by admin/ and api/ — both directories live
 * outside the web root, so this dispatch step is what makes /admin/media and
 * /api/feed resolve at all under clean URLs.
 */
class Router
{
    /** @var array<string, array<int, array{pattern: string, regex: string, handler: callable}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[$method][] = ['pattern' => $pattern, 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $uri = $uri === '' ? '/' : $uri;

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $uri, $matches)) {
                $params = array_filter($matches, fn ($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
        }

        if ($this->dispatchFlatFile($uri, ADMIN_PATH, '/admin')) {
            return;
        }
        if ($this->dispatchFlatFile($uri, API_PATH, '/api')) {
            return;
        }

        http_response_code(404);
        render('404', [], true);
    }

    /** Maps /prefix/segment -> $dir/segment.php, defaulting empty segment to "index". One level, strictly whitelisted. */
    private function dispatchFlatFile(string $uri, string $dir, string $prefix): bool
    {
        if ($uri !== $prefix && !str_starts_with($uri, $prefix . '/')) {
            return false;
        }
        $segment = trim(substr($uri, strlen($prefix)), '/');
        $segment = $segment === '' ? 'index' : $segment;

        if (!preg_match('/^[a-z0-9_-]+$/', $segment)) {
            http_response_code(404);
            render('404', [], true);
            return true;
        }

        if ($prefix === '/api') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
                http_response_code(204);
                return true;
            }
        }

        $file = $dir . '/' . $segment . '.php';
        if (!is_file($file)) {
            http_response_code(404);
            if ($prefix === '/api') {
                jsonResponse(['status' => 'error', 'message' => 'Unknown endpoint'], 404);
            }
            render('404', [], true);
            return true;
        }

        require $file;
        return true;
    }
}
