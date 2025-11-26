<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Отладочный вывод
function debug_log($message) {
    file_put_contents(__DIR__ . '/../debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

debug_log('Starting index.php');
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Core/Bootstrap.php';

use Messa\Core\Bootstrap;
use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Router;
use Messa\Http\JsonResponder;
use Messa\Http\MiddlewareRunner;
use Messa\Http\Middleware\ErrorHandler;
use Messa\Http\Middleware\Cors;
use Messa\Http\Middleware\MicroserviceProxy;
use Messa\Http\Middleware\AuthMiddleware;
use Messa\Http\Middleware\RateLimit;

// Контроллеры
use Messa\Controllers\HealthController;
use Messa\Controllers\AuthTelegramController;
use Messa\Controllers\AuthPasswordController;
use Messa\Controllers\PasswordResetController;
use Messa\Controllers\MeController;
use Messa\Controllers\SessionsController;
use Messa\Controllers\PasswordChangeController;
use Messa\Controllers\ContactsController;
use Messa\Controllers\DialogsController;
use Messa\Controllers\MessagesController;
use Messa\Controllers\ReactionsController;
use Messa\Controllers\PinsController;
use Messa\Controllers\MessageReadController;
use Messa\Controllers\ChatsController;
use Messa\Controllers\ChatMembersController;
use Messa\Controllers\PinnedChatsController;
use Messa\Controllers\ChatMuteController;
use Messa\Controllers\GroupsController;
use Messa\Controllers\GroupInvitesController;
use Messa\Controllers\PresenceController;
use Messa\Controllers\ContactsBlockController;
use Messa\Controllers\UserReportsController;
use Messa\Controllers\MediaController;

$router->post('/v1/auth/login', [AuthPasswordController::class, 'login']);
$router->post('/v1/auth/refresh', [AuthPasswordController::class, 'refresh']);
$router->post('/v1/auth/logout', [AuthPasswordController::class, 'logout']);

$router->post('/v1/auth/password/forgot/start', [PasswordResetController::class, 'start']);
$router->post('/v1/auth/password/forgot/confirm', [PasswordResetController::class, 'confirm']);

$router->get('/oauth/yandex/start', [YandexController::class, 'start']);
$router->get('/oauth/yandex/callback', [YandexController::class, 'callback']);

$router->post('/v1/bot/telegram/webhook', [TelegramWebhookController::class, 'handle']);

$router->get('/v1/me', [MeController::class, 'get']);
$router->patch('/v1/me', [MeController::class, 'patch']);

$router->get('/v1/me/sessions', [SessionsController::class, 'list']);
$router->delete('/v1/me/sessions/{id}', [SessionsController::class, 'delete']);
// rename device label
$router->post('/v1/me/password', [PasswordChangeController::class, 'change']);

// Контакты и поиск
$router->get('/v1/contacts', [ContactsController::class, 'list']);
$router->delete('/v1/contacts/{peer_id}', [ContactsController::class, 'delete']);
$router->get('/v1/users/search', [ContactsController::class, 'searchUsers']);

// Cтатус чатов (без звука и т.д.)
$router->post('/v1/chats/mute', [ChatMuteController::class, 'setMute']);
$router->get('/v1/chats/mute_status', [ChatMuteController::class, 'getMuteStatus']);

$router->post('/v1/chats', [ChatsController::class, 'create']);
$router->delete('/v1/chats/{id}/history', [ChatsController::class, 'clearHistory']);
$router->get('/v1/dialogs', [DialogsController::class, 'list']);
$router->post('/v1/chats/direct', [ChatsController::class, 'createDirect']);

// Закрепление чатов
$router->post('/v1/chats/pin', [PinnedChatsController::class, 'pin']);
$router->post('/v1/chats/unpin', [PinnedChatsController::class, 'unpin']);
$router->get('/v1/chats/pinned', [PinnedChatsController::class, 'list']);
$router->post('/v1/chats/reorder_pinned', [PinnedChatsController::class, 'reorder']);

$router->get('/v1/chats/{id}/messages', [MessagesController::class, 'list']);
$router->post('/v1/messages', [MessagesController::class, 'create']);
$router->post('/v1/messages/with_attachments', [MessagesController::class, 'createWithAttachments']);
$router->post('/v1/messages/forward', [MessagesController::class, 'forward']);
$router->patch('/v1/messages/{id}', [MessagesController::class, 'patch']);
$router->delete('/v1/messages/{id}', [MessagesController::class, 'delete']);

//Группы
$router->get('/v1/groups/public/search', [GroupsController::class, 'searchPublic']);
$router->post('/v1/groups/public/join', [GroupsController::class, 'joinPublic']);

// Инвайты в группы
$router->post('/v1/chats/{id}/invites', [GroupInvitesController::class, 'create']);
$router->get('/v1/chats/{id}/invites', [GroupInvitesController::class, 'list']);
$router->delete('/v1/chats/{id}/invites/{invite_id}', [GroupInvitesController::class, 'revoke']);
$router->post('/v1/chats/join_by_invite', [GroupInvitesController::class, 'joinByInvite']);

// Статусы присутствия
$router->get('/v1/users/{id}/presence', [PresenceController::class, 'getPresence']);
$router->get('/v1/users/{id}/presence/history', [PresenceController::class, 'getHistory']);

// Блокировка контактов
$router->post('/v1/contacts/block', [ContactsBlockController::class, 'block']);
$router->post('/v1/contacts/unblock', [ContactsBlockController::class, 'unblock']);
$router->get('/v1/contacts/blocked', [ContactsBlockController::class, 'list']);

// Жалобы пользователей
@@ -150,46 +150,47 @@ $router->get('/v1/chats/{id}', [ChatsController::class, 'get']);
$router->patch('/v1/chats/{id}', [ChatsController::class, 'patch']);

$router->get('/v1/chats/{id}/members', [ChatMembersController::class, 'list']);
$router->post('/v1/chats/{id}/members', [ChatMembersController::class, 'add']);
$router->delete('/v1/chats/{id}/members', [ChatMembersController::class, 'remove']);
$router->put('/v1/chats/{id}/roles/{user_id}', [ChatMembersController::class, 'setRole']);
$router->post('/v1/chats/{id}/transfer_ownership', [ChatMembersController::class, 'transferOwnership']);

$router->post('/v1/media/upload_init', [MediaController::class, 'uploadInit']);
$router->post('/v1/media/complete', [MediaController::class, 'complete']);
$router->get('/v1/media/file/{id}', [MediaController::class, 'file']);

$router->post('/v1/calls', [CallsController::class, 'create']);
$router->get('/v1/calls/{id}', [CallsController::class, 'get']);
$router->post('/v1/calls/{id}/end', [CallsController::class, 'end']);
$router->get('/v1/calls', [CallsController::class, 'listByChat']);

$router->get('/v1/updates', [UpdatesController::class, 'poll']);

$router->get('/v1/admin/users', [AdminController::class, 'users']);
$router->get('/v1/admin/chats', [AdminController::class, 'chats']);
$router->get('/v1/admin/messages', [AdminController::class, 'messages']);
$router->get('/v1/admin/stats', [AdminStatsController::class, 'stats']);

try {
$runner = new MiddlewareRunner([
    [ErrorHandler::class, 'handle'],
    [Cors::class, 'handle'],
    [RateLimit::class, 'handle'],
    [MicroserviceProxy::class, 'handle'],
    [AuthMiddleware::class, 'handle'],
]);
    
    $result = $runner->run($request, $response, function(Request $req, Response $res) use ($router) {
        $out = $router->dispatch($req, $res);
        return $out instanceof Response ? $out : (new Response())->json(is_array($out) ? $out : ['result' => $out]);
    });
    
    $result->send();
} catch (\Throwable $e) {
    \Messa\Core\Logger::error('Fatal application error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    JsonResponder::error($response, 500, 'internal', 'Internal server error')->send();
}