<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\RateLimitedException;
use Messa\Http\Exceptions\ForbiddenException;
use Messa\Support\Validators;

abstract class BaseController
{
    protected function requireAuth(Request $req): int
    {
        $userId = $req->getUserId();
        if ($userId <= 0) {
            throw new UnauthorizedException('Authentication required');
        }
        return $userId;
    }

    protected function requireAdmin(Request $req): int
    {
        $userId = $this->requireAuth($req);
        $role = $req->getUserRole();
        
        if (!in_array($role, ['admin', 'owner'], true)) {
            throw new ForbiddenException('Admin access required');
        }
        
        return $userId;
    }

    protected function validateCsrf(Request $req): void
    {
        $csrfToken = $req->header('X-CSRF-Token') ?? ($req->json(true)['csrf_token'] ?? '');
        if ($csrfToken === '' || !preg_match('/^[a-f0-9]{32}$/i', $csrfToken)) {
            throw new \Messa\Http\Exceptions\ForbiddenException('Invalid CSRF token');
        }
        $prefix = rtrim((string)\Messa\Core\ConfigHelper::getString('REDIS_PREFIX','messa'), ':').':';
        $key = $prefix.'csrf:'.$csrfToken;
        $r = \Messa\Core\Redis::client();
        $val = $r->get($key);
        if ($val === null) {
            throw new \Messa\Http\Exceptions\ForbiddenException('CSRF token expired or not found');
        }
        // одноразовый
        $r->del($key);
    }

    protected function getCurrentUser(Request $req): array
    {
        $this->requireAuth($req);
        return [
            'id' => (int)($req->user['id'] ?? 0),
            'login' => (string)($req->user['login'] ?? ''),
            'role' => (string)($req->user['role'] ?? 'user')
        ];
    }

    protected function checkBruteForce(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $current = (int)$redis->get($key);
            
            if ($current >= $maxAttempts) {
                throw new RateLimitedException('Too many attempts');
            }
            
            $redis->multi();
            $redis->incr($key);
            $redis->expire($key, $windowSeconds);
            $redis->exec();
        } catch (\Throwable $e) {
            // При проблемах с Redis не применяем брутфорс-лимит вместо того, чтобы ронять запрос
            return true;
        }

        return true;
    }

    protected function validatePaginationParams(Request $req, int $maxLimit = 100): array
    {
        $limit = max(1, min($maxLimit, (int)($req->query('limit') ?? 50)));
        $after = $this->asIntOrNull($req->query('after'));
        
        return [$limit, $after];
    }

    protected function asIntOrNull($value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!preg_match('/^\d+$/', (string)$value)) return null;
        return (int)$value;
    }

    protected function validateLogin(string $login): bool
    {
        return Validators::login($login);
    }

    protected function validatePassword(string $password): bool
    {
        return mb_strlen($password, 'UTF-8') >= 10;
    }

    protected function getJsonInput(string $raw, bool $assoc = true): array
    {
        $raw = $raw ?? file_get_contents('php://input');
        if ($raw === '') {
            throw new UnprocessableException('Invalid request body');
        }
        $data = json_decode($raw, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new UnprocessableException('Invalid JSON: ' . json_last_error_msg());
        }
        // Базовая валидация: фильтр ключей, если нужно
        return $data;
    }

    /** Простая идемпотентность: Idempotency-Key → Redis NX + TTL */
    protected function enforceIdempotency(Request $req, string $scope, int $ttlSec = 120): void
    {
        $key = trim((string)($req->header('Idempotency-Key') ?? ''));
        if ($key === '' || !preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $key)) {
            return;
        }
    
        try {
            $r = \Messa\Core\Redis::client();
            $prefix = rtrim((string)\Messa\Core\ConfigHelper::getString('REDIS_PREFIX','messa'), ':') . ':';
            $rk = $prefix . 'idem:' . $scope . ':' . sha1($key);
            $ok = $r->set($rk, '1', ['nx', 'ex' => max(1, $ttlSec)]);
            if ($ok !== true && $ok !== 'OK') {
                throw new RateLimitedException('Duplicate request');
            }
        } catch (\Throwable $e) {
            // При недоступном Redis просто не используем идемпотентность
            return;
        }
    }

    /** Белый список JSON-ключей (локальная жёсткость контрактов) */
    protected function arrayWhitelist(array $json, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $json)) { $out[$k] = $json[$k]; }
        }
        return $out;
    }
}