<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Repos\UsersRepository;
use Messa\Repos\BlockedContactsRepository;

final class ContactsBlockController extends BaseController
{
    /**
     * POST /v1/contacts/block
     * body: { "user_id": 123 }
     */
    public function block(Request $req, Response $res): array
    {
        $userId   = $this->requireAuth($req);
        $body     = $req->json();
        $targetId = (int)($body['user_id'] ?? 0);

        if ($targetId <= 0 || $targetId === $userId) {
            throw new UnprocessableException('invalid_user_id');
        }

        $usersRepo = new UsersRepository();
        if (!$usersRepo->findById($targetId)) {
            throw new NotFoundException('user_not_found');
        }

        $repo = new BlockedContactsRepository();
        $repo->block($userId, $targetId);

        return ['status' => 'ok'];
    }

    /**
     * POST /v1/contacts/unblock
     * body: { "user_id": 123 }
     */
    public function unblock(Request $req, Response $res): array
    {
        $userId   = $this->requireAuth($req);
        $body     = $req->json();
        $targetId = (int)($body['user_id'] ?? 0);

        if ($targetId <= 0) {
            throw new UnprocessableException('invalid_user_id');
        }

        $repo = new BlockedContactsRepository();
        $repo->unblock($userId, $targetId);

        return ['status' => 'ok'];
    }

    /**
     * GET /v1/contacts/blocked
     */
    public function list(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);

        $repo = new BlockedContactsRepository();
        $items = $repo->list($userId);

        return ['items' => $items];
    }
}
