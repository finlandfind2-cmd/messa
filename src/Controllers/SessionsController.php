<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Services\SessionService;

final class SessionsController extends BaseController
{
    public function list(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $limit = max(1, min(100, (int)($req->query('limit') ?? 50)));
        
        $sessionService = new SessionService();
        $sessions = $sessionService->listUserSessions($userId, $limit);
        
        // Добавляем вычисляемое поле для удобства фронтенда
        $sessions = array_map(function($session) {
            $session['is_device'] = !empty($session['device_uuid']);
            return $session;
        }, $sessions);
        
        return [
            'status' => 'ok',
            'sessions' => $sessions
        ];
    }

    public function delete(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $sessionId = (int)$req->param('id', 0);
        
        if ($sessionId <= 0) {
            throw new UnprocessableException('invalid_session_id');
        }

        $sessionService = new SessionService();
        $success = $sessionService->revokeSession($sessionId, $userId);
        
        if (!$success) {
            throw new NotFoundException('session_not_found');
        }
        
        return ['status' => 'ok'];
    }

    /**
     * PATCH /v1/me/sessions/{id}
     * { "label": "Новое название" }
     * Можно переименовать ЛЮБУЮ сессию, не только устройства
     */
    public function update(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $sessionId = (int)$req->param('id', 0);
        $newLabel = (string)($req->json()['label'] ?? '');
        
        if ($sessionId <= 0 || trim($newLabel) === '') {
            throw new UnprocessableException('invalid_parameters');
        }

        $sessionService = new SessionService();
        $success = $sessionService->updateDeviceLabel($sessionId, $userId, $newLabel);
        
        if (!$success) {
            throw new NotFoundException('session_not_found');
        }
        
        return ['status' => 'ok'];
    }
}
}