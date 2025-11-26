<?php
declare(strict_types=1);
namespace Messa\Repos;

use Messa\Core\Db;
use PDO;

final class ChatMembersRepository
{
    private const ORDER = ['member' => 1, 'editor' => 2, 'admin' => 3, 'owner' => 4];
    private const ALLOWED_ROLES = ['member', 'editor', 'admin', 'owner'];
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function role(int $chatId, int $userId): ?string
    {
        $st = $this->pdo->prepare("SELECT role FROM chat_members WHERE chat_id=? AND user_id=? LIMIT 1");
        $st->execute([$chatId, $userId]);
        $r = $st->fetchColumn();
        return $r === false ? null : (string)$r;
    }

    public function hasAtLeast(int $chatId, int $userId, string $minRole): bool
    {
        $cur = $this->role($chatId, $userId);
        if ($cur === null) {
            return false;
        }
        return (self::ORDER[$cur] ?? 0) >= (self::ORDER[$minRole] ?? 99);
    }

    public function listMembers(int $chatId): array
    {
        $st = $this->pdo->prepare("SELECT m.user_id, m.role, u.login FROM chat_members m JOIN users u ON u.id=m.user_id WHERE m.chat_id=? ORDER BY FIELD(m.role,'owner','admin','editor','member'), m.user_id");
        $st->execute([$chatId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($r) => [
            'user_id' => (int)$r['user_id'],
            'login' => $r['login'],
            'role' => $r['role'],
        ], $rows);
    }

    public function addMember(int $chatId, int $userId, string $role = 'member'): void
    {
        $this->assertRole($role);
        $this->pdo->prepare("INSERT IGNORE INTO chat_members (chat_id,user_id,role,unread_count,muted,pinned,last_event_at) VALUES (?,?,?,0,0,0,NOW())")
            ->execute([$chatId, $userId, $role]);
    }

    public function removeMember(int $chatId, int $userId): void
    {
        $this->pdo->prepare("DELETE FROM chat_members WHERE chat_id=? AND user_id=? LIMIT 1")->execute([$chatId, $userId]);
    }

    public function setRole(int $chatId, int $userId, string $role): void
    {
        $this->assertRole($role);
        $this->pdo->prepare("UPDATE chat_members SET role=? WHERE chat_id=? AND user_id=?")->execute([$role, $chatId, $userId]);
    }

    private function assertRole(string $role): void
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new \InvalidArgumentException('invalid_role');
        }
    }

    public function getOtherMember(int $chatId, int $userId): ?int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT user_id 
            FROM chat_members 
            WHERE chat_id = ? AND user_id != ?
            LIMIT 1
        ");
        $stmt->execute([$chatId, $userId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? (int)$result['user_id'] : null;
    }
}
