<?php
declare(strict_types=1);

namespace Messa\Core;

use Messa\Core\Logger;

final class Transaction
{
    /**
     * Выполнить код в транзакции с автоматическим commit/rollback.
     * Если транзакция уже открыта, новую не начинаем и не делаем commit/rollback здесь.
     */
    public static function run(callable $callback): mixed
    {
        $pdo = Db::pdo();

        // Уже внутри транзакции — просто выполняем callback.
        if ($pdo->inTransaction()) {
            return $callback();
        }

        try {
            $pdo->beginTransaction();
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            Logger::error('Transaction rollback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Проверяем, есть ли ещё активная транзакция, прежде чем делать rollBack
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $e2) {
                    Logger::error('Rollback failed', [
                        'error' => $e2->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Выполнить несколько операций в транзакции
     */
    public static function batch(array $operations): array
    {
        return self::run(function () use ($operations) {
            $results = [];
            foreach ($operations as $operation) {
                $results[] = $operation();
            }
            return $results;
        });
    }
}
