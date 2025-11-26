<?php
declare(strict_types=1);

namespace Messa\Repos;

use Messa\Core\Db;
use PDO;

final class UserReportsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function create(int $reporterId, int $targetUserId, string $reason, ?string $comment): int
    {
        $allowed = ['spam','abuse','nsfw','other'];
        if (!in_array($reason, $allowed, true)) {
            $reason = 'other';
        }

        $st = $this->pdo->prepare("
            INSERT INTO user_reports (reporter_id, target_user_id, reason, comment, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $st->execute([$reporterId, $targetUserId, $reason, $comment]);

        return (int)$this->pdo->lastInsertId();
    }
}
