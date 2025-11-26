<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Services\SettingsService;
use Messa\Repos\UsersRepository;

final class MeController extends BaseController
{
    public function get(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        
        $usersRepo = new UsersRepository();
        $user = $usersRepo->findById($userId);
        
        if (!$user) {
            throw new \Messa\Http\Exceptions\NotFoundException('User not found');
        }

        $settings = (new SettingsService())->getForUser($userId);
        
        return [
            'user' => [
                'id' => (int)$user['id'],
                'login' => (string)$user['login'],
                'telegram_id' => isset($user['telegram_id']) ? (int)$user['telegram_id'] : null,
                'must_change_password' => (bool)$user['must_change_password'],
                'created_at' => $user['created_at'],
                'last_login_at' => $user['last_login_at'],
            ],
            'settings' => $settings
        ];
    }

    public function patch(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $body = $req->json();
        
        $this->validateSettings($body);
        
        $updated = (new SettingsService())->updateForUser($userId, $body);
        
        $usersRepo = new UsersRepository();
        $user = $usersRepo->findById($userId);
        
        return [
            'user' => [
                'id' => (int)$user['id'],
                'login' => (string)$user['login'],
                'telegram_id' => isset($user['telegram_id']) ? (int)$user['telegram_id'] : null,
                'must_change_password' => (bool)$user['must_change_password'],
                'created_at' => $user['created_at'],
                'last_login_at' => $user['last_login_at'],
            ],
            'settings' => $updated
        ];
    }

    private function validateSettings(array $settings): void
    {
        $allowedThemes = ['light', 'dark', 'system'];
        $allowedPrivacy = ['all', 'contacts', 'nobody'];
        $allowedLocales = ['ru', 'en', 'uk'];

        if (isset($settings['locale']) && !in_array($settings['locale'], $allowedLocales, true)) {
            throw new UnprocessableException('Неверное значение locale');
        }
        
        if (isset($settings['theme']) && !in_array($settings['theme'], $allowedThemes, true)) {
            throw new UnprocessableException('Неверное значение theme');
        }
        
        if (isset($settings['privacy']['last_seen']) && !in_array($settings['privacy']['last_seen'], $allowedPrivacy, true)) {
            throw new UnprocessableException('Неверное значение privacy.last_seen');
        }
        
        if (isset($settings['profile']['display_name']) && mb_strlen($settings['profile']['display_name'], 'UTF-8') > 64) {
            throw new UnprocessableException('display_name слишком длинное');
        }
        
        if (isset($settings['profile']['bio']) && mb_strlen($settings['profile']['bio'], 'UTF-8') > 255) {
            throw new UnprocessableException('bio слишком длинное');
        }
        
        if (isset($settings['notifications']['quiet_hours_start']) && !preg_match('/^\d{2}:\d{2}$/', $settings['notifications']['quiet_hours_start'])) {
            throw new UnprocessableException('quiet_hours_start: ожидается HH:MM');
        }
        
        if (isset($settings['notifications']['quiet_hours_end']) && !preg_match('/^\d{2}:\d{2}$/', $settings['notifications']['quiet_hours_end'])) {
            throw new UnprocessableException('quiet_hours_end: ожидается HH:MM');
        }
    }
}