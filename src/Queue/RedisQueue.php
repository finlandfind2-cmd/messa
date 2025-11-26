<?php
declare(strict_types=1);
namespace Messa\Queue;

use Predis\Client as Predis;
use Messa\Core\Env;

final class RedisQueue
{
    private Predis $r;

    public function __construct()
    {
        $this->r = \Messa\Core\Redis::client();
    }

    /**
     * Идемпотентная постановка: если ключ idem не существует, ставим в очередь.
     * @param string $queue  Имя списка Redis (например q:media:post)
     * @param string $jobId  Уникальный id задачи
     * @param string $payload JSON
     * @param int $ttlSec TTL ключа идемпотентности
     */
    public function enqueueUnique(string $queue, string $jobId, string $payload, int $ttlSec = 86400): bool
    {
        $idemKey = "idem:job:$jobId";
        if ($this->r->setnx($idemKey, '1')) {
            $this->r->expire($idemKey, $ttlSec);
            $this->r->lpush($queue, [$payload]);
            return true;
        }
        return false;
    }

    /** Забрать до $max задач (RPOP, FIFO через LPUSH на постановке) */
    public function popBatch(string $queue, int $max): array
    {
        $out = [];
        for ($i=0; $i<$max; $i++) {
            $item = $this->r->rpop($queue);
            if ($item === null) break;
            $out[] = (string)$item;
        }
        return $out;
    }

    /**
     * Обработка одной задачи: десериализация и вызов хендлера.
     * @param string $payload JSON с задачей
     * @param callable $handler Функция-обработчик (array $jobData): void
     * @return bool Успех
     */
    public function processJob(string $payload, callable $handler): bool
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            \Messa\Core\Logger::error('Invalid job payload', ['payload' => $payload]);
            return false;
        }
        try {
            $handler($data);
            return true;
        } catch (\Throwable $e) {
            \Messa\Core\Logger::error('Job processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            // Опционально: dead-letter queue
            $dlq = \Messa\Core\ConfigHelper::getString('QUEUE_DLQ', 'q:dead');
            $this->r->lpush($dlq, [$payload]);
            return false;
        }
    }
}
