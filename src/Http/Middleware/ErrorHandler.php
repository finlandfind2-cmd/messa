<?php
declare(strict_types=1);
namespace Messa\Http\Middleware;

use Messa\Core\Logger;
use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\HttpException;

final class ErrorHandler
{
    public static function handle(Request $req, Response $res, callable $next): Response
    {
        try {
            return $next($req, $res);
        } catch (HttpException $e) {
            $traceId = bin2hex(random_bytes(8));
            $res->header('Request-Id', $traceId);
            
            // Используем warning для клиентских ошибок (4xx), error для серверных (5xx)
            if ($e->status() >= 400 && $e->status() < 500) {
                Logger::warning('HttpException', [
                    'code' => $e->code(),
                    'status' => $e->status(), 
                    'path' => $req->path,
                    'method' => $req->method,
                    'trace_id' => $traceId,
                    'details' => $e->details()
                ]);
            } else {
                Logger::error('HttpException', [
                    'code' => $e->code(),
                    'status' => $e->status(),
                    'path' => $req->path,
                    'method' => $req->method,
                    'trace_id' => $traceId,
                    'details' => $e->details()
                ]);
            }
            
            return $res->status($e->status())->json([
                'error' => [
                    'code' => $e->code(),
                    'message' => $e->getMessage(),
                    'details' => $e->details(),
                    'trace_id' => $traceId
                ]
            ]);
        } catch (\PDOException $e) {
            $traceId = bin2hex(random_bytes(8));
            $res->header('Request-Id', $traceId);
            Logger::error('Database error', [
                'error' => self::safeMessage($e->getMessage()),
                'path' => $req->path,
                'method' => $req->method,
                'trace_id' => $traceId
            ]);
            
            return $res->status(503)->json([
                'error' => [
                    'code' => 'service_unavailable',
                    'message' => 'Service temporarily unavailable',
                    'trace_id' => $traceId
                ]
            ]);
        } catch (\Throwable $e) {
            $traceId = bin2hex(random_bytes(8));
            
            // Проверяем, является ли ошибка связанной с Redis
            $isRedisError = str_contains($e->getMessage(), 'Redis') || 
                           str_contains($e->getMessage(), 'redis') ||
                           ($e->getPrevious() && (
                               str_contains($e->getPrevious()->getMessage(), 'Redis') ||
                               str_contains($e->getPrevious()->getMessage(), 'redis')
                           ));
            
            if ($isRedisError) {
                Logger::error('Redis error', [
                    'error' => $e->getMessage(),
                    'path' => $req->path,
                    'method' => $req->method,
                    'trace_id' => $traceId
                ]);
                
                return $res->status(503)->json([
                    'error' => [
                        'code' => 'service_unavailable', 
                        'message' => 'Service temporarily unavailable',
                        'trace_id' => $traceId
                    ]
                ]);
            }
            
            // Общая обработка всех остальных исключений
            Logger::error('Unhandled exception', [
                'error' => self::safeMessage($e->getMessage()),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $req->path,
                'method' => $req->method,
                'trace_id' => $traceId
            ]);
            
            return $res->status(500)->json([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Internal server error',
                    'trace_id' => $traceId
                ]
            ]);
        }
    }

    // Маскирование чувствительных фрагментов в message
    private static function safeMessage(string $m): string
    {
        $m = preg_replace('/Bearer\\s+[A-Za-z0-9\\-_.=]+/i', 'Bearer [REDACTED]', $m ?? '');
        $m = preg_replace('/(refresh[_-]?token\\s*[=:]\\s*)[^\\s]+/i', '\\1[REDACTED]', $m);
        $m = preg_replace('/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+)/', '[REDACTED_EMAIL]', $m);
        return mb_substr((string)$m, 0, 500, 'UTF-8');
    }
}