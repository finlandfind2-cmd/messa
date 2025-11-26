<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Core\Db;
use Messa\Core\Logger;
use Messa\Core\Transaction;
use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\ConflictException;
use Messa\Services\OtpService;
use Messa\Services\TelegramBot;
use Messa\Services\AvatarService;
use Messa\Services\Crypto\UserKeyService;
use Messa\Services\JwtService;
use Messa\Services\SessionService;
use Messa\Services\AuthAggregateService;
use Messa\Repos\UsersRepository;

final class AuthTelegramController extends BaseController
{
    public function checkLogin(Request $req, Response $res): array
    {
        $body = $req->json();
        $login = (string)($body['login'] ?? '');
        
        if (!$this->validateLogin($login)) {
            throw new UnprocessableException('Неверный формат логина', ['rules' => '3-32 [a-z0-9._-]']);
        }
        
        // Проверяем, не занят ли логин
        $usersRepo = new UsersRepository();
        $existingUser = $usersRepo->findByLogin($login);
        
        if ($existingUser) {
            throw new ConflictException('Логин уже занят');
        }
        
        return ['status' => 'ok'];
    }

    public function telegramStart(Request $req, Response $res): array
    {
        try {
            $b = $req->json();
            $login = (string)($b['login'] ?? '');
            
            if (!$this->validateLogin($login)) {
                return ['status' => 'ok']; // Нейтральный ответ для безопасности
            }

            // Проверяем, не занят ли логин
            $usersRepo = new UsersRepository();
            $existingUser = $usersRepo->findByLogin($login);
            if ($existingUser) {
                return ['status' => 'ok']; // Нейтральный ответ
            }

            // Rate limiting and ip logging
            $ip = $req->ip() ?? (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $this->applyOtpRateLimit($login, $ip);

            // Временно резервируем логин на 10 минут
            $this->reserveLogin($login);

            return [
                'status' => 'ok',
                'message' => 'Логин зарезервирован. Перейдите в бота @iammessa_bot и отправьте команду /reg ' . $login
            ];
        } catch (\Throwable $e) {
            Logger::error('Registration failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => 'Registration service unavailable'];
        }
    }

    public function telegramConfirm(Request $req, Response $res): array
    {
        $b = $req->json();
        $login = (string)($b['login'] ?? '');
        $code = (string)($b['code'] ?? '');
        
        // OtpService генерирует 6-значные коды, валидируем 6 цифр
        if (!$this->validateLogin($login) || !preg_match('/^\d{6}$/', $code)) {
            throw new UnprocessableException('Неверные параметры');
        }

        // Проверяем, зарезервирован ли логин
        if (!$this->isLoginReserved($login)) {
            throw new UnprocessableException('Регистрация не начата или логин уже занят');
        }

        $otp = new OtpService();
        $verification = $otp->verify('reg', $login, $code);
        
        if (!$verification['ok']) {
            throw new UnprocessableException('Неверный или просроченный код');
        }

        // Получаем данные Telegram пользователя из Redis
        $telegramData = $this->getTelegramData($login);
        if (!$telegramData || !isset($telegramData['telegram_id'])) {
            throw new UnprocessableException('Данные Telegram не найдены. Пожалуйста, начните регистрацию заново.');
        }

        $telegramId = $telegramData['telegram_id'];
        $firstName = $telegramData['first_name'] ?? '';
        $lastName = $telegramData['last_name'] ?? '';

        // Создаем пользователя в транзакции
        return Transaction::run(function() use ($login, $telegramId, $firstName, $lastName, $telegramData) {
            $password = $this->generateStrongPassword();
            $passwordHash = $this->argon2id($password);

            $usersRepo = new UsersRepository();
            
            // Проверяем, не существует ли уже пользователь (двойная проверка)
            $existingUser = $usersRepo->findByLogin($login);
            if ($existingUser) {
                throw new ConflictException('Логин уже занят');
            }

            // Проверяем, не привязан ли Telegram ID к другому аккаунту
            $existingByTelegram = $usersRepo->findByTelegramId($telegramId);
            if ($existingByTelegram) {
                throw new ConflictException('Telegram аккаунт уже привязан к другому пользователю');
            }

            // Создаем пользователя
            $pdo = Db::pdo();
            $stmt = $pdo->prepare("
                INSERT INTO users (login, password_hash, telegram_id, must_change_password) 
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$login, $passwordHash, $telegramId]);
            $userId = (int)$pdo->lastInsertId();

            // Создаем профиль пользователя
            $displayName = trim($firstName . ' ' . $lastName);
            if (empty($displayName)) {
                $displayName = $login;
            }

            $stmt = $pdo->prepare("
                INSERT INTO settings_profile (user_id, display_name, locale, theme, privacy_last_seen, read_receipts)
                VALUES (?, ?, 'ru', 'system', 'contacts', 1)
            ");
            $stmt->execute([$userId, $displayName]);

            // Создаем настройки уведомлений
            $stmt = $pdo->prepare("
                INSERT INTO settings_notifications (user_id, push_enabled, sound_enabled, preview_enabled)
                VALUES (?, 1, 1, 1)
            ");
            $stmt->execute([$userId]);

            // Генерируем crypto keys
            (new UserKeyService())->generateAndStore($userId);

            // Отправляем пароль в Telegram
            try {
                (new TelegramBot())->sendMessage(
                    $telegramId,
                    "✅ Регистрация прошла успешно!\n\n👤 Ваш логин: `{$login}`\n🔑 Ваш пароль: `{$password}`\n\nСохраните пароль — он нужен для входа. В настройках можно сменить пароль.",
                    ['parse_mode' => 'Markdown']
                );
            } catch (\Throwable $e) {
                Logger::error('Telegram password send failed', ['error' => $e->getMessage()]);
            }

            // Создаем сессию
            $sessionService = new SessionService();
            $jwtService = new JwtService();
            
            $refreshTtl = $jwtService->getRefreshTtl();
            $session = $sessionService->createSession(
                $userId, 
                null, // device_uuid
                'Telegram Registration', // device_label
                'web', // platform
                $refreshTtl
            );

            // Генерируем access token
            $user = $usersRepo->findById($userId);
            $accessToken = $jwtService->issueAccess([
                'id' => $userId,
                'login' => $login,
                'role' => (string)($user['role'] ?? 'user')
            ]);

            // Очищаем временные данные
            $this->clearReservedLogin($login);
            $this->clearTelegramData($login);

            // Собираем ответ
            $authResponse = (new AuthAggregateService())->build(
                $userId,
                $accessToken['token'],
                (int)$accessToken['exp'],
                (string)$session['refresh'],
                (string)$session['expires_at']
            );

            $authResponse['user']['must_change_password'] = true;

            return $authResponse;
        });
    }

    private function applyOtpRateLimit(string $login, string $ip): void
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $key = "rl:otpstart:" . md5(strtolower($login) . '|' . $ip);
            $maxStarts = \Messa\Core\ConfigHelper::getInt('LIMITS_OTP_START_PER_HOUR_PER_LOGIN', 3);
            
            $count = (int)$redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, 3600);
            }
            
            if ($count > $maxStarts) {
                throw new UnprocessableException('Слишком много попыток. Попробуйте позже.');
            }
        } catch (\Throwable $e) {
            Logger::warning('Rate limiting skipped', ['error' => $e->getMessage()]);
        }
    }

    private function reserveLogin(string $login): void
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $key = "reserved:login:" . md5(strtolower($login));
            $redis->setex($key, 600, '1'); // 10 минут
        } catch (\Throwable $e) {
            Logger::warning('Failed to reserve login', ['error' => $e->getMessage()]);
        }
    }

    private function isLoginReserved(string $login): bool
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $key = "reserved:login:" . md5(strtolower($login));
            return (bool)$redis->exists($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function clearReservedLogin(string $login): void
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $key = "reserved:login:" . md5(strtolower($login));
            $redis->del($key);
        } catch (\Throwable $e) {
            // Игнорируем ошибки очистки
        }
    }

    private function storeTelegramData(string $login, array $telegramData): void
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $key = "telegram:data:" . md5(strtolower($login));
            $redis->setex($key, 600, json_encode($telegramData)); // 10 минут
        } catch (\Throwable $e) {
            Logger::warning('Failed to store Telegram data', ['error' => $e->getMessage()]);
        }
    }

    private function getTelegramData(string $login): ?array
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $key = "telegram:data:" . md5(strtolower($login));
            $data = $redis->get($key);
            return $data ? $this->getJsonInput($data, true) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function clearTelegramData(string $login): void
    {
        try {
            $redis = \Messa\Core\Redis::client();
            $key = "telegram:data:" . md5(strtolower($login));
            $redis->del($key);
        } catch (\Throwable $e) {
            // Игнорируем ошибки очистки
        }
    }

    private function generateStrongPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*_-+=';
        $length = 16;
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        
        return $password;
    }

    private function argon2id(string $password): string
    {
        $options = [
            'memory_cost' => 1 << 16, // 64MB
            'time_cost' => 3,
            'threads' => 1,
        ];
        
        $hash = password_hash($password, PASSWORD_ARGON2ID, $options);
        if ($hash === false) {
            throw new \RuntimeException('Argon2id not available');
        }
        
        return $hash;
    }
    
    private function getTelegramAvatar(array $telegramData): ?string
    {
        if (!isset($telegramData['telegram_id'])) {
            Logger::warning('Telegram ID is missing in the provided data');
            return null;
        }

        $telegramId = $telegramData['telegram_id'];
        $botToken = \Messa\Core\ConfigHelper::getString('TELEGRAM_BOT_TOKEN');

        if (empty($botToken)) {
            Logger::error('Telegram Bot Token is not configured');
            return null;
        }

        $url = "https://api.telegram.org/bot{$botToken}/getUserProfilePhotos?user_id={$telegramId}";

        try {
            $response = file_get_contents($url);
            if ($response === false) {
                Logger::warning('Failed to fetch Telegram profile photos', ['telegram_id' => $telegramId]);
                return null;
            }

            $data = $this->getJsonInput($response, true);
            if (!isset($data['ok']) || !$data['ok'] || empty($data['result']['photos'])) {
                Logger::info('No profile photos found for Telegram user', ['telegram_id' => $telegramId]);
                return null;
            }

            // Get the most recent photo
            $photos = $data['result']['photos'];
            $largestPhoto = end($photos)[0] ?? null;

            if (!$largestPhoto || !isset($largestPhoto['file_id'])) {
                Logger::warning('Failed to retrieve file_id for Telegram profile photo', ['telegram_id' => $telegramId]);
                return null;
            }

            $fileId = $largestPhoto['file_id'];
            $fileUrl = "https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}";

            $fileResponse = file_get_contents($fileUrl);
            if ($fileResponse === false) {
                Logger::warning('Failed to fetch Telegram file details', ['file_id' => $fileId]);
                return null;
            }

            $fileData = $this->getJsonInput($fileResponse, true);
            if (!isset($fileData['ok']) || !$fileData['ok'] || empty($fileData['result']['file_path'])) {
                Logger::warning('Failed to retrieve file path for Telegram profile photo', ['file_id' => $fileId]);
                return null;
            }

            $filePath = $fileData['result']['file_path'];
            return "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        } catch (\Throwable $e) {
            Logger::error('Error occurred while fetching Telegram avatar', [
                'telegram_id' => $telegramId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}