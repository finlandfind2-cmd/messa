<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

final class CallsRepository
{
    
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function create(int $chatId, int $initiatorId, string $roomId, string $url): int
    {
        $st = $this->pdo->prepare("INSERT INTO calls (chat_id, initiator_id, telemost_room_id, telemost_url, status) VALUES (?,?,?,?, 'started')");
        $st->execute([$chatId, $initiatorId, $roomId, $url]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT id, chat_id, initiator_id, telemost_room_id, telemost_url, status, started_at, ended_at, duration_sec FROM calls WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function getActiveByChat(int $chatId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM calls 
            WHERE chat_id = ? AND status = 'started' 
            ORDER BY started_at DESC
        ");
        $stmt->execute([$chatId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function endCall(int $callId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE calls 
            SET status = 'ended', ended_at = NOW(), 
                duration_sec = TIMESTAMPDIFF(SECOND, started_at, NOW()) 
            WHERE id = ? AND status = 'started'
        ");
        return $stmt->execute([$callId]);
    }

    public function addParticipant(int $callId, int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO call_participants (call_id, user_id, joined_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE joined_at = NOW()
        ");
        return $stmt->execute([$callId, $userId]);
    }
}
