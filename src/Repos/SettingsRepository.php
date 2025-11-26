<?php
declare(strict_types=1);
namespace Messa\Repos;

use Messa\Core\Db;
use PDO;

final class SettingsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function getProfile(int $userId): ?array
    {
        $st = $this->pdo->prepare("SELECT user_id, display_name, bio, avatar_key, locale, theme, privacy_last_seen, read_receipts FROM settings_profile WHERE user_id=? LIMIT 1");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;

        return [
            'user_id'          => (int)$r['user_id'],
            'display_name'     => $r['display_name'],
            'bio'              => $r['bio'],
            'avatar_key'       => $r['avatar_key'],
            'locale'           => $r['locale'],
            'theme'            => $r['theme'],
            'privacy_last_seen'=> $r['privacy_last_seen'],
            'read_receipts'    => (bool)$r['read_receipts'],
        ];
    }

    public function upsertProfile(int $userId, array $p): void
    {
        $sql = "INSERT INTO settings_profile (user_id, display_name, bio, avatar_key, locale, theme, privacy_last_seen, read_receipts, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  display_name=VALUES(display_name),
                  bio=VALUES(bio),
                  avatar_key=VALUES(avatar_key),
                  locale=VALUES(locale),
                  theme=VALUES(theme),
                  privacy_last_seen=VALUES(privacy_last_seen),
                  read_receipts=VALUES(read_receipts),
                  updated_at=NOW()";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            $userId,
            $p['display_name'] ?? null,
            $p['bio'] ?? null,
            $p['avatar_key'] ?? null,
            $p['locale'] ?? 'ru',
            $p['theme'] ?? 'system',
            $p['privacy_last_seen'] ?? 'contacts',
            isset($p['read_receipts']) ? (int)$p['read_receipts'] : 1,
        ]);
    }

    public function getNotifications(int $userId): ?array
    {
        $st = $this->pdo->prepare("SELECT user_id, push_enabled, sound_enabled, preview_enabled, quiet_hours_start, quiet_hours_end FROM settings_notifications WHERE user_id=? LIMIT 1");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? [
            'user_id'           => (int)$r['user_id'],
            'push_enabled'      => (bool)$r['push_enabled'],
            'sound_enabled'     => (bool)$r['sound_enabled'],
            'preview_enabled'   => (bool)$r['preview_enabled'],
            'quiet_hours_start' => $r['quiet_hours_start'],
            'quiet_hours_end'   => $r['quiet_hours_end'],
        ] : null;
    }

    public function upsertNotifications(int $userId, array $n): void
    {
        $sql = "INSERT INTO settings_notifications (user_id, push_enabled, sound_enabled, preview_enabled, quiet_hours_start, quiet_hours_end, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  push_enabled=VALUES(push_enabled),
                  sound_enabled=VALUES(sound_enabled),
                  preview_enabled=VALUES(preview_enabled),
                  quiet_hours_start=VALUES(quiet_hours_start),
                  quiet_hours_end=VALUES(quiet_hours_end),
                  updated_at=NOW()";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            $userId,
            isset($n['push']) ? (int)$n['push'] : 1,
            isset($n['sound']) ? (int)$n['sound'] : 1,
            isset($n['preview']) ? (int)$n['preview'] : 1,
            $n['quiet_hours_start'] ?? null,
            $n['quiet_hours_end'] ?? null,
        ]);
    }
}
