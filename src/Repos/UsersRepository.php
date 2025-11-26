<?php
declare(strict_types=1);
namespace Messa\Repos;

use Messa\Core\Db;
use Messa\Services\CacheService;
use PDO;

final class UsersRepository
{
    private PDO $pdo;
    private CacheService $cache;

    public function __construct()
    {
        $this->pdo = Db::pdo();
        $this->cache = new CacheService();
    }

    public function findById(int $userId): ?array
    {
        $cacheKey = "user:$userId";
        
        return $this->cache->get($cacheKey, function() use ($userId) {
            $stmt = $this->pdo->prepare("
                SELECT id, login, telegram_id, role, avatar_key, must_change_password, 
                       created_at, updated_at, last_login_at 
                FROM users 
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: null;
        }, 300); // 5 минут кэш
    }

    public function findByTelegramId(string|int $telegramId): ?array
    {
        // На всякий случай приводим к строке — и int, и string сюда зайдут
        $telegramId = (string)$telegramId;
    
        $stmt = $this->pdo->prepare("
            SELECT id, login, telegram_id, role, password_hash, must_change_password,
                created_at, updated_at, last_login_at
            FROM users
            WHERE telegram_id = ? AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function findByLogin(string $login): ?array
    {
        $cacheKey = "user:login:" . md5(strtolower($login));
        
        return $this->cache->get($cacheKey, function() use ($login) {
            $stmt = $this->pdo->prepare("
                SELECT id, login, telegram_id, role, password_hash, must_change_password,
                       created_at, updated_at, last_login_at
                FROM users 
                WHERE login_lower = LOWER(?) AND deleted_at IS NULL
            ");
            $stmt->execute([$login]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: null;
        }, 300);
    }

    public function updateLastLogin(int $userId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$userId]);

        if ($result) {
            // Инвалидируем кэш по id
            $this->cache->delete("user:$userId");

            // Инвалидируем кэш по login (если есть)
            $st2 = $this->pdo->prepare("SELECT login_lower FROM users WHERE id = ? LIMIT 1");
            $st2->execute([$userId]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['login_lower'])) {
                $this->cache->delete("user:login:" . md5((string)$row['login_lower']));
            }
        }

        return $result;
    }

    public function searchUsers(string $query, int $limit = 20): array
    {
        $pdo = Db::pdo();
        $searchTerm = '%' . strtolower($query) . '%';
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.login,
                sp.display_name,
                sp.avatar_key,
                u.last_login_at as last_seen_at
            FROM users u
            LEFT JOIN settings_profile sp ON u.id = sp.user_id
            WHERE u.deleted_at IS NULL 
            AND (LOWER(u.login) LIKE ? OR LOWER(sp.display_name) LIKE ?)
            ORDER BY 
                CASE 
                    WHEN LOWER(u.login) = ? THEN 1
                    WHEN LOWER(u.login) LIKE ? THEN 2  
                    ELSE 3
                END,
                u.login
            LIMIT ?
        ");
        
        $exactTerm = strtolower($query);
        $stmt->execute([$searchTerm, $searchTerm, $exactTerm, $exactTerm . '%', $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET password_hash = ?, must_change_password = 0, updated_at = NOW() 
            WHERE id = ?
        ");
        $result = $stmt->execute([$passwordHash, $userId]);

        if ($result) {
            // Инвалидируем кэш по id
            $this->cache->delete("user:$userId");

            // И по login
            $st2 = $this->pdo->prepare("SELECT login_lower FROM users WHERE id = ? LIMIT 1");
            $st2->execute([$userId]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['login_lower'])) {
                $this->cache->delete("user:login:" . md5((string)$row['login_lower']));
            }
        }

        return $result;
    }

    public function updatePasswordWithFlag(int $userId, string $passwordHash, bool $mustChange): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET password_hash = ?, must_change_password = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $result = $stmt->execute([$passwordHash, $mustChange ? 1 : 0, $userId]);

        if ($result) {
            $this->cache->delete("user:$userId");

            $st2 = $this->pdo->prepare("SELECT login_lower FROM users WHERE id = ? LIMIT 1");
            $st2->execute([$userId]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['login_lower'])) {
                $this->cache->delete("user:login:" . md5((string)$row['login_lower']));
            }
        }

        return $result;
    }

    /**
     * Оставить только реально существующие (и не удалённые) user_id.
     *
     * @param int[] $userIds
     * @return int[] отсортированный список существующих ID
     */
    public function filterExistingIds(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT id FROM users WHERE id IN ($placeholders) AND deleted_at IS NULL"
        );
        $st->execute(array_values($ids));

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[] = (int)$id;
        }

        sort($out, SORT_NUMERIC);
        return $out;
    }
}