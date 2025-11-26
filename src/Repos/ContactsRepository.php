<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

class ContactsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function getUserContacts(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                c.peer_id as user_id,
                u.login,
                sp.display_name,
                sp.avatar_key,
                u.last_login_at as last_seen_at,
                c.last_message_at,
                c.created_at
            FROM contacts c
            INNER JOIN users u ON c.peer_id = u.id
            LEFT JOIN settings_profile sp ON u.id = sp.user_id
            WHERE c.user_id = ? AND c.deleted_at IS NULL
            ORDER BY c.last_message_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function softDeleteContact(int $userId, int $peerId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE contacts 
            SET deleted_at = NOW() 
            WHERE user_id = ? AND peer_id = ? AND deleted_at IS NULL
        ");
        
        return $stmt->execute([$userId, $peerId]);
    }
    
    // Добавление контакта с помощью QR-кода (Задел на будущее)
    public function addContactViaQRCode(int $userA, int $userB): bool
    {
        // Добавляем контакт в обе стороны, если его еще нет
        $sql = "INSERT INTO contacts (user_id, peer_id, created_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE deleted_at = NULL";

        $stmt = $this->pdo->prepare($sql);
        $resultA = $stmt->execute([$userA, $userB]);
        $resultB = $stmt->execute([$userB, $userA]);

        return $resultA && $resultB;
    }
    
    public function getContact(int $userId, int $peerId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM contacts 
            WHERE user_id = ? AND peer_id = ? AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$userId, $peerId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}