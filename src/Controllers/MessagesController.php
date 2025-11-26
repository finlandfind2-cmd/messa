<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\ForbiddenException;
use Messa\Repos\ChatsRepository;
use Messa\Repos\BlockedContactsRepository;
use Messa\Repos\AttachmentsRepository;
use Messa\Repos\MessagesRepository;
use Messa\Domain\Messages\MessageType;

final class MessagesController extends BaseController
{
    public function list(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $chatId = (int)$req->param('id', 0);
        $limit = max(1, min(200, (int)($req->query('limit') ?? 50)));
        $before = $this->asIntOrNull($req->query('before_id'));

        $repo = new MessagesRepository();
        if (!$repo->isMember($chatId, $userId)) {
            throw new NotFoundException('chat_not_found');
        }
        
        return $repo->listByChat($chatId, $limit, $before);
    }

    public function create(Request $req, Response $res): array
    {
        $this->enforceIdempotency(
            $req,
            'msg:' . (string)($req->json()['chat_id'] ?? '') . ':' . (string)($req->json()['client_msg_id'] ?? '')
        );
        $userId = $this->requireAuth($req);
        // принимаем только ожидаемые ключи
        $b = $this->arrayWhitelist($req->json(), ['chat_id','type','text','client_msg_id','reply_to_id','attachment_ids']);
        
        $chatId  = (int)($b['chat_id'] ?? 0);
        $type    = (string)($b['type'] ?? '');
        $text    = (string)($b['text'] ?? '');
        $client  = (string)($b['client_msg_id'] ?? '');
        $replyTo = $this->asIntOrNull($b['reply_to_id'] ?? null);
        $attachmentIds = isset($b['attachment_ids']) ? array_map('intval', (array)$b['attachment_ids']) : [];

        if ($client === '' || mb_strlen($client, 'UTF-8') > 64 || !preg_match('/^[A-Za-z0-9._:\\-]+$/u', $client)) {
            throw new UnprocessableException('invalid_client_msg_id');
        }

        // РАСШИРЯЕМ валидацию: разрешаем сообщения с вложениями без текста
        $hasText = $text !== '' && $this->isTextValid($text);
        $hasAttachments = !empty($attachmentIds);
        
        if ($chatId <= 0 || $type !== MessageType::TEXT || (!$hasText && !$hasAttachments)) {
            throw new UnprocessableException('invalid_payload');
        }

        // ВАЛИДАЦИЯ ВЛОЖЕНИЙ
        if ($attachmentIds) {
            $attachmentsRepo = new AttachmentsRepository();
            foreach ($attachmentIds as $attachmentId) {
                $att = $attachmentsRepo->getByIdOwned($attachmentId, $userId);
                if (!$att || $att['status'] !== 'uploaded') {
                    throw new UnprocessableException('Invalid or not ready attachment');
                }
            }
        }

        $repo = new MessagesRepository();
        if (!$repo->isMember($chatId, $userId)) {
            throw new NotFoundException('chat_not_found');
        }

        // >>> БЛОКИРОВКА ДЛЯ DIRECT-ЧАТА
        $chatsRepo = new ChatsRepository();
        $chat = $chatsRepo->getById($chatId);
        if (!$chat) {
            throw new NotFoundException('chat_not_found');
        }

        if ($chat['type'] === 'direct') {
            $peer = $chatsRepo->getDirectChatPeer($chatId, $userId);
            if ($peer) {
                $peerId = (int)$peer['id'];
                $blockedRepo = new BlockedContactsRepository();

                $blocked =
                    $blockedRepo->isBlocked($userId, $peerId)      // я заблокировал собеседника
                    || $blockedRepo->isBlocked($peerId, $userId);  // собеседник заблокировал меня

                if ($blocked) {
                    throw new ForbiddenException('blocked_contact');
                }
            }
        }
        // <<< КОНЕЦ БЛОКИРОВКИ

        $msg = $repo->insertMessageFanout(
            $chatId,
            $userId,
            $client,
            MessageType::TEXT,
            $hasText ? $text : null,
            $attachmentIds,
            $replyTo
        );
        return ['message' => $msg];
    }

    public function patch(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $msgId = (int)$req->param('id', 0);
        $text = (string)($req->json()['text'] ?? '');

        if (!$this->isTextValid($text)) {
            throw new UnprocessableException('invalid_text');
        }

        $repo = new MessagesRepository();
        $m = $repo->getById($msgId);
        
        if (!$m || !$repo->isMember((int)$m['chat_id'], $userId) || (int)$m['sender_id'] !== $userId || $m['deleted_at'] !== null) {
            throw new NotFoundException('message_not_found');
        }
        
        $updated = $repo->updateText($msgId, $text);
        return ['message' => $updated];
    }

    public function delete(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $msgId = (int)$req->param('id', 0);

        $repo = new MessagesRepository();
        $m = $repo->getById($msgId);
        
        if (!$m || !$repo->isMember((int)$m['chat_id'], $userId) || (int)$m['sender_id'] !== $userId || $m['deleted_at'] !== null) {
            throw new NotFoundException('message_not_found');
        }
        
        $ok = $repo->softDelete($msgId);
        return ['status' => $ok ? 'ok' : 'noop'];
    }

