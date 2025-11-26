<?php
declare(strict_types=1);
namespace Messa\Http;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void { $this->map('GET', $path, $handler); }
    public function post(string $path, callable|array $handler): void { $this->map('POST', $path, $handler); }
    public function put(string $path, callable|array $handler): void { $this->map('PUT', $path, $handler); }
    public function patch(string $path, callable|array $handler): void { $this->map('PATCH', $path, $handler); }
    public function delete(string $path, callable|array $handler): void { $this->map('DELETE', $path, $handler); }


    private function map(string $method, string $path, callable|array $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $req, Response $res): mixed
    {
        $table = $this->routes[$req->method] ?? [];
        // 1) точное совпадение
        if (isset($table[$req->path])) {
            $handler = $table[$req->path];
            return $this->call($handler, $req, $res);
        }
        // 2) шаблоны с {param}
        foreach ($table as $pattern => $handler) {
            if (str_contains($pattern, '{')) {
                $re = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
                $re = '#^' . $re . '$#';
                if (preg_match($re, $req->path, $m)) {
                    $params = [];
                    foreach ($m as $k => $v) if (is_string($k)) { $params[$k] = $v; }
                    $req->params = $params;
                    return $this->call($handler, $req, $res);
                }
            }
        }
        $res->status(404)->json(['error' => [
            'code' => 'not_found',
            'message' => 'Ресурс не найден',
            'details' => ['path' => $req->path],
            'trace_id' => bin2hex(random_bytes(8))
        ]]);
        return $res;
    }

    /** @param callable|array{class-string,string} $handler */
    private function call(callable|array $handler, Request $req, Response $res): mixed
    {
        if (is_array($handler) && is_string($handler[0])) {
            $obj = new ($handler[0])();
            return $obj->{$handler[1]}($req, $res);
        }
        return $handler($req, $res);
    }
}
