<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Repos\BlockedContactsRepository;
use Messa\Services\JwtService;
use Messa\Repos\ChatsRepository;

final class DialogsController extends BaseController
{
    /** GET /v1/dialogs?limit=&cursor= */
    public function list(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $limit  = max(1, min(100, (int)($req->query('limit') ?? 30)));
        $cursor = $req->query('cursor');

        $repo = new ChatsRepository();
        $data = $repo->listDialogs($uid, $limit, is_string($cursor) ? $cursor : null);

        // >>> ОПЦИОНАЛЬНАЯ ФИЛЬТРАЦИЯ ЗАБЛОКИРОВАННЫХ
        $blockedRepo = new BlockedContactsRepository();

        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = array_values(array_filter(
                $data['items'],
                function (array $item) use ($uid, $blockedRepo): bool {
                    // Оставляем всё, что не direct
                    if (($item['chat']['type'] ?? null) !== 'direct') {
                        return true;
                    }

                    $peer = $item['peer'] ?? null;
                    if (!$peer || !isset($peer['id'])) {
                        return true;
                    }

                    $peerId = (int)$peer['id'];

                    // Если Я заблокировал собеседника — диалог прячем
                    if ($blockedRepo->isBlocked($uid, $peerId)) {
                        return false;
                    }

                    // Если он заблокировал меня, а я нет — можно оставлять,
                    // отправка всё равно запрещена MessagesController'ом.
                    return true;
                }
            ));
        }
        // <<< КОНЕЦ ФИЛЬТРАЦИИ

        return $data;
    }

}
