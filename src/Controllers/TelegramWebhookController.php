<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Core\ConfigHelper;
use Messa\Core\Redis as R;
use Messa\Core\Logger;
use Messa\Http\Exceptions\UnprocessableException;

final class TelegramWebhookController extends BaseController
{
    public function handle(Request $req, Response $res): array
    {
        if ($req->method() !== 'POST') {
            $res->setStatus(405);
            return ['error' => 'method_not_allowed'];
        }

        $contentType = $req->header('Content-Type') ?? '';
        if (stripos($contentType, 'application/json') !== 0) {
            $res->setStatus(415);
            return ['error' => 'unsupported_media_type'];
        }

        $secretExpected = ConfigHelper::getString('TELEGRAM_WEBHOOK_SECRET', '');
        if ($secretExpected !== '') {
            $secretGot = (string)($req->header('X-Telegram-Bot-Api-Secret-Token') ?? '');
            if (!hash_equals($secretExpected, $secretGot)) {
                Logger::warning('Telegram webhook secret mismatch', [
                    'got_len' => strlen($secretGot)
                ]);
                $res->setStatus(403);
                return ['error' => 'forbidden', 'code' => 'bad_secret'];
            }
        }

        // безопасный парсинг JSON
        try {
            $input = $req->json(true);
        } catch (UnprocessableException $e) {
            Logger::warning('Telegram webhook invalid JSON', [
                'error' => $e->getMessage()
            ]);
            return ['status' => 'ok'];
        }

        if (!is_array($input)) {
            Logger::warning('Telegram webhook invalid JSON structure');
            return ['status' => 'ok'];
        }

        // Идемпотентность: проверка дубликатов по update_id
        $updateId = $input['update_id'] ?? null;
        if (is_int($updateId)) {
            try {
                $prefix = ConfigHelper::getString('REDIS_PREFIX', 'messa:');
                $prefix = rtrim($prefix, ':') . ':'; // нормализация
                $key = $prefix . 'tgw:dedup:' . $updateId;

                // Predis: OK или null; phpredis: true или false
                $ok = R::client()->set($key, '1', 'EX', 600, 'NX');

                if ($ok === null || $ok === false) {
                    Logger::info('Telegram webhook duplicate update', ['update_id' => $updateId]);
                    return ['status' => 'ok', 'duplicate' => true];
                }
            } catch (\Throwable $e) {
                Logger::error('Telegram webhook deduplication failed', ['error' => $e->getMessage()]);
            }
        }

        if (!isset($input['message']['text'])) {
            return ['status' => 'ok'];
        }

        $message = $input['message'];
        $text = trim($message['text']);
        $chatId = $message['chat']['id'];
        $from = $message['from'];
        $telegramId = $from['id'];
        $firstName = $from['first_name'] ?? '';
        $lastName = $from['last_name'] ?? '';

        Logger::info('Telegram webhook message', [
            'update_id' => $input['update_id'] ?? null,
            'text' => $text,
            'chat_id' => $chatId,
            'telegram_id' => $telegramId
        ]);

        if (strpos($text, '/start') === 0) {
            Logger::info('Telegram webhook /start', ['chat_id' => $chatId]);
            $this->handleStartCommand($chatId, $firstName);
        } elseif (strpos($text, '/reg') === 0) {
            Logger::info('Telegram webhook /reg', ['chat_id' => $chatId, 'login_raw' => $text]);
            $this->handleRegCommand($chatId, $telegramId, $text, $firstName, $lastName);
        }

        return ['status' => 'ok'];
    }

    private function handleStartCommand(int $chatId, string $firstName): void
    {
        $message = "👋 Привет, {$firstName}!\n\n";
        $message .= "Я официальный бот MESSA. Я связываю твой Telegram с аккаунтом.\n\n";
        $message .= "📝 *Как пройти регистрацию:*\n";
        $message .= "1) В приложении выбери логин и нажми «Зарегистрироваться через Telegram»\n";
        $message .= "2) Напиши мне `/reg твой_логин`\n";
        $message .= "3) Я пришлю 6-значный код подтверждения\n";
        $message .= "4) Введи его в приложении — аккаунт будет создан\n";
        $message .= "5) После этого я пришлю пароль для будущих авторизаций";

        $bot = new \Messa\Services\TelegramBot();
        $bot->sendMessage($chatId, $message, ['parse_mode' => 'Markdown']);
    }

    private function handleRegCommand(int $chatId, int $telegramId, string $text, string $firstName, string $lastName): void
    {
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            $bot = new \Messa\Services\TelegramBot();
            $bot->sendMessage($chatId, "❌ Пожалуйста, укажи логин:\n`/reg твой_логин`", ['parse_mode' => 'Markdown']);
            return;
        }

        $login = trim($parts[1]);

        // Валидация логина теми же правилами, что и в HTTP API
        if (!$this->validateLogin($login)) {
            $bot = new \Messa\Services\TelegramBot();
            $bot->sendMessage(
                $chatId,
                "❌ Неверный формат логина. Используй только латинские буквы, цифры, точки, дефисы и подчёркивания (3–32 символа).",
                ['parse_mode' => 'Markdown']
            );
            return;
        }

        // Проверяем, не занят ли логин
        $usersRepo = new \Messa\Repos\UsersRepository();
        $existingUser = $usersRepo->findByLogin($login);
        if ($existingUser) {
            $bot = new \Messa\Services\TelegramBot();
            $bot->sendMessage($chatId, "❌ Логин `{$login}` уже занят. Выбери другой логин.", ['parse_mode' => 'Markdown']);
            return;
        }

        // Проверяем, не привязан ли Telegram ID к другому аккаунту
        $existingByTelegram = $usersRepo->findByTelegramId((string)$telegramId);
        if ($existingByTelegram) {
            $bot = new \Messa\Services\TelegramBot();
            $bot->sendMessage($chatId, "❌ Этот Telegram аккаунт уже привязан к пользователю @" . $existingByTelegram['login'], ['parse_mode' => 'Markdown']);
            return;
        }

        // Проверяем, была ли начата регистрация для этого логина
        if (!$this->isRegistrationStarted($login)) {
            $bot = new \Messa\Services\TelegramBot();
            $bot->sendMessage($chatId, "❌ Регистрация для логина `{$login}` не начата. Сначала начни регистрацию в приложении MESSA.", ['parse_mode' => 'Markdown']);
            return;
        }

        // Генерируем код подтверждения
        $otp = new \Messa\Services\OtpService();
        $info = $otp->start('reg', $login);

        // Сохраняем данные Telegram для использования при подтверждении
        $this->storeTelegramData($login, [
            'telegram_id' => $telegramId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'chat_id' => $chatId
        ]);

        // Отправляем код пользователю
        $bot = new \Messa\Services\TelegramBot();
        $bot->sendRegistrationCode($chatId, $login, $info['code'], $info['ttl_sec']);

        Logger::info('Telegram registration code sent', [
            'login' => $login,
            'telegram_id' => $telegramId
        ]);
    }

    private function isRegistrationStarted(string $login): bool
    {
        try {
            $redis = R::client();
            $key = "reserved:login:" . md5(strtolower($login));
            return (bool)$redis->exists($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function storeTelegramData(string $login, array $data): void
    {
        try {
            $redis = R::client();
            $key = "telegram:data:" . md5(strtolower($login));
            $redis->setex($key, 600, json_encode($data)); // 10 минут
        } catch (\Throwable $e) {
            Logger::error('Failed to store Telegram data', ['error' => $e->getMessage()]);
        }
    }
}