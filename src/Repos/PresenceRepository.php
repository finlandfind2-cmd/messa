<?php
declare(strict_types=1);

namespace Messa\Repos;

use Messa\Core\Db;
use PDO;

final class PresenceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function logStatus(int $userId, string $status): void
    {
        if (!in_array($status, ['online','offline'], true)) {
            return;
        }
        $st = $this->pdo->prepare("
            INSERT INTO presence_history (user_id, status, at)
            VALUES (?, ?, NOW())
        ");
        $st->execute([$userId, $status]);
    }

    public function getLastOnline(int $userId): ?string
    {
        $st = $this->pdo->prepare("
            SELECT at FROM presence_history
            WHERE user_id = ? AND status = 'online'
            ORDER BY at DESC
            LIMIT 1
        ");
        $st->execute([$userId]);
        $val = $st->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    public function getHistory(int $userId, int $limit = 50, int $offset = 0): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $st = $this->pdo->prepare("
            SELECT status, at
            FROM presence_history
            WHERE user_id = ?
            ORDER BY at DESC
            LIMIT ? OFFSET ?
        ");
        $st->bindValue(1, $userId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->bindValue(3, $offset, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => [
            'status' => $r['status'],
            'at'     => (string)$r['at'],
        ], $rows);
    }
}
