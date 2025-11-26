<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Core\Config;
use Messa\Core\Db;
use Messa\Core\Redis as RedisCore;
use Messa\Core\Logger;
use Messa\Core\ConfigHelper;
use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\RateLimitedException;
use Messa\Services\OtpService;
use Messa\Services\TelegramBot;
use Messa\Services\SessionService;
use PDO;

final class PasswordResetController extends BaseController
{
    /** POST /v1/auth/password/forgot/start — всегда {status:ok} */
    public function start(Request $req, Response $res): array
    {
        // предотвращаем повторную отправку по ретраям
        $this->enforceIdempotency($req, 'pwdreset-start', 120);
        $b = $req->json();
        $login = (string)($b['login'] ?? '');
        
        // Пер-IP лимит на старт
        $this->limitIp(
            ConfigHelper::getInt('RESET_STARTS_PER_HOUR_IP', 10), 
            'reset-start'
        );

        // Валидируем формат, но отвечаем нейтрально в любом случае
        if (!$this->validateLogin($login)) {
            return ['status' => 'ok'];
        }
        
        // Пер-логин лимит запусков/час
        $maxStarts = ConfigHelper::getInt('RESET_STARTS_PER_HOUR', 3);
        try {
            $r = RedisCore::client();
            $key = ConfigHelper::getString('REDIS_PREFIX', 'messa:') . 'rl:resetstart:' . mb_strtolower($login,'UTF-8');
            $cnt = (int)$r->incr($key);
            if ($cnt === 1) { 
                $r->expire($key, 3600); 
            }
            if ($cnt > $maxStarts) {
                return ['status' => 'ok']; // нейтрально, без отправки OTP
            }
        } catch (\Throwable $e) { /* мягкая деградация */ }

        // Если пользователь и telegram_id существуют — отправим код
        try {
            $pdo = Db::pdo();
            $stmt = $pdo->prepare("SELECT id, telegram_id FROM users WHERE login_lower = LOWER(?) AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['telegram_id'])) {
                $otp = new OtpService();
                $info = $otp->start('reset', $login);
                
                // привяжем telegram_id к OTP-ключу
                try {
                    $r = RedisCore::client();
                    $r->hset(ConfigHelper::getString('REDIS_PREFIX','messa:').'otp:reset:'.mb_strtolower($login,'UTF-8'), 'tgid', (string)$row['telegram_id']);
                } catch (\Throwable) {}
                
                try {
                    $tgid = (int)$row['telegram_id'];
                    if ($tgid > 0) {
                        (new TelegramBot())->sendMessage($tgid, "Код сброса пароля: *{$info['code']}*\nДействует {$info['ttl_sec']} сек.", ['parse_mode'=>'Markdown']);
                    }
                } catch (\Throwable $e) {
                    Logger::error('tg.resetcode.fail', ['e'=>$e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('reset.start.db', ['e'=>$e->getMessage()]);
        }
        
        return ['status' => 'ok'];
    }

    /** POST /v1/auth/password/forgot/confirm */
    public function confirm(Request $req, Response $res): array
    {
        // предотвращаем повторную обработку по ретраям
        $this->enforceIdempotency($req, 'pwdreset-confirm', 120);
        $b = $req->json();
        $login = (string)($b['login'] ?? '');
        $code = (string)($b['code'] ?? '');
        $newPassword = isset($b['new_password']) ? (string)$b['new_password'] : null;
        $serverGenerate = (bool)($b['server_generate'] ?? false);

        // Пер-IP лимит на подтверждения
        $this->limitIp(
            ConfigHelper::getInt('RESET_CONFIRM_PER_HOUR_IP', 60), 
            'reset-confirm'
        );

        if (!$serverGenerate) {
            if ($newPassword === null) {
                throw new UnprocessableException('Пароль не соответствует требованиям');
            }
            $this->validatePassword($newPassword); // общая политика (Validators::passwordStrong)
        }

        // Пер-логин лимит подтверждений/час (троттлинг брута OTP)
        try {
            $maxConfirm = ConfigHelper::getInt('RESET_CONFIRM_PER_HOUR', 12);
            $r = RedisCore::client();
            $prefix = ConfigHelper::getString('REDIS_PREFIX','messa:');
            $key = $prefix . 'rl:resetconfirm:' . mb_strtolower($login,'UTF-8');
            $cnt = (int)$r->incr($key);
            if ($cnt === 1) {
                $r->expire($key, 3600);
            }
            if ($cnt > $maxConfirm) {
                throw new RateLimitedException('too_many_attempts_confirm');
            }
        } catch (\Messa\Http\Exceptions\RateLimitedException $e) { throw $e; }
        catch (\Throwable $e) { /* мягкая деградация */ }

        if (!$this->validateLogin($login) || !preg_match('/^\d{6}$/', $code)) {
            throw new UnprocessableException('Неверные параметры');
        }
        
        if (!$serverGenerate && ($newPassword === null || !$this->validatePassword($newPassword))) {
            throw new UnprocessableException('Пароль не соответствует требованиям (мин. 10 симв.)');
        }

        // проверка соответствия telegram_id в ключе (если есть)
        try {
            $r = RedisCore::client();
            $k = ConfigHelper::getString('REDIS_PREFIX','messa:').'otp:reset:'.mb_strtolower($login,'UTF-8');
            $tgid = $r->hget($k, 'tgid');
            if (!$tgid) {
                // нейтрально: если Redis недоступен/нет tgid — позволяем продолжить
            }
        } catch (\Throwable $e) { /* мягко */ }

        // заранее прочитаем возможный tgid, сохранённый при старте
        $tgidFromStart = null;
        try {
            $r = RedisCore::client();
            $k = ConfigHelper::getString('REDIS_PREFIX','messa:').'otp:reset:'.mb_strtolower($login,'UTF-8');
            $tgidFromStart = $r->hget($k, 'tgid') ?: null;
        } catch (\Throwable $e) { /* мягко */ }

        $otp = new OtpService();
        $v = $otp->verify('reset', $login, $code);
        if (!$v['ok']) {
            throw new UnprocessableException('Неверный или просроченный код');
        }

        // применяем смену пароля и ревокацию всех сессий
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT id, telegram_id FROM users WHERE login_lower = LOWER(?) AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$login]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            // крайне редкий случай (несогласованность); отвечаем нейтрально
            return ['status' => 'ok'];
        }
        
        $userId = (int)$u['id'];

        // Если при старте мы привязывали tgid и у пользователя есть telegram_id,
        // проверим соответствие каналов — иначе ошибка как при неверном коде.
        if ($tgidFromStart !== null && !empty($u['telegram_id'])) {
            if ((string)$tgidFromStart !== (string)$u['telegram_id']) {
                // без утечек деталей
                throw new UnprocessableException('Неверный или просроченный код');
            }
        }

        // Далее — смена пароля и ревокация сессий
        $passwordToSet = $serverGenerate ? $this->generateStrongPassword() : (string)$newPassword;
        $hash = $this->argon2id($passwordToSet);
        $usersRepo = new \Messa\Repos\UsersRepository();
        $pdo->beginTransaction();
        try {
            $mustChange = $serverGenerate ? 1 : 0;
            $usersRepo->updatePasswordWithFlag($userId, $hash, (bool)$mustChange);

            (new SessionService())->revokeAllByUserId($userId);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // если генерировали пароль — отправим ТОЛЬКО в Telegram
        if ($serverGenerate && !empty($u['telegram_id'])) {
            try {
                $tgid2 = (int)$u['telegram_id'];
                 if ($tgid2 > 0) {
                    (new TelegramBot())->sendMessage($tgid2, "Ваш новый пароль: `{$passwordToSet}`\nПожалуйста, смените его после входа.", ['parse_mode'=>'Markdown']);
                 }
            } catch (\Throwable $e) { 
                Logger::error('tg.resetpass.fail', ['e'=>$e->getMessage()]); 
            }
        }

        return ['status' => 'ok'];
    }

    private function argon2id(string $password): string
    {
        $opts = ['memory_cost' => 1<<16, 'time_cost' => 3, 'threads' => 1];
        $hash = password_hash($password, PASSWORD_ARGON2ID, $opts);
        if ($hash === false) throw new \RuntimeException('Argon2id not available');
        return $hash;
    }

    private function generateStrongPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*_-+=';
        $len = 20; 
        $pw = '';
        
        for ($i=0; $i<$len; $i++) { 
            $pw .= $alphabet[random_int(0, strlen($alphabet)-1)]; 
        }
        
        return $pw;
    }

    private function limitIp(int $maxPerHour, string $scope): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $r = RedisCore::client();
            $key = ConfigHelper::getString('REDIS_PREFIX','messa:') . "rl:{$scope}:ip:" . $ip;
            $cnt = (int)$r->incr($key);
            if ($cnt === 1) { 
                $r->expire($key, 3600); 
            }
            if ($cnt > $maxPerHour) {
                // Мягкая нейтральная деградация
                throw new \RuntimeException('ip limited');
            }
        } catch (\Throwable $e) {
            // не прерываем поток (нейтральность важнее)
        }
    }
}