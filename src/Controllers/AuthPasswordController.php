<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\RateLimitedException;
use Messa\Support\Validators;
use Messa\Repos\UsersRepository;
use Messa\Services\SessionService;
use Messa\Services\JwtService;
use Messa\Services\AuthAggregateService;
use Messa\Core\ConfigHelper;
use Messa\Core\Redis as R;
use Messa\Core\Logger;

final class AuthPasswordController extends BaseController
{
    public function login(Request $req, Response $res): array
    {
        $b = $req->json();
        $login = (string)($b['login'] ?? '');
        $password = (string)($b['password'] ?? '');
        
        // На логине проверяем только валидность логина и непустоту пароля
        if (!$this->validateLogin($login) || $password === '') {
            throw new UnprocessableException('Неверные параметры');
        }
        
        $deviceUuid = isset($b['device_uuid']) ? (string)$b['device_uuid'] : null;
        $deviceLabel = isset($b['device_label']) ? (string)$b['device_label'] : null;

        // Нормализация device_* (ограничения длины/алфавита)
        if ($deviceUuid !== null) {
            $deviceUuid = preg_replace('/[^A-Za-z0-9._:-]/', '', $deviceUuid);
            $deviceUuid = mb_substr($deviceUuid, 0, 64, 'UTF-8');
        }
        if ($deviceLabel !== null) {
            $deviceLabel = trim($deviceLabel);
            $deviceLabel = preg_replace('/[\r\n]+/u', ' ', $deviceLabel);
            $deviceLabel = mb_substr($deviceLabel, 0, 64, 'UTF-8');
        }

        // Локальный анти-bruteforce: (login+IP) + глобально per-IP
        $maxAttempts = (int)ConfigHelper::getInt('AUTH_LOGIN_MAX_ATTEMPTS', 7);
        $ttl         = (int)ConfigHelper::getInt('AUTH_LOGIN_TTL', 300); // сек

        $ip = $req->ip() ?? (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        // нормализуем префикс: всегда ровно один ":" на конце
        $prefix = rtrim((string)ConfigHelper::getString('REDIS_PREFIX', 'messa'), ':') . ':';

        $keyLoginIp = $prefix . 'auth:pwd:rl:' . hash('sha256', mb_strtolower($login, 'UTF-8') . '|' . $ip);
        $keyIpHour  = $prefix . 'auth:pwd:ip:h:' . hash('sha256', $ip);
        $keyIpMin   = $prefix . 'auth:pwd:ip:m:' . hash('sha256', $ip);

        $hourLimit = (int)ConfigHelper::getInt('AUTH_LOGIN_IP_HOURLY', 120);
        $minLimit  = (int)ConfigHelper::getInt('AUTH_LOGIN_IP_MINUTELY', 20);
        
        try {
            $r = R::client();
        
            // лимит для (login+ip)
            $attempts = (int)$r->incr($keyLoginIp);
            if ($attempts === 1) { $r->expire($keyLoginIp, $ttl); }
        
            // глобально per-IP: час/минута
            $h = (int)$r->incr($keyIpHour);
            if ($h === 1) { $r->expire($keyIpHour, 3600); }
        
            $m = (int)$r->incr($keyIpMin);
            if ($m === 1) { $r->expire($keyIpMin, 60); }
        
            
            $over = ($attempts > $maxAttempts) || ($h > $hourLimit) || ($m > $minLimit);
            if ($over) {
                Logger::warning('Brute force attempt detected', [
                    'login' => $login,
                    'ip' => $ip,
                    'attempts' => $attempts,
                    'hour' => $h,
                    'minute' => $m
                ]);
                // берём максимальный TTL из задействованных бакетов
                $retrySec = $ttl;
                try {
                    $t1 = (int)R::client()->ttl($keyLoginIp);
                    $t2 = (int)R::client()->ttl($keyIpHour);
                    $t3 = (int)R::client()->ttl($keyIpMin);
                    $cands = array_filter([$t1, $t2, $t3], fn($v) => $v > 0);
                    if (!empty($cands)) {
                        $retrySec = max($cands);
                    }
                } catch (\Throwable $e2) {}
                $res->header('Retry-After', (string)max(1, $retrySec));
                throw new RateLimitedException('too_many_attempts');
            }
            
        } catch (\Throwable $e) {
            // В случае проблем с Redis продолжаем без лимитов, но замедляем попытку
            Logger::warning('Redis error during login rate limiting', [
                'error' => $e->getMessage()
            ]);
            try {
                usleep(random_int(50000, 150000)); // 50–150 мс
            } catch (\Throwable $e2) {
                // игнорируем проблемы с random_int/usleep
            }
        }

        $usersRepo = new UsersRepository();
        $user = $usersRepo->findByLogin($login);
        
        // ЗАЩИТА ОТ TIMING ATTACKS - всегда выполняем password_verify
        $passwordValid = false;
        $userExists = false;

        if ($user) {
            $userExists = true;
            $passwordValid = password_verify($password, (string)$user['password_hash']);
        }

        // Всегда выполняем верификацию пароля для выравнивания времени
        if (!$userExists) {
            // Хешируем фиктивный пароль с аналогичными параметрами Argon2id
            $dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$' . 
                base64_encode(random_bytes(16)) . '$' . 
                base64_encode(random_bytes(32));
            password_verify($password, $dummyHash);
        }

        if (!$userExists || !$passwordValid) {
            throw new UnauthorizedException('Неверный логин или пароль');
        }

        $usersRepo->updateLastLogin((int)$user['id']);

        $sessionService = new SessionService();
        $clientIp = $req->ip() ?? ($_SERVER['REMOTE_ADDR'] ?? null);

        $platform = $this->determinePlatform($_SERVER['HTTP_USER_AGENT'] ?? null);

        $jwtService = new JwtService();
        $refreshTtl = $jwtService->getRefreshTtl();
        $created = $sessionService->createSession(
            (int)$user['id'], 
            $deviceUuid,
            (int)$deviceLabel,
            $platform,
            $refreshTtl
        );

        // Логируем информацию о активных сессиях
        $activeSessionsCount = $sessionService->getActiveSessionsCount((int)$user['id']);
        Logger::info('User login completed', [
            'user_id' => $user['id'],
            'active_sessions' => $activeSessionsCount,
            'device_id' => $platform
        ]);

        $accessToken = $jwtService->issueAccess([
            'id' => (int)$user['id'],
            'login' => (string)$user['login'],
            'role' => (string)($user['role'] ?? 'user')
        ]);

        // Успех: сбросить счётчик попыток для конкретной связки (login+ip)
        try { R::client()->del($keyLoginIp); } catch (\Throwable $e) {}

        return (new AuthAggregateService())->build(
            (int)$user['id'],
            $accessToken['token'],
            (int)$accessToken['exp'],
            (string)$created['refresh'],
            (string)$created['expires_at'],
        );
    }

    public function refresh(Request $req, Response $res): array
    {
        $b = $req->json();
        $refreshToken = (string)($b['refresh_token'] ?? '');
        
        // ПРОВЕРКА ДЛИНЫ REFRESH TOKEN
        if ($refreshToken === '' || strlen($refreshToken) > 512) {
            throw new UnprocessableException('refresh_token обязателен');
        }

        // Небольшой rate-limit per-IP для /refresh
        $ip = $req->ip() ?? (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $prefix = rtrim((string)ConfigHelper::getString('REDIS_PREFIX', 'messa'), ':') . ':';
        $keyIp = $prefix . 'auth:refresh:ip:' . hash('sha256', $ip);
        $minLimit = (int)ConfigHelper::getInt('AUTH_REFRESH_IP_MINUTELY', 60);

        try {
            $r = R::client();
            $cnt = (int)$r->incr($keyIp);
            if ($cnt === 1) {
                $r->expire($keyIp, 60);
            }
            if ($cnt > $minLimit) {
                $res->header('Retry-After', '60');
                throw new RateLimitedException('too_many_attempts');
            }
        } catch (\Throwable $e) {
            // При проблемах с Redis продолжаем без лимита
        }

        $sessionService = new SessionService();
        $session = $sessionService->verifyRefresh($refreshToken);
        
        if (!$session) {
            Logger::warning('Invalid refresh token attempt', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            throw new UnauthorizedException('Недействительный refresh_token');
        }

        try {
            $jwtService = new JwtService();
            $refreshTtl = $jwtService->getRefreshTtl();
            $rotated = $sessionService->rotateRefresh((int)$session['sid'], $refreshTtl);

            $accessToken = $jwtService->issueAccess([
                'id' => (int)$session['user_id'],
                'login' => (string)$session['login'],
                'role' => (string)($session['role'] ?? 'user')
            ]);

            return [
                'access_token' => $accessToken['token'],
                'access_exp' => $accessToken['exp'],
                'refresh_token' => $rotated['refresh'],
                'refresh_exp' => $rotated['expires_at'],
                'token_type' => 'Bearer'
            ];
        } catch (\Throwable $e) {
            Logger::error('Refresh token processing failed', [
                'error' => $e->getMessage(),
                'session_id' => $session['sid'] ?? 'unknown'
            ]);
            throw new UnauthorizedException('Ошибка обновления токена');
        }
    }

    public function logout(Request $req, Response $res): array
    {
        // предотвращаем двойную обработку по ретраям клиента
        $this->enforceIdempotency($req, 'logout', 120);
        $b = $req->json();
        $refreshToken = (string)($b['refresh_token'] ?? '');
        
        if ($refreshToken === '' || strlen($refreshToken) > 512) {
            return ['status' => 'ok'];
        }

        $sessionService = new SessionService();
        $session = $sessionService->verifyRefresh($refreshToken);
        
        if ($session) {
            $sessionService->revokeById((int)$session['user_id'], (int)$session['sid']);
            Logger::info('User session revoked', [
                'user_id' => $session['user_id'],
                'session_id' => $session['sid']
            ]);
        }
        
        return ['status' => 'ok'];
    }

    private function determinePlatform(?string $userAgent): string
    {
        if (!$userAgent) return 'web';
        
        $ua = strtolower($userAgent);
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'ios';
        if (strpos($ua, 'android') !== false) return 'android';
        if (strpos($ua, 'windows') !== false || strpos($ua, 'macintosh') !== false) return 'desktop';
        
        return 'web';
    }

    protected function sanitizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }
        
        // Ограничиваем длину и удаляем небезопасные символы
        $clean = preg_replace('/[^\x20-\x7E]/', '', $userAgent);
        return mb_substr($clean, 0, 255, 'UTF-8');
    }
}