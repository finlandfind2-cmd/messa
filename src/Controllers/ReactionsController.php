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
use Messa\Repos\MessagesRepository;
use Messa\Repos\ReactionsRepository;

final class ReactionsController extends BaseController
{
    public function put(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $msgId = (int)$req->param('id', 0);
        $emoji = (string)($req->json()['emoji'] ?? '');
        $emoji = trim(rawurldecode($emoji));
        if ($emoji === '' || $this->gLen($emoji) > 8) {
            throw new UnprocessableException('invalid_emoji');
        }
        $repoMsg = new MessagesRepository();
        $cid = $repoMsg->getChatIdByMessageId($msgId);
        if ($cid === null || !$repoMsg->isMember($cid, $uid)) {
            throw new NotFoundException('message_not_found');
        }
        $cnt = (new ReactionsRepository())->add($msgId, $uid, $emoji);
        return ['message_id' => $msgId, 'counts' => $cnt];
    }

    public function delete(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $msgId = (int)$req->param('id', 0);
        $emoji = (string)$req->param('emoji', '');
        $emoji = trim(rawurldecode($emoji));
        if ($emoji === '' || $this->gLen($emoji) > 8) {
            throw new UnprocessableException('invalid_emoji');
        }
        $repoMsg = new MessagesRepository();
        $cid = $repoMsg->getChatIdByMessageId($msgId);
        if ($cid === null || !$repoMsg->isMember($cid, $uid)) {
            throw new NotFoundException('message_not_found');
        }
        $cnt = (new ReactionsRepository())->remove($msgId, $uid, $emoji);
        return ['message_id' => $msgId, 'counts' => $cnt];
    }

    private function gLen(string $s): int
    {
        if (function_exists('grapheme_strlen')) {
            return (int)grapheme_strlen($s);
        }
        return (int)mb_strlen($s, 'UTF-8');
    }
}
