<?php
declare(strict_types=1);

namespace Messa\Services;

use Messa\Core\Logger;
use Messa\Core\Db;
use PDO;

final class SessionService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function createSession(
        int $userId, 
        ?string $deviceUuid = null,
        ?string $deviceLabel = null,
        string $platform = 'web',
        int $refreshTtl = 2592000
    ): array {
        
        // Нормализация device_*
        if ($deviceUuid !== null) {
            $deviceUuid = preg_replace('/[^A-Za-z0-9._:-]/', '', $deviceUuid);
            $deviceUuid = mb_substr($deviceUuid, 0, 64, 'UTF-8');
        }
        
        if ($deviceLabel !== null) {
            $deviceLabel = trim($deviceLabel);
            $deviceLabel = preg_replace('/[\r\n]+/u', ' ', $deviceLabel);
            $deviceLabel = mb_substr($deviceLabel, 0, 64, 'UTF-8');
        } else {
            $deviceLabel = '';
        }

        $refreshToken = bin2hex(random_bytes(32));
        $refreshTokenHash = hash('sha256', $refreshToken);
        $expiresAt = (new \DateTimeImmutable())->modify("+{$refreshTtl} seconds");

        $stmt = $this->pdo->prepare("
            INSERT INTO sessions 
            (user_id, device_uuid, device_label, platform, refresh_token_hash, expires_at, last_seen_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $deviceUuid,
            $deviceLabel,
            $platform,
            $refreshTokenHash,
            $expiresAt->format('Y-m-d H:i:s')
        ]);

        return [
            'refresh' => $refreshToken,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
            'session_id' => (int)$this->pdo->lastInsertId()
        ];
    }

    public function verifyRefresh(string $refreshToken): ?array
    {
        $refreshTokenHash = hash('sha256', $refreshToken);
        
        $stmt = $this->pdo->prepare("
            SELECT s.id as sid, s.user_id, s.device_uuid, s.device_label, s.platform,
                   s.expires_at, u.login, u.role
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.refresh_token_hash = ? 
            AND s.revoked = 0 
            AND s.expires_at > NOW()
            LIMIT 1
        ");
        
        $stmt->execute([$refreshTokenHash]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($session) {
            // Обновляем last_seen_at
            $updateStmt = $this->pdo->prepare("
                UPDATE sessions SET last_seen_at = NOW() WHERE id = ?
            ");
            $updateStmt->execute([$session['sid']]);
        }
        
        return $session ?: null;
    }

    public function rotateRefresh(int $sessionId, int $refreshTtl): array
    {        
        $newRefreshToken = bin2hex(random_bytes(32));
        $newRefreshTokenHash = hash('sha256', $newRefreshToken);
        $expiresAt = (new \DateTimeImmutable())->modify("+{$refreshTtl} seconds");

        $stmt = $this->pdo->prepare("
            UPDATE sessions 
            SET refresh_token_hash = ?, expires_at = ?, last_seen_at = NOW()
            WHERE id = ? AND revoked = 0
        ");
        
        $stmt->execute([
            $newRefreshTokenHash,
            $expiresAt->format('Y-m-d H:i:s'),
            $sessionId
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Session not found or revoked');
        }

        return [
            'refresh' => $newRefreshToken,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM)
        ];
    }

    public function revokeAllByUserId(int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE sessions 
            SET revoked = 1 
            WHERE user_id = ? AND revoked = 0
        ");
        
        $stmt->execute([$userId]);
        $affected = $stmt->rowCount() > 0;
        
        if ($affected) {
            Logger::info('All sessions revoked for user', ['user_id' => $userId]);
        }
        
        return $affected;
    }
    
    public function revokeById(int $userId, int $sessionId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE sessions 
            SET revoked = 1 
            WHERE user_id = ? AND id = ?
        ");
        
        $stmt->execute([$userId, $sessionId]);
        return $stmt->rowCount() > 0;
    }

    public function revokeAllExceptCurrent(int $userId, int $currentSessionId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE sessions 
            SET revoked = 1 
            WHERE user_id = ? AND id != ? AND revoked = 0
        ");
        
        $stmt->execute([$userId, $currentSessionId]);
        return $stmt->rowCount() > 0;
    }

    public function getActiveSessionsCount(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM sessions 
            WHERE user_id = ? AND revoked = 0 AND expires_at > NOW()
        ");
        
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function listUserSessions(int $userId, ?int $limit = null, ?int $offset = null): array
    {
        $sql = "
            SELECT 
                id,
                device_uuid,
                device_label,
                platform,
                created_at,
                last_seen_at,
                expires_at,
                revoked
            FROM sessions 
            WHERE user_id = ? 
            ORDER BY created_at DESC
        ";
        
        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    

    public function updateDeviceLabel(int $sessionId, int $userId, string $newLabel): bool
    {
        $newLabel = trim($newLabel);
        $newLabel = preg_replace('/[\r\n]+/u', ' ', $newLabel);
        $newLabel = mb_substr($newLabel, 0, 64, 'UTF-8');

        $stmt = $this->pdo->prepare("
            UPDATE sessions 
            SET device_label = ?
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$newLabel, $sessionId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function revokeSession(int $sessionId, int $userId): bool
    {
        return $this->revokeById($userId, $sessionId);
    }
}