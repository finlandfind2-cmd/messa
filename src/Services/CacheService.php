<?php
declare(strict_types=1);
namespace Messa\Services;

use Messa\Core\Redis;
use Messa\Core\Logger;

final class CacheService
{
    private static array $memoryCache = [];
    private \Predis\Client $redis;
    private bool $redisAvailable;

    public function __construct()
    {
        $this->redis = Redis::client();
        $this->redisAvailable = true;
    }

    /**
     * Получить данные из кэша или вычислить и сохранить
     */
    public function get(string $key, callable $callback, int $ttl = 3600): mixed
    {
        // Пытаемся получить из memory cache (в рамках одного запроса)
        if (array_key_exists($key, self::$memoryCache)) {
            return self::$memoryCache[$key];
        }

        // Пытаемся получить из Redis
        if ($this->redisAvailable) {
            try {
                $cached = $this->redis->get($key);
                if ($cached !== null) {
                    $data = unserialize($cached);
                    self::$memoryCache[$key] = $data;
                    return $data;
                }
            } catch (\Throwable $e) {
                $this->redisAvailable = false;
                Logger::warning('Redis cache get error', [
                    'error' => $e->getMessage(),
                    'key' => $key
                ]);
            }
        }

        // Если нет в кэше, вызываем callback и сохраняем
        $data = $callback();

        // Сохраняем в memory cache
        self::$memoryCache[$key] = $data;

        // Сохраняем в Redis, если он доступен
        if ($this->redisAvailable) {
            try {
                $this->redis->setex($key, $ttl, serialize($data));
            } catch (\Throwable $e) {
                $this->redisAvailable = false;
                Logger::warning('Redis cache set error', [
                    'error' => $e->getMessage(),
                    'key' => $key
                ]);
            }
        }

        return $data;
    }

    /**
     * Удалить данные из кэша
     */
    public function delete(string $key): void
    {
        // Удаляем из memory cache
        unset(self::$memoryCache[$key]);

        // Удаляем из Redis
        if ($this->redisAvailable) {
            try {
                $this->redis->del($key);
            } catch (\Throwable $e) {
                $this->redisAvailable = false;
                Logger::warning('Redis cache delete error', [
                    'error' => $e->getMessage(),
                    'key' => $key
                ]);
            }
        }
    }

    /**
     * Очистить memory cache (полезно между тестами)
     */
    public function clearMemory(): void
    {
        self::$memoryCache = [];
    }

    /**
     * Проверить доступность Redis
     */
    public function isRedisAvailable(): bool
    {
        return $this->redisAvailable;
    }
}