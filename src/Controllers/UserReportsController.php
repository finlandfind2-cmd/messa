<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Repos\UsersRepository;
use Messa\Repos\UserReportsRepository;

final class UserReportsController extends BaseController
{
    /**
     * POST /v1/users/report
     * body: { "user_id": 123, "reason": "spam", "comment": "..." }
     */
    public function report(Request $req, Response $res): array
    {
        $reporterId = $this->requireAuth($req);
        $body       = $req->json();

        $targetId = (int)($body['user_id'] ?? 0);
        $reason   = (string)($body['reason'] ?? 'other');
        $comment  = isset($body['comment']) ? trim((string)$body['comment']) : null;

        if ($targetId <= 0 || $targetId === $reporterId) {
            throw new UnprocessableException('invalid_user_id');
        }

        $allowed = ['spam','abuse','nsfw','other'];
        if (!in_array($reason, $allowed, true)) {
            throw new UnprocessableException('invalid_reason');
        }

        if ($comment !== null && mb_strlen($comment, 'UTF-8') > 2000) {
            throw new UnprocessableException('comment_too_long');
        }

        $usersRepo = new UsersRepository();
        if (!$usersRepo->findById($targetId)) {
            throw new NotFoundException('user_not_found');
        }

        $repo = new UserReportsRepository();
        $id   = $repo->create($reporterId, $targetId, $reason, $comment);

        return ['status' => 'ok', 'report_id' => $id];
    }
}
