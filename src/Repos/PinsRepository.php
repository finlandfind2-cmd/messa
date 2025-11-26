<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

final class PinsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function userCanPin(int $chatId, int $userId): bool
    {
        $st = $this->pdo->prepare("SELECT role FROM chat_members WHERE chat_id=? AND user_id=? LIMIT 1");
        $st->execute([$chatId, $userId]);
        $role = $st->fetchColumn();
        if ($role === false) {
            return false;
        }
        return in_array((string)$role, ['owner','admin','editor'], true);
    }

    public function add(int $chatId, int $msgId, int $byUser): void
    {
        $this->assertMessageBelongsToChat($chatId, $msgId);
        $this->pdo->prepare("INSERT IGNORE INTO message_pins (chat_id, message_id, pinned_by) VALUES (?,?,?)")
            ->execute([$chatId, $msgId, $byUser]);
    }

    public function remove(int $chatId, int $msgId): void
    {
        $this->pdo->prepare("DELETE FROM message_pins WHERE chat_id=? AND message_id=?")->execute([$chatId, $msgId]);
    }

    private function assertMessageBelongsToChat(int $chatId, int $messageId): void
    {
        $st = $this->pdo->prepare("SELECT 1 FROM messages WHERE id=? AND chat_id=? LIMIT 1");
        $st->execute([$messageId, $chatId]);
        if ($st->fetchColumn() === false) {
            throw new \InvalidArgumentException('message_not_in_chat');
        }
    }
}