    public function forward(Request $req, Response $res): array
    {
        $body = $req->json() ?? [];
        $chatIdRaw  = $body['chat_id'] ?? null;
        $clientRaw  = $body['client_msg_id'] ?? null;

        $this->enforceIdempotency(
            $req,
            'msg_forward:' . (string)$chatIdRaw . ':' . (string)$clientRaw
        );

        $userId = $this->requireAuth($req);
        $b = $this->arrayWhitelist($body, ['chat_id', 'client_msg_id', 'forward_from_id']);

        $chatId        = (int)($b['chat_id'] ?? 0);
        $client        = (string)($b['client_msg_id'] ?? '');
        $forwardFromId = $this->asIntOrNull($b['forward_from_id'] ?? null);

        if ($client === '' || mb_strlen($client, 'UTF-8') > 64 || !preg_match('/^[A-Za-z0-9._:\-]+$/u', $client)) {
            throw new UnprocessableException('invalid_client_msg_id');
        }

        if ($chatId <= 0 || $forwardFromId === null || $forwardFromId <= 0) {
            throw new UnprocessableException('invalid_payload');
        }

        $repo = new MessagesRepository();

        // доступ к целевому чату
        if (!$repo->isMember($chatId, $userId)) {
            throw new NotFoundException('chat_not_found');
        }

        // >>> БЛОКИРОВКА ДЛЯ DIRECT-ЧАТА ПРИ ПЕРЕСЫЛКЕ
        $chatsRepo = new ChatsRepository();
        $chat = $chatsRepo->getById($chatId);
        if (!$chat) {
            throw new NotFoundException('chat_not_found');
        }

        if ($chat['type'] === 'direct') {
            $peer = $chatsRepo->getDirectChatPeer($chatId, $userId);
            if ($peer) {
                $peerId = (int)$peer['id'];
                $blockedRepo = new BlockedContactsRepository();

                $blocked =
                    $blockedRepo->isBlocked($userId, $peerId)
                    || $blockedRepo->isBlocked($peerId, $userId);

                if ($blocked) {
                    throw new ForbiddenException('blocked_contact');
                }
            }
        }
        // <<< КОНЕЦ БЛОКИРОВКИ

        // доступ к исходному сообщению
        $srcChatId = $repo->getChatIdByMessageId($forwardFromId);
        if ($srcChatId === null || !$repo->isMember($srcChatId, $userId)) {
            throw new NotFoundException('source_message_not_found');
        }

        $msg = $repo->forwardTextFanout($chatId, $userId, $client, $forwardFromId);
        return ['message' => $msg];
    }

    public function createWithAttachments(Request $req, Response $res): array
    {
        $this->enforceIdempotency(
            $req,
            'msg_att:' . (string)($req->json()['chat_id'] ?? '') . ':' . (string)($req->json()['client_msg_id'] ?? '')
        );
        
        $userId = $this->requireAuth($req);
        $b = $this->arrayWhitelist($req->json(), [
            'chat_id', 'client_msg_id', 'text', 'reply_to_id', 'attachment_ids'
        ]);

        $chatId = (int)($b['chat_id'] ?? 0);
        $client = (string)($b['client_msg_id'] ?? '');
        $text = isset($b['text']) ? (string)$b['text'] : null;
        $replyTo = $this->asIntOrNull($b['reply_to_id'] ?? null);
        $attachmentIds = isset($b['attachment_ids']) ? array_map('intval', (array)$b['attachment_ids']) : [];

        if ($client === '' || mb_strlen($client, 'UTF-8') > 64 || !preg_match('/^[A-Za-z0-9._:\\-]+$/u', $client)) {
            throw new UnprocessableException('invalid_client_msg_id');
        }

        if ($chatId <= 0 || empty($attachmentIds)) {
            throw new UnprocessableException('chat_id_and_attachments_required');
        }

        // Валидация текста (если есть)
        $hasText = $text !== null && $text !== '' && $this->isTextValid($text);
        if ($hasText && !$this->isTextValid($text)) {
            throw new UnprocessableException('invalid_text');
        }

        // Валидация вложений
        $attachmentsRepo = new AttachmentsRepository();
        foreach ($attachmentIds as $attachmentId) {
            $att = $attachmentsRepo->getByIdOwned($attachmentId, $userId);
            if (!$att || $att['status'] !== 'uploaded') {
                throw new UnprocessableException('Invalid or not ready attachment: ' . $attachmentId);
            }
        }

        $repo = new MessagesRepository();
        if (!$repo->isMember($chatId, $userId)) {
            throw new NotFoundException('chat_not_found');
        }

        // Проверка блокировки для direct-чатов
        $chatsRepo = new ChatsRepository();
        $chat = $chatsRepo->getById($chatId);
        if ($chat && $chat['type'] === 'direct') {
            $peer = $chatsRepo->getDirectChatPeer($chatId, $userId);
            if ($peer) {
                $peerId = (int)$peer['id'];
                $blockedRepo = new BlockedContactsRepository();
                if ($blockedRepo->isBlocked($userId, $peerId) || $blockedRepo->isBlocked($peerId, $userId)) {
                    throw new ForbiddenException('blocked_contact');
                }
            }
        }

        $msg = $repo->createMessageWithAttachments(
            $chatId,
            $userId,
            $client,
            $hasText ? $text : null,
            $attachmentIds,
            $replyTo
        );

        return ['message' => $msg];
    }

    private function isTextValid(string $text): bool
    {
        $text = trim($text);
        if ($text === '') return false;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text)) return false;
        
        $len = mb_strlen($text, 'UTF-8');
        return $len >= 1 && $len <= 4000;
    }
}