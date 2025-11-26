<?php
declare(strict_types=1);
namespace Messa\Services;

use Messa\Core\Logger;
use Messa\Core\ConfigHelper;

final class TelegramBot
{
    private string $token;
    private string $apiBase;

    public function __construct()
    {
        $this->token = (string)ConfigHelper::getString('TELEGRAM_BOT_TOKEN', '');
        $this->apiBase = (string)ConfigHelper::getString('TELEGRAM_API_BASE', 'https://api.telegram.org');
    }

    public function sendMessage(int $chatId, string $text, array $options = []): void
    {
        if (empty($this->token)) {
            Logger::warning('Telegram bot token not configured');
            return;
        }

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $options['parse_mode'] ?? null,
            'disable_web_page_preview' => $options['disable_web_page_preview'] ?? true,
        ];

        $this->makeRequest('sendMessage', $data);
    }

    public function sendRegistrationCode(int $chatId, string $login, string $code, int $ttlSec): void
    {
        $message = "🔐 *Регистрация в MESSA*\n\n";
        $message .= "Логин: `{$login}`\n";
        $message .= "Код подтверждения: *{$code}*\n";
        $message .= "Действует: {$ttlSec} секунд\n\n";
        $message .= "Введите этот код в приложении для завершения регистрации.";

        $this->sendMessage($chatId, $message, ['parse_mode' => 'Markdown']);
    }

    private function makeRequest(string $method, array $data): array
    {
        $url = $this->apiBase . '/bot' . $this->token . '/' . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            Logger::error('Telegram API curl error', ['error' => $error]);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error('Telegram API error', [
                'method' => $method,
                'http_code' => $httpCode,
                'response_length' => strlen($response ?? '')
            ]);
        }

        return json_decode($response ?: '{}', true) ?: [];
    }
}