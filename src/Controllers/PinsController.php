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
use Messa\Repos\PinsRepository;

final class PinsController extends BaseController
{
    public function add(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $msgId = (int)($req->json()['message_id'] ?? 0);
        if ($msgId <= 0) throw new UnprocessableException('invalid_message_id');

        $repoMsg = new MessagesRepository();
        if (!$repoMsg->isMember($chatId, $uid)) throw new NotFoundException('chat_not_found');

        $mCid = $repoMsg->getChatIdByMessageId($msgId);
        if ($mCid !== $chatId) throw new NotFoundException('message_not_found');

        $pins = new PinsRepository();
        if (!$pins->userCanPin($chatId, $uid)) throw new ForbiddenException('insufficient_role');
        $pins->add($chatId, $msgId, $uid);
        return ['status' => 'ok'];
    }

    public function remove(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $msgId = (int)$req->param('message_id', 0);

        $repoMsg = new MessagesRepository();
        if (!$repoMsg->isMember($chatId, $uid)) throw new NotFoundException('chat_not_found');
        $mCid = $repoMsg->getChatIdByMessageId($msgId);
        if ($mCid !== $chatId) throw new NotFoundException('message_not_found');

        $pins = new PinsRepository();
        if (!$pins->userCanPin($chatId, $uid)) throw new ForbiddenException('insufficient_role');
        $pins->remove($chatId, $msgId);
        return ['status' => 'ok'];
    }
}
