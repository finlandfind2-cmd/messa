<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

final class ReactionsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    private function normalizeEmoji(string $emoji): string
    {
        $emoji = trim($emoji);
        if ($emoji === '') {
            throw new \InvalidArgumentException('empty_emoji');
        }

        // Запрещаем управляющие символы
        if (preg_match('/[\x00-\x1F\x7F]/u', $emoji)) {
            throw new \InvalidArgumentException('invalid_emoji');
        }

        // Обрезаем до разумной длины, чтобы не класть монструозные строки
        if (mb_strlen($emoji, 'UTF-8') > 16) {
            $emoji = mb_substr($emoji, 0, 16, 'UTF-8');
        }

        return $emoji;
    }

    public function add(int $messageId, int $userId, string $emoji): array
    {
        $emoji = $this->normalizeEmoji($emoji);
        $ins = $this->pdo->prepare("INSERT IGNORE INTO message_reactions (message_id, user_id, emoji) VALUES (?,?,?)");
        $ins->execute([$messageId, $userId, $emoji]);
        return $this->counts($messageId);
    }

    public function remove(int $messageId, int $userId, string $emoji): array
    {
        $emoji = $this->normalizeEmoji($emoji);
        $del = $this->pdo->prepare("DELETE FROM message_reactions WHERE message_id=? AND user_id=? AND emoji=?");
        $del->execute([$messageId, $userId, $emoji]);
        return $this->counts($messageId);
    }

    public function counts(int $messageId): array
    {
        $st = $this->pdo->prepare("SELECT emoji, COUNT(*) c FROM message_reactions WHERE message_id=? GROUP BY emoji");
        $st->execute([$messageId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[$r['emoji']] = (int)$r['c'];
        }
        return $map;
    }
}
