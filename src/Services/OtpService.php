<?php
declare(strict_types=1);
namespace Messa\Services;

use Messa\Core\ConfigHelper;
use Messa\Core\Redis;
use Messa\Core\Logger;

final class OtpService
{
    private const PURPOSES = ['reg','reset','2fa'];
    private string $secret;
    private int $ttlSec;
    private int $maxAttempts;
    private string $prefix;

    /** Lua verify: KEYS[1]=key, ARGV[1]=provided_hash_hex, ARGV[2]=max_attempts
     * Returns: {ok(1/0), ttl_ms_remaining, attempts_used, blocked(1/0)}
     */
    private const LUA_VERIFY = <<<'LUA'
local key = KEYS[1]
local provided = ARGV[1]
local maxatt = tonumber(ARGV[2])
local h = redis.call('HGET', key, 'hash')
if not h then
  return {0, -1, 0, 0}
end
local attempts = tonumber(redis.call('HGET', key, 'attempts') or '0')
if h == provided then
  redis.call('DEL', key)
  return {1, 0, attempts, 0}
else
  attempts = attempts + 1
  if attempts >= maxatt then
    redis.call('DEL', key)
    return {0, 0, attempts, 1}
  else
    redis.call('HSET', key, 'attempts', attempts)
    local ttl = redis.call('PTTL', key)
    if ttl < 0 then ttl = 0 end
    return {0, ttl, attempts, 0}
  end
end
LUA;

    public function __construct(
        ?string $secret = null,
        ?int $ttlSec = null,
        ?int $maxAttempts = null,
        ?string $prefix = null
    ) {
        $this->secret = $secret ?? (string)ConfigHelper::getString('OTP_SECRET', '');
        if ($this->secret === '') throw new \RuntimeException('OTP_SECRET is not set');
        $this->ttlSec = $ttlSec ?? (int)(ConfigHelper::getInt('OTP_TTL_SEC', 600) ?? 600);
        $this->maxAttempts = $maxAttempts ?? (int)(ConfigHelper::getInt('OTP_MAX_ATTEMPTS', 5) ?? 5);
        $this->prefix = (string)($prefix ?? (ConfigHelper::getString('REDIS_PREFIX', 'messa:')));
    }

    /** Создать новый код (6-значный), сохранить только HMAC-хэш, установить TTL. */
    public function start(string $purpose, string $login): array
    {
        $purpose = $this->validatePurpose($purpose);
        $loginLower = $this->normalizeLogin($login);
        $code = $this->generateSecureCode();
        $hash = $this->hashCode($purpose, $loginLower, $code);
        $key = $this->key($purpose, $loginLower);
        $c = Redis::client();
        // Перезаписываем, <=1 активная заявка — новая замещает старую
        $c->hset($key, 'hash', $hash);
        $c->hset($key, 'attempts', 0);
        $c->pexpire($key, $this->ttlSec * 1000);
        Logger::debug('otp.start', ['purpose' => $purpose, 'login' => $loginLower, 'ttl' => $this->ttlSec]);
        return [
            'code' => $code, // отдаём вызывающему уровню (дальше — только в Telegram)
            'ttl_sec' => $this->ttlSec,
            'max_attempts' => $this->maxAttempts,
        ];
    }

    /** Проверить код. Возвращает массив статуса и метаданных. */
    public function verify(string $purpose, string $login, string $code): array
    {
        $purpose = $this->validatePurpose($purpose);
        $loginLower = $this->normalizeLogin($login);
        $provided = $this->hashCode($purpose, $loginLower, $code);
        $key = $this->key($purpose, $loginLower);
        $c = Redis::client();
        try {
            /** @var array<int,mixed> $r */
            $r = $c->eval(self::LUA_VERIFY, 1, $key, $provided, (string)$this->maxAttempts);
            $ok = (int)($r[0] ?? 0) === 1;
            $ttl = (int)($r[1] ?? -1);
            $used = (int)($r[2] ?? 0);
            $blocked = (int)($r[3] ?? 0) === 1;
            $attemptsLeft = $this->maxAttempts - $used;
            if ($attemptsLeft < 0) {
                $attemptsLeft = 0;
            }
            if ($blocked) {
                $attemptsLeft = 0;
            }
            if (!$ok || $blocked || $ttl === -1) {
                Logger::info('otp.verify', [
                    'purpose' => $purpose,
                    'login'   => $loginLower,
                    'ok'      => $ok,
                    'attempts'=> $used,
                    'blocked' => $blocked,
                    'ttl'     => $ttl,
                ]);
            }
            return [
                'ok' => $ok,
                'attempts_used' => $used,
                'attempts_left' => $attemptsLeft,
                'blocked' => $blocked,
                'expired' => ($ttl === -1),
            ];
        } catch (\Throwable $e) {
            Logger::error('otp.verify.error', ['e' => $e->getMessage()]);
            return ['ok' => false, 'attempts_used' => 0, 'attempts_left' => 0, 'blocked' => false, 'expired' => true];
        }
    }

    private function hashCode(string $purpose, string $loginLower, string $code): string
    {
        // HMAC-SHA256(purpose|login|code, secret), hexlower
        $msg = $purpose . '|' . $loginLower . '|' . $code;
        return hash_hmac('sha256', $msg, $this->secret, false);
    }

    private function key(string $purpose, string $loginLower): string
    {
        return $this->prefix . 'otp:' . $purpose . ':' . $loginLower;
    }

    private function normalizeLogin(string $login): string
    {
        $login = trim($login);
        // Разрешим [a-z0-9._-], как минимум; валидация глубже будет в слое валидации эндпоинтов
        return mb_strtolower($login, 'UTF-8');
    }

    private function validatePurpose(string $purpose): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('invalid purpose');
        }
        return $purpose;
    }

    private function generateSecureCode(): string
    {
        // 6 цифр вместо 4
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function verifyWithDelay(string $purpose, string $login, string $code): array
    {
        // Искусственная задержка 1-2 секунды
        usleep(random_int(1000000, 2000000));
        return $this->verify($purpose, $login, $code);
    }
}
