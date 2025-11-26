#!/usr/bin/env php
<?php
declare(strict_types=1);

use Messa\Core\Bootstrap;
use Messa\Queue\RedisQueue;
use Messa\Queue\Jobs\PostprocessMediaJob;
use Messa\Core\Logger;

require __DIR__ . '/../vendor/autoload.php';
Bootstrap::initCli();

// Настройки выполнения из окружения
$budgetSec  = (int)($_ENV['WORKER_BUDGET_SEC'] ?? 50);    // сколько секунд крутиться за запуск
$batchSize  = max(1, (int)($_ENV['WORKER_BATCH'] ?? 20)); // сколько задач за раз читать из очереди
$maxRetries = max(0, (int)($_ENV['WORKER_MAX_RETRIES'] ?? 5));

$deadline  = microtime(true) + max(10, $budgetSec);
$queueName = 'q:media:post'; // очередь проекта (LPUSH -> RPOP, FIFO)
$q = new RedisQueue();

$r = \Messa\Core\Redis::client();

// Глобальная блокировка запуска воркера (защита от перекрытий по cron)
$lockKey = 'lock:worker:media';
$lockTtl = max(60, (int)($budgetSec + 30));
if ($r->setnx($lockKey, (string)time()) === 0) {
    // уже работает другой инстанс
    exit(0);
}
$r->expire($lockKey, $lockTtl);

// Семафор для ограничения параллельных транскодов ffmpeg (простой INCR/DECR)
$semKey     = 'sem:ffmpeg';
$semMax     = max(1, (int)($_ENV['FFMPEG_SEMAPHORE_MAX'] ?? 1)); // для shared-хостинга 1 — безопасно
$semKeyTtl  = 60; // защита от «залипших» ключей

try {
    while (microtime(true) < $deadline) {
        // продлеваем TTL глобального lock’а на всякий случай
        $r->expire($lockKey, $lockTtl);

        $rawItems = $q->popBatch($queueName, $batchSize); // неблокирующая выборка батчем
        if (!$rawItems) {
            usleep(200_000); // 200мс чтобы не крутить CPU вхолостую
            continue;
        }

        foreach ($rawItems as $raw) {
            $payload = json_decode($raw, true);
            if (!is_array($payload) || ($payload['type'] ?? '') !== 'media_post') {
                Logger::warning('Skip unknown queue payload', ['raw' => substr((string)$raw, 0, 200)]);
                continue;
            }

            // Пытаемся захватить «слот» ffmpeg; если лимит — возвращаем в хвост очереди и идём дальше
            $cur = (int)$r->incr($semKey);
            if ($cur > $semMax) {
                // откатываем инкремент и мягко ре-энкьём задачу без увеличения attempts
                $r->decr($semKey);
                $r->expire($semKey, $semKeyTtl);
                $r->rpush($queueName, [$raw]);
                // немного притормозим, чтобы не «молотить» впустую
                usleep(100_000);
                continue;
            }
            $r->expire($semKey, $semKeyTtl);

            try {
                $job = PostprocessMediaJob::fromArray($payload);
                $job->handle();
                $r->incr('metrics:q:media_post:ok');
            } catch (\Throwable $e) {
                $attempts = (int)($payload['attempts'] ?? 0) + 1;
                $payload['attempts']   = $attempts;
                $payload['last_error'] = substr($e->getMessage(), 0, 500);

                Logger::error('Postprocess job failed', [
                    'attachment_id' => $payload['attachment_id'] ?? null,
                    'attempts'      => $attempts,
                    'err'           => $e->getMessage(),
                ]);
                $r->incr('metrics:q:media_post:fail');

                if ($attempts <= $maxRetries) {
                    // простой ре-энкью без идемпотентности (иначе idem-ключ заблокирует повтор)
                    $r->rpush($queueName, [json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                } else {
                    // складываем в DLQ
                    $r->lpush('q:dlq:media:post', [json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            } finally {
                // обязательно освобождаем «слот» ffmpeg
                $r->decr($semKey);
                $r->expire($semKey, $semKeyTtl);
            }
        }
    }
} finally {
    // снимаем глобальный lock
    $r->del([$lockKey]);
}
