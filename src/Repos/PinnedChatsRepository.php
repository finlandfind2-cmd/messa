<?php
declare(strict_types=1);

namespace Messa\Repos;

use Messa\Core\Db;

final class PinnedChatsRepository
{
    public function pinChat(int $userId, int $chatId): void
    {
        $pdo = Db::pdo();
        
        // Получаем текущую максимальную позицию
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM pinned_chats WHERE user_id = ?");
        $stmt->execute([$userId]);
        $maxPosition = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            INSERT INTO pinned_chats (user_id, chat_id, position) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE position = ?
        ");
        $stmt->execute([$userId, $chatId, $maxPosition + 1, $maxPosition + 1]);
    }

    public function unpinChat(int $userId, int $chatId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("DELETE FROM pinned_chats WHERE user_id = ? AND chat_id = ?");
        $stmt->execute([$userId, $chatId]);
    }

    public function getPinnedChats(int $userId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT pc.chat_id, pc.pinned_at, pc.position, c.type, c.title, c.avatar_key
            FROM pinned_chats pc
            INNER JOIN chats c ON pc.chat_id = c.id
            WHERE pc.user_id = ?
            ORDER BY pc.position ASC
        ");
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function reorderPinnedChats(int $userId, array $chatIds): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        
        try {
            // Сначала сбрасываем все позиции
            $stmt = $pdo->prepare("UPDATE pinned_chats SET position = 0 WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Затем устанавливаем новые позиции
            $stmt = $pdo->prepare("UPDATE pinned_chats SET position = ? WHERE user_id = ? AND chat_id = ?");
            foreach ($chatIds as $position => $chatId) {
                $stmt->execute([$position + 1, $userId, (int)$chatId]);
            }
            
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}