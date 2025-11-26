<?php
declare(strict_types=1);
namespace Messa\Services;

use Messa\Repos\SettingsRepository;

final class SettingsService
{
    private SettingsRepository $repo;
    public function __construct()
    {
        $this->repo = new SettingsRepository();
    }

    public function getForUser(int $userId): array
    {
        $p = $this->repo->getProfile($userId);
        $n = $this->repo->getNotifications($userId);
        $defaults = $this->defaults();
        return [
            'privacy' => [
                'last_seen' => $p['privacy_last_seen'] ?? $defaults['privacy']['last_seen'],
                'read_receipts' => isset($p['read_receipts']) ? (bool)$p['read_receipts'] : $defaults['privacy']['read_receipts'],
            ],
            'notifications' => [
                'push' => isset($n['push_enabled']) ? (bool)$n['push_enabled'] : $defaults['notifications']['push'],
                'sound' => isset($n['sound_enabled']) ? (bool)$n['sound_enabled'] : $defaults['notifications']['sound'],
                'preview' => isset($n['preview_enabled']) ? (bool)$n['preview_enabled'] : $defaults['notifications']['preview'],
                'quiet_hours_start' => $n['quiet_hours_start'] ?? null,
                'quiet_hours_end' => $n['quiet_hours_end'] ?? null,
            ],
            'locale' => $p['locale'] ?? $defaults['locale'],
            'theme' => $p['theme'] ?? $defaults['theme'],
            'profile' => [
                'display_name' => $p['display_name'] ?? null,
                'bio' => $p['bio'] ?? null,
                'avatar_key' => $p['avatar_key'] ?? null,
            ],
        ];
    }

    public function updateForUser(int $userId, array $input): array
    {
        $profile = $input['profile'] ?? [];
        $notifications = $input['notifications'] ?? [];

        if (isset($input['locale'])) { $profile['locale'] = $input['locale']; }
        if (isset($input['theme'])) { $profile['theme'] = $input['theme']; }
        if (isset($input['privacy']['last_seen'])) { $profile['privacy_last_seen'] = $input['privacy']['last_seen']; }
        if (isset($input['privacy']['read_receipts'])) { $profile['read_receipts'] = (bool)$input['privacy']['read_receipts']; }

        $this->repo->upsertProfile($userId, $profile);
        $this->repo->upsertNotifications($userId, [
            'push' => $notifications['push'] ?? null,
            'sound' => $notifications['sound'] ?? null,
            'preview' => $notifications['preview'] ?? null,
            'quiet_hours_start' => $notifications['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $notifications['quiet_hours_end'] ?? null,
        ]);
        return $this->getForUser($userId);
    }

    public function getDefaultsForUser(int $userId): array
    {
        return $this->defaults();
    }

    private function defaults(): array
    {
        return [
            'privacy' => [
                'last_seen' => 'contacts',
                'read_receipts' => true,
            ],
            'notifications' => [
                'push' => true,
                'sound' => true,
                'preview' => true,
                'quiet_hours_start' => null,
                'quiet_hours_end' => null,
            ],
            'locale' => 'ru',
            'theme'  => 'system',
            'profile' => [
                'display_name' => null,
                'bio' => null,
                'avatar_key' => null,
            ],
        ];
    }
}
