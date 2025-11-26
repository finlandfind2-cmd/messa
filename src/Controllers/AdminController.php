<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Repos\AdminRepository;

final class AdminController extends BaseController
{
    public function users(Request $req, Response $res): array
    {
        $this->requireAdmin($req);
        [$limit, $after] = $this->validatePaginationParams($req);
        
        $q = trim((string)($req->query('q') ?? ''));
        $repo = new AdminRepository();
        
        $out = $repo->listUsers([
            'limit' => $limit,
            'after' => $after,
            'q' => $q !== '' ? $q : null,
        ]);
        
        return ['status' => 'ok'] + $out;
    }

    public function chats(Request $req, Response $res): array
    {
        $this->requireAdmin($req);
        [$limit, $after] = $this->validatePaginationParams($req);
        
        $q = trim((string)($req->query('q') ?? ''));
        $ownerId = $this->asIntOrNull($req->query('owner_id'));
        $repo = new AdminRepository();
        
        $out = $repo->listChats([
            'limit' => $limit,
            'after' => $after,
            'q' => $q !== '' ? $q : null,
            'owner_id' => $ownerId,
        ]);
        
        return ['status' => 'ok'] + $out;
    }

    public function messages(Request $req, Response $res): array
    {
        $this->requireAdmin($req);
        [$limit, $after] = $this->validatePaginationParams($req);
        
        $chatId = $this->asIntOrNull($req->query('chat_id'));
        $senderId = $this->asIntOrNull($req->query('sender_id'));
        $since = $req->query('since');
        $until = $req->query('until');
        
        $repo = new AdminRepository();
        $out = $repo->listMessages([
            'limit' => $limit,
            'after' => $after,
            'chat_id' => $chatId,
            'sender_id' => $senderId,
            'since' => $since,
            'until' => $until,
        ]);
        
        return ['status' => 'ok'] + $out;
    }
}