<?php
declare(strict_types=1);
namespace Messa\Services;

use Messa\Core\Config;
use Messa\Core\Db;
use PDO;

final class AuthAggregateService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function build(
        int $userId,
        string $accessToken,
        int $accessExp,
        string $refreshToken,
        string $refreshExp
    ): array {
        $user = $this->loadUser($userId);
        $sessionService = new SessionService();
        
        // Используем новые методы
        $sessions = $sessionService->listUserSessions($userId, 30);
        
        $settings = (new SettingsService())->getDefaultsForUser($userId);
        $featureFlags = [
            'websocket' => (bool)((int)(Config::get('WS_ENABLED', '0') ?? 0)),
            'long_poll' => ['enabled' => true, 'max_parallel' => (int)(Config::get('LP_MAX_PARALLEL', '2') ?? 2)],
        ];
        
        return [
            'access_token'   => $accessToken,
            'access_exp'     => $accessExp,
            'refresh_token'  => $refreshToken,
            'refresh_exp'    => $refreshExp,
            'token_type'     => 'Bearer',
            'user'           => $user,
            'settings'       => $settings,
            'sessions'       => $sessions,
            'devices'        => $devices,
            'dialogs_preview'=> [],
            'feature_flags'  => $featureFlags,
        ];
    }

    private function loadUser(int $userId): array
    {
        $st = $this->pdo->prepare("SELECT id, login, telegram_id, must_change_password, created_at, last_login_at FROM users WHERE id=? LIMIT 1");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'id' => (int)($u['id'] ?? $userId),
            'login' => (string)($u['login'] ?? ''),
            'telegram_id' => isset($u['telegram_id']) ? (int)$u['telegram_id'] : null,
            'must_change_password' => (bool)((int)($u['must_change_password'] ?? 0) === 1),
            'created_at' => $u['created_at'] ?? null,
            'last_login_at' => $u['last_login_at'] ?? null,
        ];
    }
}