<?php
declare(strict_types=1);
namespace Messa\Http\Middleware;

use Messa\Core\Config;
use Messa\Core\Redis;
use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Core\Logger;
use Messa\Core\ConfigHelper;

final class RateLimit
{
    /** Lua токен-бакет: ключ с TTL окна.
     * KEYS[1]=key, ARGV[1]=tokens, ARGV[2]=window_ms, ARGV[3]=now_ms
     * return {allowed, ttl_ms, count}
     */
    private const LUA = <<<'LUA'
local key   = KEYS[1]
local limit = tonumber(ARGV[1])
local win   = tonumber(ARGV[2])
local now   = tonumber(ARGV[3])
local val = redis.call('GET', key)
if not val then
  redis.call('SET', key, 1, 'PX', win)
  return {1, win, 1}
end
local count = tonumber(val)
if count >= limit then
  local ttl = redis.call('PTTL', key)
  if ttl < 0 then ttl = win end
  return {0, ttl, count}
end
redis.call('INCR', key)
local ttl = redis.call('PTTL', key)
if ttl < 0 then
  redis.call('PEXPIRE', key, win)
  ttl = win
end
return {1, ttl, count+1}
LUA;

    /** @param callable(Request,Response):Response $next */
    public static function handle(Request $req, Response $res, callable $next): Response
    {
        $securityConfig = ConfigHelper::getSecurity();
        $rateLimitConfig = $securityConfig['rate_limiting'];
        
        $tokens = $rateLimitConfig['default_tokens'];
        $window = $rateLimitConfig['default_window_ms'];

        $ip = $req->ip();
        $trusted = array_filter(array_map('trim', explode(',', (string)(ConfigHelper::getString('TRUSTED_PROXIES', '') ?? ''))));
        if (!empty($trusted) && self::ipInRanges($ip, $trusted)) {
            $xff = (string)($req->header('X-Forwarded-For') ?? '');
            if ($xff !== '') {
                $parts = array_map('trim', explode(',', $xff));
                if (!empty($parts[0])) { $ip = $parts[0]; }
            }
        }
        
        $path   = $req->path;

        // Нормализация префикса: всегда ровно один ':' в конце
        $prefix = rtrim((string)ConfigHelper::getString('REDIS_PREFIX', 'messa:'), ':') . ':';

        // Включим HTTP-метод в ключ, чтобы GET/POST на один путь не делили корзину (опция, но полезно)
        $key    = $prefix . 'rl:' . hash('sha256', $req->method . '|' . $path . '|' . $ip);


        try {
            $now = (int)floor(microtime(true)*1000);
            // Для predis: args массив + numkeys=1
            $r = Redis::client()->eval(
                self::LUA,
                1,                           // numkeys = 1
                $key,                        // KEYS[1]
                (string)$tokens,             // ARGV[1]
                (string)$window,             // ARGV[2]
                (string)$now                 // ARGV[3]
            );
            $allowed = (int)($r[0] ?? 0) === 1;
            $ttl = (int)($r[1] ?? $window);
            $count = (int)($r[2] ?? 0);
            
            $res->header('X-RateLimit-Limit', (string)$tokens);
            $res->header('X-RateLimit-Remaining', (string)max(0, $tokens - $count));
            // Семантика: ttl в мс до «разморозки»
            $res->header('X-RateLimit-Reset', (string)$ttl);          // мс до сброса окна
            $res->header('Retry-After', (string)max(1, (int)ceil($ttl/1000))); // сек для совместимости
            
            if (!$allowed) {
                Logger::warning('Rate limit exceeded', [
                    'ip' => $ip,
                    'path' => $path,
                    'method' => $req->method,
                    'count' => $count,
                    'limit' => $tokens
                ]);
                
                $payload = [
                    'error' => [
                        'code' => 'rate_limited',
                        'message' => 'Слишком много запросов. Повторите позже.',
                        'details' => ['retry_after_ms' => $ttl],
                        'trace_id' => bin2hex(random_bytes(8)),
                    ]
                ];
                $rid = bin2hex(random_bytes(8));
                $res->header('Request-Id', $rid);
                $payload['error']['trace_id'] = $rid;
                return $res->status(429)->json($payload);
            }
        } catch (\Throwable $e) {
            Logger::error('Rate limit error', [
                'error' => $e->getMessage(),
                'ip' => $ip,
                'path' => $path
            ]);
            // При сбое Redis не блокируем
        }

        return $next($req, $res);
    }

    /** IPv4: 1.2.3.4 или CIDR 1.2.3.0/24 */
    private static function ipInRanges(string $ip, array $ranges): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        $ipl = ip2long($ip);
        foreach ($ranges as $r) {
            $r = trim($r);
            if ($r === '') continue;
            if (strpos($r, '/') === false) {
                if ($r === $ip) return true; // точный IP
                continue;
            }
            [$subnet, $mask] = explode('/', $r, 2);
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
            $mask = (int)$mask;
            $mask = max(0, min(32, $mask));
            $sub = ip2long($subnet);
            $m = -1 << (32 - $mask);
            if (($ipl & $m) === ($sub & $m)) return true;
        }
        return false;
    }
}
