<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\ForbiddenException;
use Messa\Services\JwtService;
use Messa\Repos\ChatMembersRepository;
use Messa\Repos\MessagesRepository;
use Messa\Repos\ChatsRepository;
use Messa\Core\Db;
use Messa\Http\Exceptions\ConflictException;

final class ChatMembersController extends BaseController
{
    public function list(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $memRepo = new ChatMembersRepository();
        if (!$memRepo->role($chatId,$uid)) throw new NotFoundException('chat_not_found');
        return ['members'=>$memRepo->listMembers($chatId)];
    }

    public function add(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $b = $req->json();
        $targetId = (int)($b['user_id'] ?? 0);
        $role = (string)($b['role'] ?? 'member');
        if ($targetId<=0 || !in_array($role,['member','editor','admin'],true)) {
            throw new UnprocessableException('invalid_payload');
        }

        $memRepo = new ChatMembersRepository();
        $curRole = $memRepo->role($chatId,$uid);
        if ($memRepo->role($chatId,$targetId) !== null) {
            throw new ConflictException('already_member');
        }
        if ($targetId === $uid) {
            throw new ForbiddenException('cant_invite_self');
        }

        // Администраторы/owner могут задавать роль явно,
        // все остальные всегда приглашают как 'member'
        $isAdminOrOwner = $memRepo->hasAtLeast($chatId,$uid,'admin');
        if (!$isAdminOrOwner) {
            $role = 'member';
        }

        $chat = (new ChatsRepository())->getById($chatId);
        $canInvite = ($chat && (int)$chat['allow_invites']===1 && in_array($curRole,['member','editor','admin','owner'],true))
                    || $isAdminOrOwner;
        if (!$canInvite) {
            throw new ForbiddenException('insufficient_role');
        }

        $memRepo->addMember($chatId,$targetId,$role);
        $this->system($chatId,$uid,"add_member",$targetId,$role);
        return ['status'=>'ok'];
    }

    public function remove(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $targetId = (int)($req->json()['user_id'] ?? 0);
        if ($targetId<=0) throw new UnprocessableException('invalid_user_id');
        if ($targetId === $uid) throw new ForbiddenException('cant_remove_self');

        $memRepo = new ChatMembersRepository();
        if (!$memRepo->hasAtLeast($chatId,$uid,'admin')) throw new ForbiddenException('insufficient_role');
        $tRole = $memRepo->role($chatId,$targetId);
        if ($tRole===null) throw new NotFoundException('member_not_found');
        if ($tRole==='owner') throw new ForbiddenException('cant_remove_owner');

        $memRepo->removeMember($chatId,$targetId);
        $this->system($chatId,$uid,"remove_member",$targetId,null);
        return ['status'=>'ok'];
    }

    public function setRole(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $targetId = (int)$req->param('user_id', 0);
        $role = (string)($req->json()['role'] ?? '');
        if (!in_array($role,['member','editor','admin'],true)) throw new UnprocessableException('invalid_role');
        $memRepo = new ChatMembersRepository();
        if (!$memRepo->hasAtLeast($chatId,$uid,'admin')) throw new ForbiddenException('insufficient_role');
        $tRole = $memRepo->role($chatId,$targetId);
        if ($tRole===null) throw new NotFoundException('member_not_found');
        if ($tRole==='owner') throw new ForbiddenException('cant_change_owner_role');
        $memRepo->setRole($chatId,$targetId,$role);
        $this->system($chatId,$uid,"change_role",$targetId,$role);
        return ['status'=>'ok'];
    }

    public function transferOwnership(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $newOwner = (int)($req->json()['new_owner_id'] ?? 0);
        if ($newOwner<=0) throw new UnprocessableException('invalid_new_owner_id');
        if ($newOwner === $uid) throw new UnprocessableException('same_owner');

        $memRepo = new ChatMembersRepository();
        if (!$memRepo->hasAtLeast($chatId,$uid,'owner')) throw new ForbiddenException('only_owner');
        if ($memRepo->role($chatId,$newOwner)===null) throw new NotFoundException('member_not_found');

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $repoChats = new ChatsRepository();
            $repoChats->transferOwner($chatId,$newOwner);
            $memRepo->setRole($chatId,$uid,'admin');
            $memRepo->setRole($chatId,$newOwner,'owner');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->system($chatId,$uid,"transfer_owner",$newOwner,null);
        return ['status'=>'ok'];
    }

    private function system(int $chatId, int $actor, string $event, int $targetId, ?string $role): void
    {
        $login = $this->loginOf($targetId);
        $map = [
            'add_member' => "пригласил(а) @$login (роль: ".($role??'member').")",
            'remove_member' => "удалил(а) @$login из чата",
            'change_role' => "назначил(а) @$login роль ".($role??'member'),
            'transfer_owner' => "передал(а) владение @$login",
        ];
        $text = $map[$event] ?? 'событие';
        (new MessagesRepository())->insertSystemFanout($chatId, $actor, $text);
    }
    private function loginOf(int $userId): string {
        $pdo = \Messa\Core\Db::pdo();
        $st=$pdo->prepare("SELECT login FROM users WHERE id=? LIMIT 1"); $st->execute([$userId]);
        return (string)($st->fetchColumn() ?: ("user".$userId));
    }
}
