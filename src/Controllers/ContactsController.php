<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Repos\ContactsRepository;
use Messa\Repos\UsersRepository;

final class ContactsController extends BaseController
{
    public function list(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $limit = max(1, min(200, (int)($req->query('limit') ?? 50)));
        $offset = max(0, (int)($req->query('offset') ?? 0));
        
        $repo = new ContactsRepository();
        $contacts = $repo->getUserContacts($userId, $limit, $offset);
        
        return [
            'status' => 'ok',
            'contacts' => $contacts,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => count($contacts) === $limit
            ]
        ];
    }
    
    public function delete(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $peerId = (int)$req->param('peer_id', 0);
        
        if ($peerId <= 0) {
            throw new UnprocessableException('invalid_peer_id');
        }
        
        $repo = new ContactsRepository();
        $success = $repo->softDeleteContact($userId, $peerId);
        
        return ['status' => $success ? 'ok' : 'error'];
    }
    
    public function searchUsers(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $query = trim((string)($req->query('q') ?? ''));
        $limit = max(1, min(50, (int)($req->query('limit') ?? 20)));
        
        if (mb_strlen($query) < 2) {
            throw new UnprocessableException('query_too_short');
        }
        
        $usersRepo = new UsersRepository();
        $results = $usersRepo->searchUsers($query, $limit);
        
        // Фильтруем самого себя из результатов
        $results = array_filter($results, function($user) use ($userId) {
            return (int)$user['id'] !== $userId;
        });
        
        return [
            'status' => 'ok',
            'results' => array_values($results),
            'query' => $query
        ];
    }
}