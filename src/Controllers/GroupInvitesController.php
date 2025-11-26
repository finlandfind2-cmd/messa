<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\ForbiddenException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Repos\ChatsRepository;
use Messa\Repos\ChatMembersRepository;
use Messa\Repos\GroupInvitesRepository;

final class GroupInvitesController extends BaseController
{
    /**
     * POST /v1/chats/{id}/invites
     * body: { "ttl_sec": 86400|null, "max_uses": 100|null }
     */
    public function create(Request $req, Response $res, array $args): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)($args['id'] ?? 0);

        if ($chatId <= 0) {
            throw new NotFoundException('chat_not_found');
        }

        $body     = $req->json();
        $ttlSec   = isset($body['ttl_sec']) ? (int)$body['ttl_sec'] : null;
        $maxUses  = isset($body['max_uses']) ? (int)$body['max_uses'] : null;

        $chatsRepo = new ChatsRepository();
        $chat      = $chatsRepo->getById($chatId);
        if (!$chat || $chat['type'] !== 'group') {
            throw new NotFoundException('chat_not_found');
        }

        $memRepo = new ChatMembersRepository();
        $role    = $memRepo->role($chatId, $userId);

        if ($role === null) {
            throw new ForbiddenException('not_member');
        }

        // Право создавать инвайты:
        // - если allow_invites=0 → только owner/admin
        // - если allow_invites=1 → любой участник
        if (!$chat['allow_invites'] && !in_array($role, ['owner', 'admin'], true)) {
            throw new ForbiddenException('invites_not_allowed');
        }

        $invRepo = new GroupInvitesRepository();
        $invite  = $invRepo->create($chatId, $userId, $ttlSec, $maxUses);

        return [
            'invite' => [
                'id'         => $invite['id'],
                'chat_id'    => $invite['chat_id'],
                'token'      => $invite['token'],
                'url'        => $this->buildInviteUrl($invite['token']),
                'created_by' => $invite['created_by'],
                'expires_at' => $invite['expires_at'],
                'max_uses'   => $invite['max_uses'],
                'used_count' => $invite['used_count'],
            ],
        ];
    }

    /**
     * GET /v1/chats/{id}/invites
     */
    public function list(Request $req, Response $res, array $args): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)($args['id'] ?? 0);

        if ($chatId <= 0) {
            throw new NotFoundException('chat_not_found');
        }

        $chatsRepo = new ChatsRepository();
        $chat      = $chatsRepo->getById($chatId);
        if (!$chat || $chat['type'] !== 'group') {
            throw new NotFoundException('chat_not_found');
        }

        $memRepo = new ChatMembersRepository();
        $role    = $memRepo->role($chatId, $userId);
        if ($role === null || !in_array($role, ['owner', 'admin'], true)) {
            throw new ForbiddenException('invites_view_forbidden');
        }

        $invRepo = new GroupInvitesRepository();
        $items   = $invRepo->listByChat($chatId);

        $result = [];
        foreach ($items as $inv) {
            $result[] = [
                'id'         => $inv['id'],
                'chat_id'    => $inv['chat_id'],
                'token'      => $inv['token'],
                'url'        => $this->buildInviteUrl($inv['token']),
                'created_by' => $inv['created_by'],
                'created_at' => $inv['created_at'],
                'expires_at' => $inv['expires_at'],
                'max_uses'   => $inv['max_uses'],
                'used_count' => $inv['used_count'],
            ];
        }

        return ['items' => $result];
    }

    /**
     * DELETE /v1/chats/{chat_id}/invites/{invite_id}
     */
    public function revoke(Request $req, Response $res, array $args): array
    {
        $userId   = $this->requireAuth($req);
        $chatId   = (int)($args['id'] ?? 0);
        $inviteId = (int)($args['invite_id'] ?? 0);

        if ($chatId <= 0 || $inviteId <= 0) {
            throw new NotFoundException('not_found');
        }

        $chatsRepo = new ChatsRepository();
        $chat      = $chatsRepo->getById($chatId);
        if (!$chat || $chat['type'] !== 'group') {
            throw new NotFoundException('chat_not_found');
        }

        $memRepo = new ChatMembersRepository();
        $role    = $memRepo->role($chatId, $userId);
        if ($role === null || !in_array($role, ['owner', 'admin'], true)) {
            throw new ForbiddenException('invites_revoke_forbidden');
        }

        $invRepo = new GroupInvitesRepository();
        $invRepo->revoke($inviteId);

        return ['status' => 'ok'];
    }

    /**
     * POST /v1/chats/join_by_invite
     * body: { "token": "..." }
     */
    public function joinByInvite(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $body   = $req->json();
        $token  = trim((string)($body['token'] ?? ''));

        if ($token === '') {
            throw new UnprocessableException('invalid_token');
        }

        $invRepo  = new GroupInvitesRepository();
        $chatsRepo = new ChatsRepository();
        $memRepo   = new ChatMembersRepository();

        $inv = $invRepo->getByToken($token);
        if (!$inv) {
            throw new NotFoundException('invite_not_found');
        }

        $chat = $chatsRepo->getById($inv['chat_id']);
        if (!$chat || $chat['type'] !== 'group') {
            throw new NotFoundException('chat_not_found');
        }

        // проверка срока
        if ($inv['expires_at'] !== null && new \DateTimeImmutable($inv['expires_at']) < new \DateTimeImmutable()) {
            throw new ForbiddenException('invite_expired');
        }

        // проверка лимита
        if ($inv['max_uses'] !== null && $inv['used_count'] >= $inv['max_uses']) {
            throw new ForbiddenException('invite_limit_reached');
        }

        // если уже участник — просто вернуть чат
        $role = $memRepo->role($chat['id'], $userId);
        if ($role === null) {
            $memRepo->addMember($chat['id'], $userId, 'member');
            $invRepo->incrementUsage($inv['id']);
        }

        return [
            'chat' => [
                'id'            => (int)$chat['id'],
                'type'          => $chat['type'],
                'title'         => $chat['title'],
                'avatar_key'    => $chat['avatar_key'] ?? null,
                'visibility'    => $chat['visibility'],
                'is_encrypted'  => (bool)$chat['is_encrypted'],
                'last_event_at' => $chat['last_event_at'],
            ],
        ];
    }

    private function buildInviteUrl(string $token): string
    {
        $base = rtrim((string)($_ENV['APP_PUBLIC_URL'] ?? 'https://app.chatzee.ru'), '/');
        return $base . '/join/' . $token;
    }
}
