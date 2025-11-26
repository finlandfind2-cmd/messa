<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\ForbiddenException;
use Messa\Repos\ChatMembersRepository;
use Messa\Repos\ChatsRepository;

final class GroupsController extends BaseController
{
    /**
     * GET /v1/groups/public/search?query=&limit=&offset=
     */
    public function searchPublic(Request $req, Response $res): array
    {
        $this->requireAuth($req); // просто проверяем токен

        $query  = trim((string)($req->query('query') ?? ''));
        $limit  = (int)($req->query('limit') ?? 20);
        $offset = (int)($req->query('offset') ?? 0);

        if ($query === '') {
            throw new UnprocessableException('empty_query');
        }

        $limit  = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $repo  = new ChatsRepository();
        $items = $repo->searchPublicGroups($query, $limit, $offset);

        return [
            'items'  => $items,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    public function joinPublic(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $body   = $req->json();
        $chatId = (int)($body['chat_id'] ?? 0);

        if ($chatId <= 0) {
            throw new UnprocessableException('invalid_chat_id');
        }

        $chatsRepo = new ChatsRepository();
        $chat      = $chatsRepo->getById($chatId);

        if (!$chat || $chat['type'] !== 'group' || $chat['visibility'] !== 'public') {
            throw new NotFoundException('public_group_not_found');
        }

        $memRepo = new ChatMembersRepository();

        // если уже участник — просто отдаём чат
        if ($memRepo->role($chatId, $userId) !== null) {
            return [
                'chat' => $this->buildChatPayloadForUser($chat, $userId),
            ];
        }

        // можно добавить ещё доп. проверки (бан в этой группе и т.п.)

        $memRepo->addMember($chatId, $userId, 'member');

        // можно кинуть системное сообщение "X присоединился к группе"

        return [
            'chat' => $this->buildChatPayloadForUser($chat, $userId),
        ];
    }

    /**
     * Лёгкий адаптер, чтобы не тащить весь ChatsController.
     */
    private function buildChatPayloadForUser(array $chat, int $userId): array
    {
        // можно вынести в общий helper, но тут — компактная версия
        return [
            'id'             => (int)$chat['id'],
            'type'           => (string)$chat['type'],
            'title'          => $chat['title'],
            'avatar_key'     => $chat['avatar_key'] ?? null,
            'owner_id'       => (int)$chat['created_by'],
            'history_visibility' => $chat['history_visibility'],
            'allow_invites'  => (bool)$chat['allow_invites'],
            'visibility'     => $chat['visibility'],
            'is_encrypted'   => (bool)$chat['is_encrypted'],
            'last_message_id'=> $chat['last_message_id'],
            'last_sender_id' => $chat['last_sender_id'],
            'last_preview'   => $chat['last_preview'],
            'last_event_at'  => $chat['last_event_at'],
        ];
    }
}
