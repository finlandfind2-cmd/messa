<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

final class AdminStatsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function totals(): array
    {
        $q = [
            'users'    => "SELECT COUNT(*) FROM users",
            'chats'    => "SELECT COUNT(*) FROM chats",
            'messages' => "SELECT COUNT(*) FROM messages",
            'calls'    => "SELECT COUNT(*) FROM calls",
        ];
        $out = [];
        foreach ($q as $k=>$sql) { $out[$k] = (int)$this->pdo->query($sql)->fetchColumn(); }
        return $out;
    }

    public function activeUsers(int $days): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(DISTINCT sender_id) FROM messages WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL :d DAY)");
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn();
    }

    public function activeChats(int $days): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(DISTINCT chat_id) FROM messages WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL :d DAY)");
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn();
    }

    public function messagesPerDay(int $days): array
    {
        $st = $this->pdo->prepare("
            SELECT DATE(created_at) d, COUNT(*) c
            FROM messages
            WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL :d DAY)
            GROUP BY d ORDER BY d ASC
        ");
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['c'] = (int)$r['c']; }
        return $rows;
    }

    public function callsPerDay(int $days): array
    {
        $st = $this->pdo->prepare("
            SELECT DATE(started_at) d, COUNT(*) c
            FROM calls
            WHERE started_at >= (UTC_TIMESTAMP() - INTERVAL :d DAY)
            GROUP BY d ORDER BY d ASC
        ");
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['c'] = (int)$r['c']; }
        return $rows;
    }
}
