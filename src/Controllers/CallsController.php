<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\ForbiddenException;
use Messa\Services\Telemost\TelemostService;
use Messa\Repos\CallsRepository;
use Messa\Security\RateLimiter;
use Messa\Core\ConfigHelper;
use Messa\Repos\OAuthYandexRepository;

final class CallsController extends BaseController
{
    public function create(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $b = $req->json();
        
        $chatId = (int)($b['chat_id'] ?? 0);
        $titleRaw = (string)($b['title'] ?? 'MESSA call');
        $expires = isset($b['expires_sec']) ? (int)$b['expires_sec'] : null;
        
        // Нормализация title: убираем переводы строк, обрезаем
        $title = trim($titleRaw);
        $title = preg_replace('/[\r\n]+/u', ' ', $title);
        $title = mb_substr($title, 0, 120, 'UTF-8');

        // Ограничиваем expires_sec, если задан
        if ($expires !== null) {
            if ($expires < 60)   $expires = 60;       // минимум 1 мин
            if ($expires > 86400) $expires = 86400;   // максимум 24 часа
        }

        if ($chatId <= 0) {
            throw new UnprocessableException('invalid_chat_id');
        }

        $role = $this->getUserRoleInChat($chatId, $userId);
        if ($role === null) {
            throw new ForbiddenException('not_in_chat');
        }
        
        if (!in_array($role, ['owner','admin','editor'], true)) {
            throw new ForbiddenException('insufficient_role');
        }

        // Ранняя проверка: если уже есть активный звонок — не создаём комнату в Telemost
        $repo = new CallsRepository();
        $activeCallsPre = $repo->getActiveByChat($chatId);
        if (!empty($activeCallsPre)) {
            throw new \Messa\Http\Exceptions\ConflictException('active_call_exists');
        }

        // RL per-user-per-chat
        $cap = ConfigHelper::getInt('CALLS_CREATE_CAPACITY', 2);
        $perMin = ConfigHelper::getInt('CALLS_CREATE_REFILL_PER_MIN', 2);
        $refill = $perMin / 60.0;
        
        [$ok, $rem] = (new RateLimiter())->allow('calls_create', "u:$userId:chat:$chatId", $cap, $refill, 1.0);
        if (!$ok) {
            throw new \Messa\Http\Exceptions\RateLimitedException('rate_limited');
        }

        // RL per-chat (все участники суммарно)
        $capC = ConfigHelper::getInt('CALLS_CREATE_CHAT_CAPACITY', 5);
        $perMinC = ConfigHelper::getInt('CALLS_CREATE_CHAT_REFILL_PER_MIN', 1);
        $refillC = $perMinC / 60.0;
        
        [$okC, $remC] = (new RateLimiter())->allow('calls_create_chat', "chat:$chatId", $capC, $refillC, 1.0);
        if (!$okC) {
            throw new \Messa\Http\Exceptions\RateLimitedException('rate_limited');
        }

        // Получаем OAuth-токен Яндекса для пользователя (получен в OAuth flow и сохранён репозиторием)
        $repoOauth  = new \Messa\Repos\OAuthYandexRepository();
        $oauth = new \Messa\Services\Telemost\OAuthService();
        $tok = $repoOauth->getByUser($userId);
        if (!$tok || !isset($tok['access_token'])) {
            throw new \Messa\Http\Exceptions\ForbiddenException('telemost_not_authorized');
        }

        // Создаём конференцию через Telemost c OAuth-токеном
        $tm = new \Messa\Services\Telemost\TelemostService();
        try {
            $room = $tm->createConference((string)$tok['access_token'], $title, $expires);
        } catch (\RuntimeException $e) {
            // пробуем рефреш только на 401/403
            if (str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), '403')) {
                if (!empty($tok['refresh_token'])) {
                    try {
                        $newTok = $oauth->refresh((string)$tok['refresh_token']);
                        $repoOauth->upsert($userId, $newTok);
                        // порядок аргументов: (accessToken, title, expires)
                        $room = $tm->createConference((string)($newTok['access_token'] ?? ''), $title, $expires);
                    } catch (\Throwable $e2) {
                        throw new \Messa\Http\Exceptions\ForbiddenException('telemost_token_expired');
                    }
                } else {
                    throw new \Messa\Http\Exceptions\ForbiddenException('telemost_token_expired');
                }
            } else {
                // graceful degradation на временные ошибки Telemost (429/5xx)
                if (preg_match('/\\bcode=(429|5\\d\\d)\\b/', $e->getMessage())) {
                    $res->setStatus(503);
                    $res->header('Retry-After', '30');
                    return [
                        'error' => [
                            'code' => 'telemost_unavailable',
                            'message' => 'Telemost is temporarily unavailable. Please retry shortly.',
                            'retry_after_sec' => 30
                        ]
                    ];
                }
                throw $e;
            }
        }

        // Финальная проверка на активный звонок перед записью в БД
        $activeCalls = $repo->getActiveByChat($chatId);
        if (!empty($activeCalls)) {
            throw new \Messa\Http\Exceptions\ConflictException('active_call_exists');
        }
        
        $callId = $repo->create($chatId, $userId, $room['room_id'], $room['join_url']);

        $pub = new UpdatesController();
        $pub->publishEventToChat($chatId, 'call_started', [
            'call_id' => $callId,
            'link' => $room['join_url'],
            'by' => $userId
        ]);

        $repo->addParticipant($callId, $userId);

        return [
            'status' => 'ok',
            'call' => [
                'id' => $callId,
                'chat_id' => $chatId,
                'initiator_id' => $userId,
                'telemost_room_id' => $room['room_id'],
                'link' => $room['join_url'],
                'started_at' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            ]
        ];
    }

    public function get(Request $req, Response $res, array $args): array
    {
        $userId = $this->requireAuth($req);
        $id = (int)($args['id'] ?? 0);
        
        $repo = new CallsRepository();
        $call = $repo->getById($id);
        
        if (!$call) {
            throw new \Messa\Http\Exceptions\NotFoundException('call_not_found');
        }
        
        $role = $this->getUserRoleInChat((int)$call['chat_id'], $userId);
        if ($role === null) {
            throw new ForbiddenException('not_in_chat');
        }
        
        return ['status'=>'ok','call'=>$call];
    }

    public function listByChat(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)($req->query('chat_id') ?? 0);
        
        if (!$this->getUserRoleInChat($chatId, $userId)) {
            throw new ForbiddenException('not_in_chat');
        }
        
        $repo = new CallsRepository();
        $calls = $repo->getActiveByChat($chatId, 50); // последние 50 звонков
        
        return ['status' => 'ok', 'calls' => $calls];
    }

    public function end(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $callId = (int)($req->json()['call_id'] ?? 0);
        
        $repo = new CallsRepository();
        $call = $repo->getById($callId);
        
        if (!$call) {
            throw new \Messa\Http\Exceptions\NotFoundException('call_not_found');
        }
        
        // Только инициатор или админ чата может завершить
        $canEnd = ((int)$call['initiator_id'] === $userId) || 
                $this->isChatAdmin((int)$call['chat_id'], $userId);
        
        if (!$canEnd) {
            throw new ForbiddenException('not_allowed_to_end');
        }
        
        $repo->endCall($callId);
        
        // Уведомление участников
        $pub = new UpdatesController();
        $pub->publishEventToChat((int)$call['chat_id'], 'call_ended', [
            'call_id' => $callId,
            'by' => $userId
        ]);
        
        return ['status' => 'ok'];
    }

    private function isChatAdmin(int $chatId, int $userId): bool
    {
        $role = $this->getUserRoleInChat($chatId, $userId);
        return in_array($role, ['owner', 'admin'], true);
    }

    private function getUserRoleInChat(int $chatId, int $userId): ?string
    {
        $pdo = \Messa\Core\Db::pdo();
        $st = $pdo->prepare("SELECT role FROM chat_members WHERE chat_id=? AND user_id=? LIMIT 1");
        $st->execute([$chatId, $userId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? (string)$r['role'] : null;
    }
}