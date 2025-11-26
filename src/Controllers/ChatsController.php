<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\ForbiddenException;
use Messa\Repos\BlockedContactsRepository;
use Messa\Repos\ChatsRepository;
use Messa\Repos\UsersRepository;
use Messa\Repos\ChatMembersRepository;
use Messa\Repos\MessagesRepository;

final class ChatsController extends BaseController
{
    /**
     * GET /v1/chats/{id}
     */
    public function get(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);

        $repo = new ChatsRepository();
        $chat = $repo->getById($chatId);
        if (!$chat) {
            throw new NotFoundException('chat_not_found');
        }

        $cmRepo = new ChatMembersRepository();
        $role = $cmRepo->role($chatId, $userId);
        if ($role === null) {
            throw new NotFoundException('chat_not_found');
        }

        return $this->buildChatPayload($chat, $userId);
    }

    /**
     * Создание группы или канала.
     *
     * POST /v1/chats
     * {
     *   "type": "group" | "channel",
     *   "title": "Название",
     *   "visibility": "private" | "public",      // по умолчанию "private"
     *   "allow_invites": true|false,            // по умолчанию true
     *   "history_visibility": "all"|"since_join"|"none", // по умолчанию "all"
     *   "member_ids": [2,3,4]                   // доп. участники (создатель добавляется сам)
     * }
     */
    public function create(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);

        // Простая идемпотентность по Idempotency-Key
        $this->enforceIdempotency($req, 'chat_create:' . (string)$userId, 60);

        // Берём только ожидаемые ключи
        $body = $this->arrayWhitelist($req->json(), [
            'type',
            'title',
            'visibility',
            'allow_invites',
            'history_visibility',
            'member_ids',
            'avatar_key'
        ]);

        $type = (string)($body['type'] ?? 'group');
        if ($type === 'dialog' || $type === 'direct') {
            // для direct есть отдельный эндпоинт
            throw new UnprocessableException('invalid_chat_type');
        }
        if (!in_array($type, ['group', 'channel'], true)) {
            throw new UnprocessableException('invalid_chat_type');
        }

        $rawTitle = (string)($body['title'] ?? '');
        $title = trim(preg_replace('/[\r\n]+/u', ' ', $rawTitle));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
            throw new UnprocessableException('invalid_title');
        }

        $visibility = (string)($body['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'public'], true)) {
            throw new UnprocessableException('invalid_visibility');
        }

        $allowInvites = array_key_exists('allow_invites', $body)
            ? (bool)$body['allow_invites']
            : true;

        $historyVisibility = (string)($body['history_visibility'] ?? '');
        if ($historyVisibility === '') {
            $historyVisibility = 'all';
        }
        if (!in_array($historyVisibility, ['all', 'since_join', 'none'], true)) {
            throw new UnprocessableException('invalid_history_visibility');
        }

        // Сбор и грубая очистка member_ids
        $memberIds = [];
        if (isset($body['member_ids']) && is_array($body['member_ids'])) {
            foreach ($body['member_ids'] as $rawId) {
                $id = (int)$rawId;
                if ($id > 0 && $id !== $userId) {
                    $memberIds[] = $id;
                }
            }
        }
        $memberIds = array_values(array_unique($memberIds));

        // Фильтруем по реально существующим пользователям
        if ($memberIds !== []) {
            $usersRepo = new UsersRepository();
            $memberIds = $usersRepo->filterExistingIds($memberIds);
        }

        $avatarKey = isset($body['avatar_key']) ? (string)$body['avatar_key'] : null;

        $repo = new ChatsRepository();
        $chatId = $repo->createGroupOrChannel(
            $userId,
            $type,
            $title,
            $visibility,
            $historyVisibility,
            $allowInvites,
            $memberIds,
            $avatarKey
        );

        // Системное сообщение о создании чата (но без дубля участников)
        $kind = $type === 'channel' ? 'канал' : 'группу';
        $text = "создал(а) {$kind} \"{$title}\"";
        (new MessagesRepository())->insertSystemFanout($chatId, $userId, $text);

        $chat = $repo->getById($chatId);
        if (!$chat) {
            throw new NotFoundException('chat_not_found');
        }

        return $this->buildChatPayload($chat, $userId);
    }

    /**
     * Создать или найти direct-чат с пользователем.
     * В теле: { "peer_id": 123 }
     *
     * POST /v1/chats/direct
     */
    public function createDirect(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $body = $req->json();

        $peerId = isset($body['peer_id']) ? (int)$body['peer_id'] : 0;
        if ($peerId <= 0) {
            throw new UnprocessableException('invalid_peer_id');
        }
        if ($peerId === $userId) {
            throw new UnprocessableException('self_direct_not_allowed');
        }

        $usersRepo = new UsersRepository();
        $peer = $usersRepo->findById($peerId);
        if (!$peer) {
            throw new NotFoundException('user_not_found');
        }

        // >>> БЛОКИРОВКА: direct с заблокированным не создаём
        $blockedRepo = new BlockedContactsRepository();
        if (
            $blockedRepo->isBlocked($userId, $peerId) ||     // я заблокировал его
            $blockedRepo->isBlocked($peerId, $userId)        // он заблокировал меня
        ) {
            throw new ForbiddenException('blocked_contact');
        }
        // <<< КОНЕЦ БЛОКИРОВКИ

        $repo = new ChatsRepository();
        $chatId = $repo->findOrCreateDirect($userId, $peerId);
        $chat = $repo->getById($chatId);

        if (!$chat) {
            throw new NotFoundException('chat_creation_failed');
        }

        return $this->buildChatPayload($chat, $userId);
    }


    private function buildChatPayload(array $chat, int $userId): array
    {
        $result = [
            'chat' => [
                'id' => (int)$chat['id'],
                'type' => (string)$chat['type'],
                'title' => $chat['title'],
                'owner_id' => (int)$chat['created_by'],
                'history_visibility' => $chat['history_visibility'],
                'allow_invites' => (bool)$chat['allow_invites'],
                'visibility' => $chat['visibility'] ?? 'private',
                'is_encrypted' => isset($chat['is_encrypted']) ? (bool)$chat['is_encrypted'] : true,
                'last_message_id' => $chat['last_message_id'],
                'last_sender_id' => $chat['last_sender_id'],
                'last_preview' => $chat['last_preview'],
                'last_event_at' => $chat['last_event_at'],
            ],
        ];

        // Для direct-чатов добавляем информацию о собеседнике
        if ($chat['type'] === 'direct') {
            $repo = new ChatsRepository();
            $peer = $repo->getDirectChatPeer((int)$chat['id'], $userId);

            if ($peer) {
                $result['peer'] = [
                    'id' => (int)$peer['id'],
                    'login' => $peer['login'],
                    'display_name' => $peer['display_name'],
                    'avatar_key' => $peer['avatar_key'],
                    'last_seen_at' => $peer['last_login_at'],
                ];
            }
        }

        return $result;
    }

    /**
     * PATCH /v1/chats/{id}
     */
    public function patch(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $memRepo = new ChatMembersRepository();
        if (!$memRepo->hasAtLeast($chatId, $uid, 'admin')) {
            throw new ForbiddenException('insufficient_role');
        }

        $b = $req->json();
        $title = isset($b['title']) ? trim((string)$b['title']) : null;
        $hv = $b['history_visibility'] ?? null;
        $inv = $b['allow_invites'] ?? null;

        if ($title !== null && ($title === '' || mb_strlen($title, 'UTF-8') > 200)) {
            throw new UnprocessableException('invalid_title');
        }
        if ($hv !== null && !in_array($hv, ['all', 'since_join', 'none'], true)) {
            throw new UnprocessableException('invalid_history_visibility');
        }
        if ($inv !== null) {
            $inv = (int)(!!$inv);
        }

        $repo = new ChatsRepository();
        $repo->patchSettings($chatId, $title, $hv, $inv);

        return ['status' => 'ok'];
    }

    /**
     * DELETE /v1/chats/{id}/history
     * Очистка истории приватного диалога (direct) для обоих участников.
     *
     * После вызова:
     * - чат, сообщения, участники удалены;
     * - контакт в "Моих контактах" сохраняется.
     */
    public function clearHistory(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);

        if ($chatId <= 0) {
            throw new UnprocessableException('invalid_chat_id');
        }

        $chatsRepo = new ChatsRepository();
        $chat = $chatsRepo->getById($chatId);
        if (!$chat) {
            throw new NotFoundException('chat_not_found');
        }

        if ($chat['type'] !== 'direct') {
            // Для групп/каналов отдельная логика, здесь строго direct
            throw new UnprocessableException('only_direct_chat_history_can_be_cleared');
        }

        $membersRepo = new ChatMembersRepository();
        $role = $membersRepo->role($chatId, $userId);
        if ($role === null) {
            // Пользователь не участник — для него этот чат не существует
            throw new NotFoundException('chat_not_found');
        }

        // Удаляем чат целиком: сообщения, участников и прочее
        $chatsRepo->deleteDirectHard($chatId);

        return ['status' => 'ok'];
    }
}
