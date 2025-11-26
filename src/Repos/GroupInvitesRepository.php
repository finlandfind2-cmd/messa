<?php
declare(strict_types=1);

namespace Messa\Repos;

use Messa\Core\Db;
use PDO;

final class GroupInvitesRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function create(int $chatId, int $creatorId, ?int $ttlSeconds, ?int $maxUses): array
    {
        $token = bin2hex(random_bytes(16)); // 32 символа
        $expiresAt = null;

        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $expiresAt = (new \DateTimeImmutable("+{$ttlSeconds} seconds"))
                ->format('Y-m-d H:i:s');
        }

        $st = $this->pdo->prepare("
            INSERT INTO chat_invites (chat_id, token, created_by, expires_at, max_uses)
            VALUES (?, ?, ?, ?, ?)
        ");
        $st->execute([
            $chatId,
            $token,
            $creatorId,
            $expiresAt,
            $maxUses,
        ]);

        return [
            'id'         => (int)$this->pdo->lastInsertId(),
            'chat_id'    => $chatId,
            'token'      => $token,
            'created_by' => $creatorId,
            'expires_at' => $expiresAt,
            'max_uses'   => $maxUses,
            'used_count' => 0,
        ];
    }

    public function getByToken(string $token): ?array
    {
        $st = $this->pdo->prepare("
            SELECT id, chat_id, token, created_by, created_at, expires_at, max_uses, used_count
            FROM chat_invites
            WHERE token = ?
            LIMIT 1
        ");
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id'         => (int)$row['id'],
            'chat_id'    => (int)$row['chat_id'],
            'token'      => (string)$row['token'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'expires_at' => $row['expires_at'],
            'max_uses'   => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
            'used_count' => (int)$row['used_count'],
        ];
    }

    public function incrementUsage(int $id): void
    {
        $st = $this->pdo->prepare("
            UPDATE chat_invites
            SET used_count = used_count + 1
            WHERE id = ?
        ");
        $st->execute([$id]);
    }

    public function revoke(int $id): void
    {
        $st = $this->pdo->prepare("DELETE FROM chat_invites WHERE id = ? LIMIT 1");
        $st->execute([$id]);
    }

    public function listByChat(int $chatId): array
    {
        $st = $this->pdo->prepare("
            SELECT id, chat_id, token, created_by, created_at, expires_at, max_uses, used_count
            FROM chat_invites
            WHERE chat_id = ?
            ORDER BY created_at DESC
        ");
        $st->execute([$chatId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id'         => (int)$r['id'],
                'chat_id'    => (int)$r['chat_id'],
                'token'      => (string)$r['token'],
                'created_by' => (int)$r['created_by'],
                'created_at' => (string)$r['created_at'],
                'expires_at' => $r['expires_at'],
                'max_uses'   => $r['max_uses'] !== null ? (int)$r['max_uses'] : null,
                'used_count' => (int)$r['used_count'],
            ];
        }, $rows);
    }
}
