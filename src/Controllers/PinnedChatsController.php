<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Repos\PinnedChatsRepository;
use Messa\Repos\ChatMembersRepository;

final class PinnedChatsController extends BaseController
{
    public function pin(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)($req->json()['chat_id'] ?? 0);
        
        if ($chatId <= 0) {
            throw new \Messa\Http\Exceptions\UnprocessableException('invalid_chat_id');
        }

        // Проверяем, что пользователь состоит в чате
        $membersRepo = new ChatMembersRepository();
        if (!$membersRepo->role($chatId, $userId)) {
            throw new \Messa\Http\Exceptions\ForbiddenException('not_chat_member');
        }

        $repo = new PinnedChatsRepository();
        $repo->pinChat($userId, $chatId);
        
        return ['status' => 'ok'];
    }

    public function unpin(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)($req->json()['chat_id'] ?? 0);
        
        if ($chatId <= 0) {
            throw new \Messa\Http\Exceptions\UnprocessableException('invalid_chat_id');
        }

        $repo = new PinnedChatsRepository();
        $repo->unpinChat($userId, $chatId);
        
        return ['status' => 'ok'];
    }

    public function list(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        
        $repo = new PinnedChatsRepository();
        $pinnedChats = $repo->getPinnedChats($userId);
        
        return ['pinned_chats' => $pinnedChats];
    }

    public function reorder(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatIds = $req->json()['chat_ids'] ?? [];
        
        if (!is_array($chatIds)) {
            throw new \Messa\Http\Exceptions\UnprocessableException('invalid_chat_ids');
        }

        $repo = new PinnedChatsRepository();
        $repo->reorderPinnedChats($userId, $chatIds);
        
        return ['status' => 'ok'];
    }
}