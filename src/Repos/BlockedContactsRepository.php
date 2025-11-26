<?php
declare(strict_types=1);

namespace Messa\Repos;

use Messa\Core\Db;
use PDO;

final class BlockedContactsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function block(int $userId, int $blockedUserId): void
    {
        $st = $this->pdo->prepare("
            INSERT INTO blocked_contacts (user_id, blocked_user_id, created_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE created_at = created_at
        ");
        $st->execute([$userId, $blockedUserId]);
    }

    public function unblock(int $userId, int $blockedUserId): void
    {
        $st = $this->pdo->prepare("
            DELETE FROM blocked_contacts
            WHERE user_id = ? AND blocked_user_id = ?
            LIMIT 1
        ");
        $st->execute([$userId, $blockedUserId]);
    }

    public function isBlocked(int $userId, int $blockedUserId): bool
    {
        $st = $this->pdo->prepare("
            SELECT 1
            FROM blocked_contacts
            WHERE user_id = ? AND blocked_user_id = ?
            LIMIT 1
        ");
        $st->execute([$userId, $blockedUserId]);
        return (bool)$st->fetchColumn();
    }

    public function list(int $userId): array
    {
        $st = $this->pdo->prepare("
            SELECT blocked_user_id, created_at
            FROM blocked_contacts
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn(array $r) => [
            'user_id'    => (int)$r['blocked_user_id'],
            'created_at' => (string)$r['created_at'],
        ], $rows);
    }
}
