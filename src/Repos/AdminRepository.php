<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

final class AdminRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /** @param array{limit:int, after: ?int, q: ?string} $f */
    public function listUsers(array $f): array
    {
        $sql = "SELECT u.id, u.login, u.display_name, u.role, u.telegram_id, u.must_change_password, u.created_at
                FROM users u WHERE 1=1";
        $p = [];
        if (!empty($f['after'])) { $sql .= " AND u.id < :after"; $p[':after'] = $f['after']; }
        if (!empty($f['q']))     { $sql .= " AND (u.login LIKE :q OR u.display_name LIKE :q)"; $p[':q'] = '%'.$f['q'].'%'; }
        $sql .= " ORDER BY u.id DESC LIMIT :lim";
        $st = $this->pdo->prepare($sql);
        $lim = (int)$f['limit'] + 1;
        foreach ($p as $k=>$v) $st->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $st->bindValue(':lim', $lim, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $next = null;
        if (count($rows) > $f['limit']) { $last = array_pop($rows); $next = (int)$last['id']; $rows[] = $last; array_pop($rows); }
        return ['items'=>$rows, 'next_cursor'=>$next];
    }

    /** @param array{limit:int, after:?int, q:?string, owner_id:?int} $f */
    public function listChats(array $f): array
    {
        $sql = "SELECT c.id, c.type, c.title, c.owner_id, c.last_event_at, c.last_message_id
                FROM chats c WHERE 1=1";
        $p = [];
        if (!empty($f['after']))    { $sql .= " AND c.id < :after"; $p[':after'] = $f['after']; }
        if (!empty($f['q']))        { $sql .= " AND c.title LIKE :q"; $p[':q'] = '%'.$f['q'].'%'; }
        if (!empty($f['owner_id'])) { $sql .= " AND c.owner_id = :oid"; $p[':oid'] = $f['owner_id']; }
        $sql .= " ORDER BY c.id DESC LIMIT :lim";
        $st = $this->pdo->prepare($sql);
        $lim = (int)$f['limit'] + 1;
        foreach ($p as $k=>$v) $st->bindValue($k, $v, is_int($v)?\PDO::PARAM_INT:\PDO::PARAM_STR);
        $st->bindValue(':lim', $lim, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $next = null;
        if (count($rows) > $f['limit']) { $last = array_pop($rows); $next = (int)$last['id']; $rows[] = $last; array_pop($rows); }
        return ['items'=>$rows, 'next_cursor'=>$next];
    }

    /** @param array{limit:int, after:?int, chat_id:?int, sender_id:?int, since:?string, until:?string} $f */
    public function listMessages(array $f): array
    {
        $sql = "SELECT m.id, m.chat_id, m.sender_id, m.type, m.created_at
                FROM messages m WHERE 1=1";
        $p = [];
        if (!empty($f['after']))     { $sql .= " AND m.id < :after"; $p[':after'] = $f['after']; }
        if (!empty($f['chat_id']))   { $sql .= " AND m.chat_id = :chat"; $p[':chat'] = $f['chat_id']; }
        if (!empty($f['sender_id'])) { $sql .= " AND m.sender_id = :sender"; $p[':sender'] = $f['sender_id']; }
        if (!empty($f['since']))     { $sql .= " AND m.created_at >= :since"; $p[':since'] = $f['since']; }
        if (!empty($f['until']))     { $sql .= " AND m.created_at <= :until"; $p[':until'] = $f['until']; }
        $sql .= " ORDER BY m.id DESC LIMIT :lim";
        $st = $this->pdo->prepare($sql);
        $lim = (int)$f['limit'] + 1;
        foreach ($p as $k=>$v) $st->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $st->bindValue(':lim', $lim, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $next = null;
        if (count($rows) > $f['limit']) { $last = array_pop($rows); $next = (int)$last['id']; $rows[] = $last; array_pop($rows); }
        return ['items'=>$rows, 'next_cursor'=>$next];
    }
}
