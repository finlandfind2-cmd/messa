<?php
declare(strict_types=1);
namespace Messa\Http\Middleware;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Services\JwtService;
use Messa\Http\JsonResponder;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Core\Logger;
use Messa\Core\ConfigHelper;
use Messa\Core\Redis;

final class AuthMiddleware
{
    public static function handle(Request $req, Response $res, callable $next): Response
    {
        // Публичные маршруты
        if (self::isPublicRoute($req->path, $req->method)) {
            return $next($req, $res);
        }

        try {
            $jwtService = new JwtService();
            $claims = $jwtService->requireAuth($req); 

            if (!isset($claims['sub']) || !is_numeric($claims['sub'])) {
                throw new UnauthorizedException('Invalid user ID in token');
            }
            
            // Добавляем claims в request
            $req->user = [
                'id' => (int)$claims['sub'],
                'login' => (string)($claims['login'] ?? ''),
                'role' => (string)($claims['role'] ?? 'user')
            ];

            // Генерация CSRF token для аутентифицированных
            if (\Messa\Core\ConfigHelper::getBool('ENABLE_CSRF_TOKENS', false)) {
                $csrfToken = bin2hex(random_bytes(16));
                $prefix = rtrim((string)\Messa\Core\ConfigHelper::getString('REDIS_PREFIX', 'messa'), ':') . ':';
                $key = $prefix . 'csrf:' . $csrfToken;
                \Messa\Core\Redis::client()->setex($key, 3600, '1');
                $res->header('X-CSRF-Token', $csrfToken);
            }
            
            return $next($req, $res);
            
        } catch (\Messa\Http\Exceptions\UnauthorizedException $e) {
            Logger::warning('Auth middleware rejected request', [
                'path' => $req->path,
                'ip' => $req->ip(),
                'reason' => $e->getMessage()
            ]);
            
            return JsonResponder::error($res, 401, 'unauthorized', $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('Auth middleware unexpected error', [
                'error' => $e->getMessage(),
                'path' => $req->path
            ]);
            
            return JsonResponder::error($res, 500, 'internal', 'Authentication service error');
        }
    }

    private static function isPublicRoute(string $path, string $method): bool
    {
        $publicRoutes = ConfigHelper::getSecurity()['public_routes'] ?? [
            'GET' => ['/v1/health'],
            'POST' => [
                '/v1/auth/check_login',
                '/v1/auth/telegram/start', 
                '/v1/auth/telegram/confirm',
                '/v1/auth/login',
                '/v1/auth/refresh',
                '/v1/auth/password/forgot/start',
                '/v1/auth/password/forgot/confirm',
                '/v1/bot/telegram/webhook'
            ]
        ];

        return in_array($path, $publicRoutes[$method] ?? []);
    }
}