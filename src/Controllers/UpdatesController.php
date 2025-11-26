<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Core\ConfigHelper;
use Messa\Core\Redis as R;
use Messa\Core\Logger;
use Messa\Core\Db;
use PDO;


final class UpdatesController extends BaseController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    /**
     * Публикация события для всех участников чата в их персональные Redis Streams.
     * Ключи: upd:stream:{userId}. Ограничиваем длину MAXLEN ~ N и освежаем TTL ключа.
     *
     * @return int количество получателей
     */
    public function publishEventToChat(int $chatId, string $type, array $payload): int
    {
        // Получаем участников чата
        $st = $this->pdo->prepare("SELECT user_id FROM chat_members WHERE chat_id=?");
        $st->execute([$chatId]);
        $uids = $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if (empty($uids)) {
            return 0;
        }

        $maxlen = (int)ConfigHelper::getInt('UPD_STREAM_MAXLEN', 2000);     // мягкий лимит длины стрима
        $ttlSec = (int)ConfigHelper::getInt('UPD_STREAM_TTL_SEC', 172800);  // 48 часов по умолчанию

        // Формируем полезную нагрузку события
        $evt = [
            'type'    => $type,
            'chat_id' => $chatId,
            'ts'      => (int)round(microtime(true) * 1000),
            'data'    => $payload,
        ];
        $json = json_encode($evt, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // не публикуем сломанное событие
            Logger::error('updates.publish.json_encode_failed', ['type'=>$type, 'chat_id'=>$chatId]);
            return 0;
        }

        $r = R::client();
        $delivered = 0;
        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) { continue; }
            $stream = "upd:stream:$uid";
            try {
                // XADD upd:stream:{uid} MAXLEN ~ {maxlen} * e {json}
                $r->executeRaw(['XADD', $stream, 'MAXLEN', '~', (string)$maxlen, '*', 'e', $json]);
                // Обновим TTL стрима, чтобы старые не висели бесконечно
                if ($ttlSec > 0) {
                    $r->executeRaw(['EXPIRE', $stream, (string)$ttlSec]);
                }
                $delivered++;
            } catch (\Throwable $e) {
                Logger::error('updates.publish.fail', [
                    'stream' => $stream,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
        return $delivered;
    }
    
    /** GET /v1/updates?cursor=&limit=&block_ms=&nowait=1 */
    public function poll(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);

        try {
            \Messa\Core\Redis::touchPresence($userId);
        } catch (\Throwable $e) {
            // не падаем, если Redis недоступен
        }
        
        (new \Messa\Repos\PresenceRepository())->logStatus($userId, 'online');
        
        $maxLim = ConfigHelper::getInt('UPD_POLL_LIMIT', 
            ConfigHelper::getInt('UPDATES_LIMIT_MAX', 100)
        );
        $maxLim = max(10, $maxLim);
        
        $limit = (int)($req->query('limit') ?? 50);
        $limit = max(1, min($maxLim, $limit));
        
        $cursor = (string)($req->query('cursor') ?? '$');
        if ($cursor === '>') { $cursor = '$'; }
        if ($cursor !== '$' && $cursor !== '0-0' && !preg_match('/^\d+\-\d+$/', $cursor)) {
            $cursor = '$';
        }
        $block = (int)($req->query('block_ms') ?? ConfigHelper::getInt('UPD_POLL_BLOCK_MS', 20000));
        $block = max(0, min(20000, $block));
        
        $nowait = ((string)($req->query('nowait') ?? '0') === '1');

        try {
            $r = R::client();
        } catch (\Throwable $e) {
            $res->status(503);
            return [
                'status' => 'unavailable',
                'code'   => 'updates_unavailable',
            ];
        }

        $res->header('Cache-Control', 'no-store');
        $res->header('X-Accel-Buffering', 'no');

        $minBlock = (int)ConfigHelper::getInt('UPD_POLL_BLOCK_MIN', 5000);
        if (!$nowait && $block > 0 && $block < $minBlock) {
            $block = $minBlock;
        }

        $semKey = "upd:sem:$userId";
        $cnt = (int)$r->incr($semKey);
        if ($cnt === 1) { 
            $r->expire($semKey, R::ttlLpSem()); 
        }
        if ($cnt > 2) {
            $r->decr($semKey);
            $res->header('Retry-After', '3');
            $res->status(429);
            return [
                'status'=>'rate_limited',
                'retry_after_ms'=>3000,
                'reason'=>'too_many_parallel_polls'
            ];
        }

        try {
            $stream = "upd:stream:$userId";
            $after = $cursor;

            if ($nowait || $block === 0) {
                if ($after === '$') {
                    $res->status(204);
                    $res->header('X-Cursor', $after);
                    return [];
                }
                $events = $this->range($r, $stream, $after, $limit);
                if (empty($events)) {
                    $res->status(204);
                    $res->header('X-Cursor', $after);
                    return [];
                }
                $out = $this->pack($events);
                $res->header('X-Cursor', (string)$out['cursor']);
                return $out;
            }

            $cmd = ['XREAD', 'COUNT', (string)$limit, 'BLOCK', (string)$block, 'STREAMS', $stream, $after];
            $raw = $r->executeRaw($cmd);
            if (!is_array($raw) || empty($raw)) {
                $res->status(204);
                $res->header('X-Cursor', $after);
                return [];
            }
            
            $events = $this->normalizeXread($raw, $stream);
            if (empty($events)) {
                $res->status(204);
                $res->header('X-Cursor', $after);
                return [];
            }
            
            $out = $this->pack($events);
            $res->header('X-Cursor', (string)$out['cursor']);
            return $out;
        } finally {
            try { $r->decr($semKey); } catch (\Throwable) {}
            try { $r->expire($semKey, R::ttlLpSem()); } catch (\Throwable) {}
        }
    }

    /** XRANGE with COUNT limit from $after..+ */
    private function range(\Predis\Client $r, string $stream, string $after, int $limit): array
    {
        // XRANGE: читаем строго "после" заданного ID; если after некорректен, сюда мы не попадаем
        $start = '(' . $after;
        $raw = $r->executeRaw(['XRANGE', $stream, $start, '+', 'COUNT', (string)$limit]);
        return $this->normalizeXrange($raw);
    }

    /** Преобразование ответа XRANGE в список событий */
    private function normalizeXrange($raw): array
    {
        $out = [];
        if (!is_array($raw)) return $out;
        
        foreach ($raw as $row) {
            if (!isset($row[0], $row[1]) || !is_array($row[1])) continue;
            $id = (string)$row[0];
            $fields = $row[1];
            for ($i=0; $i<count($fields); $i+=2) {
                if ($fields[$i]==='e') {
                    $rawJson = (string)$fields[$i+1];
                    if ($rawJson === '') {
                        continue;
                    }
                    $j = json_decode($rawJson, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($j)) {
                        continue;
                    }
                    $j['id'] = $id;
                    $out[] = $j;
                }
            }
        }
        return $out;
    }

    /** Преобразование ответа XREAD в список событий */
    private function normalizeXread($raw, string $expectedStream): array
    {
        $out = [];
        if (!is_array($raw) || !isset($raw[0][1]) || !is_array($raw[0][1])) return $out;
        
        foreach ($raw[0][1] as $row) {
            if (!isset($row[0], $row[1]) || !is_array($row[1])) continue;
            $id = (string)$row[0];
            $fields = $row[1];
            for ($i=0; $i<count($fields); $i+=2) {
                if ($fields[$i]==='e') {
                    $rawJson = (string)$fields[$i+1];
                    if ($rawJson === '') {
                        continue;
                    }
                    $j = json_decode($rawJson, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($j)) {
                        continue;
                    }
                    $j['id'] = $id;
                    $out[] = $j;
                }
            }
        }
        return $out;
    }

    private function pack(array $events): array
    {
        $last = end($events);
        $cursor = $last['id'] ?? '0-0';
        return ['status'=>'ok', 'events'=>$events, 'cursor'=>$cursor];
    }
}