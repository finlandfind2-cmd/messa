<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Repos\ChatMuteRepository;
use Messa\Repos\ChatMembersRepository;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\ForbiddenException;

final class ChatMuteController extends BaseController
{
    public function setMute(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)($req->json()['chat_id'] ?? 0);
        $mutedUntil = $req->json()['muted_until'] ?? null; // ISO8601 string или null
        
        if ($chatId <= 0) {
            throw new UnprocessableException('invalid_chat_id');
        }

        // Проверяем, что пользователь состоит в чате
        $membersRepo = new ChatMembersRepository();
        if (!$membersRepo->role($chatId, $userId)) {
            throw new ForbiddenException('not_chat_member');
        }

        $muteRepo = new ChatMuteRepository();
        
        if ($mutedUntil === null) {
            $muteRepo->setMute($userId, $chatId, null);
            return ['status' => 'ok', 'muted_until' => null];
        }

        try {
            $mutedUntilDt = new \DateTimeImmutable($mutedUntil);
            $now = new \DateTimeImmutable();
            
            if ($mutedUntilDt <= $now) {
                throw new UnprocessableException('muted_until_must_be_future');
            }
            
            $muteRepo->setMute($userId, $chatId, $mutedUntilDt);
            return ['status' => 'ok', 'muted_until' => $mutedUntilDt->format(\DateTimeInterface::ATOM)];
            
        } catch (\Exception $e) {
            throw new UnprocessableException('invalid_date_format');
        }
    }

    public function getMuteStatus(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)($req->query('chat_id') ?? 0);
        
        if ($chatId <= 0) {
            throw new UnprocessableException('invalid_chat_id');
        }

        $muteRepo = new ChatMuteRepository();
        $muteInfo = $muteRepo->getMuteInfo($userId, $chatId);
        
        return [
            'muted_until' => $muteInfo['muted_until'] ?? null,
            'is_muted' => $muteInfo && (
                $muteInfo['muted_until'] === null || 
                new \DateTimeImmutable($muteInfo['muted_until']) > new \DateTimeImmutable()
            )
        ];
    }
}