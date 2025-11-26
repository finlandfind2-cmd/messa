<?php
declare(strict_types=1);
namespace Messa\Core;
date_default_timezone_set('UTC');

final class Bootstrap
{
    public static function init(): void
    {
        $root = dirname(__DIR__, 2);
        $envPath = $root . '/.env';
        Env::load($envPath);

        date_default_timezone_set('UTC');

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            Logger::error('Fatal exception', [
                'e' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => [
                    'code' => 'internal',
                    'message' => 'Внутренняя ошибка сервера',
                    'details' => Config::get('APP_DEBUG', 'false') === 'true'
                        ? ['exception' => get_class($e), 'message' => $e->getMessage()]
                        : [],
                    'trace_id' => bin2hex(random_bytes(8)),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }

    /** Инициализация окружения для CLI-скриптов (cron/one-shot воркеры). */
    public static function initCli(): void
    {
        $root = dirname(__DIR__, 2);
        $envPath = $root . '/.env';
        Env::load($envPath);

        date_default_timezone_set('UTC');

        // Те же строгие ошибки, но без HTTP-ответов
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            // Пишем в логи и STDERR — не пытаемся отдавать JSON
            Logger::error('CLI fatal', [
                'e' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            fwrite(STDERR, "[FATAL] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . PHP_EOL);
        });

        // Быстрый sanity-check Redis (не обязателен)
        try {
            \Messa\Core\Redis::client()->ping();
        } catch (\Throwable $e) {
            Logger::warning('Redis ping failed in CLI init', ['err' => $e->getMessage()]);
        }
    }
}
