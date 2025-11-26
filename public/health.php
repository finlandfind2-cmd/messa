<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Core/Bootstrap.php';

use Messa\Core\Bootstrap;
use Messa\Services\CacheService;

Bootstrap::init();

header('Content-Type: application/json; charset=utf-8');

function checkDatabase(): bool
{
    try {
        \Messa\Core\Db::pdo()->query('SELECT 1');
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function checkRedis(): bool
{
    try {
        return \Messa\Core\Redis::ping();
    } catch (\Throwable $e) {
        return false;
    }
}

function checkS3(): bool
{
    try {
        return \Messa\Core\S3::isAlive();
    } catch (\Throwable $e) {
        return false;
    }
}

// Кэшируем health check на 30 секунд
$cache = new CacheService();
$healthStatus = $cache->get('health:status', function() {
    return [
        'database' => checkDatabase(),
        'redis' => checkRedis(),
        's3' => checkS3(),
        'timestamp' => time(),
    ];
}, 30);

$allHealthy = $healthStatus['database'] && $healthStatus['redis'] && $healthStatus['s3'];
http_response_code($allHealthy ? 200 : 503);

echo json_encode([
    'status' => $allHealthy ? 'healthy' : 'degraded',
    'services' => $healthStatus,
    'timestamp' => $healthStatus['timestamp']
], JSON_UNESCAPED_SLASHES);