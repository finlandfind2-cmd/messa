<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Repos\UsersRepository;
use Messa\Repos\PresenceRepository;

final class PresenceController extends BaseController
{
    /**
     * GET /v1/users/{id}/presence
     */
    public function getPresence(Request $req, Response $res, array $args): array
    {
        $viewerId = $this->requireAuth($req);
        $userId   = (int)($args['id'] ?? 0);

        if ($userId <= 0) {
            throw new NotFoundException('user_not_found');
        }

        $usersRepo = new UsersRepository();
        $user      = $usersRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('user_not_found');
        }

        // Здесь в будущем надо учитывать privacy "кто может видеть онлайн"
        $presenceRepo = new PresenceRepository();

        $lastOnline = $presenceRepo->getLastOnline($userId);

        return [
            'user_id'    => $userId,
            'last_online'=> $lastOnline,
        ];
    }

    /**
     * GET /v1/users/{id}/presence/history?limit=&offset=
     */
    public function getHistory(Request $req, Response $res, array $args): array
    {
        $viewerId = $this->requireAuth($req);
        $userId   = (int)($args['id'] ?? 0);

        if ($userId <= 0) {
            throw new NotFoundException('user_not_found');
        }

        $limit  = (int)($req->query('limit') ?? 50);
        $offset = (int)($req->query('offset') ?? 0);

        $presenceRepo = new PresenceRepository();
        $history      = $presenceRepo->getHistory($userId, $limit, $offset);

        return [
            'user_id' => $userId,
            'items'   => $history,
            'limit'   => $limit,
            'offset'  => $offset,
        ];
    }
}
