<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Services\JwtService;
use Messa\Repos\MessagesRepository;

final class MessageReadController extends BaseController
{
    public function ack(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $b = $req->json();
        $acks = $b['acks'] ?? [];
        if (!is_array($acks) || count($acks) === 0) {
            throw new UnprocessableException('invalid_payload');
        }

        $repo = new MessagesRepository();
        $r = [];

        // Redis может быть недоступен — не считаем это фатальной ошибкой
        $redis = null;
        try {
            $redis = \Messa\Core\Redis::client();
        } catch (\Throwable $e) {
            $redis = null;
        }

        foreach ($acks as $a) {
            $cid = (int)($a['chat_id'] ?? 0);
            $mid = (int)($a['message_id'] ?? 0);
            if ($cid <= 0 || $mid <= 0) {
                continue;
            }
            if (!$repo->isMember($cid, $uid)) {
                continue;
            }

            if ($redis !== null) {
                try {
                    $key = "ack:{$uid}:{$cid}";
                    $cur = (int)($redis->get($key) ?? 0);
                    if ($mid > $cur) {
                        $redis->setex($key, 86400, (string)$mid);
                    }
                } catch (\Throwable $e) {
                    // игнорируем ошибки Redis для ack
                }
            }

            $r[] = ['chat_id' => $cid, 'message_id' => $mid];
        }

        return ['acks' => $r];
    }

    public function seen(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $b = $req->json();
        $seen = $b['seen'] ?? [];
        if (!is_array($seen) || count($seen) === 0) throw new UnprocessableException('invalid_payload');
        $repo = new MessagesRepository();
        $pdo = \Messa\Core\Db::pdo();
        $out = [];
        foreach ($seen as $s) {
            $cid = (int)($s['chat_id'] ?? 0);
            $mid = (int)($s['message_id'] ?? 0);
            if ($cid <= 0 || $mid <= 0) continue;
            if (!$repo->isMember($cid, $uid)) continue;
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("SELECT last_read_message_id, unread_count FROM chat_members WHERE chat_id=? AND user_id=? FOR UPDATE");
                $st->execute([$cid, $uid]);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                if (!$row) { $pdo->rollBack(); continue; }
                $cur = isset($row['last_read_message_id']) ? (int)$row['last_read_message_id'] : 0;
                if ($mid <= $cur) { $pdo->commit(); $out[]=['chat_id'=>$cid,'last_read_message_id'=>$cur,'unread_count'=>(int)$row['unread_count']]; continue; }
                $cntSt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE chat_id=? AND id>? AND id<=? AND sender_id<>? AND deleted_at IS NULL");
                $cntSt->execute([$cid, $cur, $mid, $uid]);
                $dec = (int)$cntSt->fetchColumn();
                $upd = $pdo->prepare("UPDATE chat_members SET last_read_message_id=?, unread_count=GREATEST(unread_count-?,0) WHERE chat_id=? AND user_id=?");
                $upd->execute([$mid, $dec, $cid, $uid]);
                $st2 = $pdo->prepare("SELECT last_read_message_id, unread_count FROM chat_members WHERE chat_id=? AND user_id=?");
                $st2->execute([$cid, $uid]);
                $row2 = $st2->fetch(\PDO::FETCH_ASSOC) ?: [];
                $pdo->commit();
                $out[] = [
                    'chat_id' => $cid,
                    'last_read_message_id' => (int)($row2['last_read_message_id'] ?? $mid),
                    'unread_count' => (int)($row2['unread_count'] ?? 0),
                ];
            } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
        }
        return ['seen' => $out];
    }
}
