<?php
declare(strict_types=1);

namespace Messa\Repos;

use Messa\Core\Db;

final class ChatMuteRepository
{
    public function setMute(int $userId, int $chatId, ?\DateTimeInterface $mutedUntil): void
    {
        $pdo = Db::pdo();
        
        if ($mutedUntil === null) {
            // Удаляем запись если передано null
            $stmt = $pdo->prepare("DELETE FROM chat_mute_settings WHERE user_id = ? AND chat_id = ?");
            $stmt->execute([$userId, $chatId]);
        } else {
            // Обновляем или создаем запись
            $stmt = $pdo->prepare("
                INSERT INTO chat_mute_settings (user_id, chat_id, muted_until) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE muted_until = ?
            ");
            $stmt->execute([
                $userId, 
                $chatId, 
                $mutedUntil->format('Y-m-d H:i:s'),
                $mutedUntil->format('Y-m-d H:i:s')
            ]);
        }
    }

    public function getMuteInfo(int $userId, int $chatId): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT muted_until FROM chat_mute_settings 
            WHERE user_id = ? AND chat_id = ?
        ");
        $stmt->execute([$userId, $chatId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    public function getUserMutedChats(int $userId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT chat_id, muted_until FROM chat_mute_settings 
            WHERE user_id = ? AND (muted_until IS NULL OR muted_until > NOW())
        ");
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}