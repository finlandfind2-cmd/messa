<?php
declare(strict_types=1);
namespace Messa\Core;

final class Redis
{
    private static ?\Predis\Client $c = null;

    public static function client(): \Predis\Client
    {
        if (!self::$c) {
            $url = Env::get('REDIS_URL', '');
            if ($url !== '') {
                self::$c = new \Predis\Client($url);
            } else {
                $opt = [
                    'host' => Env::get('REDIS_HOST', '127.0.0.1'),
                    'port' => (int)Env::get('REDIS_PORT', '6379'),
                    'database' => (int)Env::get('REDIS_DB', '0'),
                    'timeout' => 2.0,
                ];
                $pwd = Env::get('REDIS_PASSWORD', '');
                if ($pwd !== '') { $opt['password'] = $pwd; }
                self::$c = new \Predis\Client($opt);
            }
        }
        return self::$c;
    }

    public static function ping(): bool
    {
        try { return (string)self::client()->ping() === 'PONG'; }
        catch (\Throwable $e) { return false; }
    }

    public static function streamMaxlen(): int
    {
        $v = (int)Env::get('STREAM_MAXLEN', '500');
        return max(50, min(2000, $v));
    }

    public static function streamAddUserEvent(int $userId, array $event): void
    {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::client()->executeRaw([
            'XADD', "upd:stream:$userId",
            'MAXLEN', '~', (string)self::streamMaxlen(),
            '*', 'e', $json
        ]);
    }

    public static function ttlPresence(): int { 
        return max(10, ConfigHelper::getInt('REDIS_TTL_PRESENCE', 60)); 
    }
    
    public static function ttlTyping(): int { 
        return max(5, ConfigHelper::getInt('REDIS_TTL_TYPING', 15)); 
    }
    
    public static function ttlCursor(): int { 
        return max(60, ConfigHelper::getInt('REDIS_TTL_CURSOR', 86400)); 
    }
    
    public static function ttlIdem(): int { 
        return max(60, ConfigHelper::getInt('REDIS_TTL_IDEM', 86400)); 
    }
    
    public static function ttlLpSem(): int { 
        return max(5, ConfigHelper::getInt('REDIS_TTL_LP_SEMAPHORE', 35)); 
    }

    public static function touchPresence(int $userId): void
    {
        self::client()->setex("online:$userId", self::ttlPresence(), '1');
    }

    public static function addTyping(int $chatId, int $userId): void
    {
        $key = "typing:$chatId";
        $r = self::client();
        $r->sadd($key, (string)$userId);
        $r->expire($key, self::ttlTyping());
    }

    public static function safeGet(string $key, int $maxSize = 1048576): mixed 
    {
        try {
            $client = self::client();
            $size = $client->strlen($key);
            if ($size > $maxSize) {
                \Messa\Core\Logger::warning('Redis key too large', ['key' => $key, 'size' => $size]);
                return null;
            }
            return $client->get($key);
        } catch (\Throwable $e) {
            \Messa\Core\Logger::error('Redis safeGet failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public static function memoryOptimizedSet(string $key, $value, int $ttl = 3600): bool
    {
        try {            
            // Проверяем размер данных
            $size = strlen(serialize($value));
            if ($size > 524288) { // 512KB max
                \Messa\Core\Logger::warning('Redis value too large', ['key' => $key, 'size' => $size]);
                return false;
            }
            
            self::client()->setex($key, $ttl, $value);
            return true; // ← ИСПРАВЛЕНИЕ
        } catch (\Throwable $e) {
            \Messa\Core\Logger::error('Redis set failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}