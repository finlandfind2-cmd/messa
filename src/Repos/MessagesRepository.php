<?php
declare(strict_types=1);
namespace Messa\Repos;

use Messa\Core\Db;
use PDO;
use Messa\Domain\Messages\MessageType;
use Messa\Services\Crypto\Envelope;

final class MessagesRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function getChatIdByMessageId(int $messageId): ?int
    {
        $st = $this->pdo->prepare("SELECT chat_id FROM messages WHERE id=? LIMIT 1");
        $st->execute([$messageId]);
        $cid = $st->fetchColumn();
        return $cid === false ? null : (int)$cid;
    }

    public function isMember(int $chatId, int $userId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=? LIMIT 1");
        $st->execute([$chatId, $userId]);
        return (bool)$st->fetchColumn();
    }

    public function findByClientId(int $chatId, string $clientMsgId): ?array
    {
        $st = $this->pdo->prepare("SELECT id FROM messages WHERE chat_id=? AND client_msg_id=? LIMIT 1");
        $st->execute([$chatId, $clientMsgId]);
        $id = $st->fetchColumn();
        return $id ? $this->getById((int)$id) : null;
    }

    /**
     * Универсальная вставка сообщения с фан-аутом (увеличение unread, обновление last_*).
     *
     * Поддерживает разные типы сообщений и опциональные вложения.
     *
     * $type:
     *  - text         — обычное сообщение, текст обязателен, если нет вложений
     *  - voice        — голосовое сообщение (как отдельный тип сообщения)
     *  - video_note   — «кружок» (видеосообщение)
     *  - image/video/audio/document/sticker — при желании можно использовать как отдельные типы
     *
     * Вложения:
     *  - $attachmentIds — массив ID из таблицы attachments, которые будут привязаны к этому сообщению
     *  - привязка делается через AttachmentsRepository::attachToMessage(...) (ожидается, что ты уже добавил этот метод)
     *
     * Текст:
     *  - может быть пустым, если есть вложения (для text / media типов)
     *  - для voice / video_note текст опционален (caption), но если есть — валидируется
     */
    public function insertMessageFanout(
        int $chatId,
        int $senderId,
        string $clientMsgId,
        string $type,
        ?string $text,
        array $attachmentIds = [],
        ?int $replyToId = null,
        ?int $forwardFromId = null,
        ?int $durationMs = null,
        ?array $extraData = null
    ): array {
        if (!MessageType::isValid($type)) {
            throw new \InvalidArgumentException('invalid_message_type');
        }
        if (!$this->isClientMsgIdValid($clientMsgId)) {
            throw new \InvalidArgumentException('invalid_client_msg_id');
        }

        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));
        $attachmentIds = array_filter($attachmentIds, static fn(int $id): bool => $id > 0);
        $attachmentsCount = \count($attachmentIds);

        $hasText = $text !== null && trim($text) !== '';

        $encrypt = $hasText && $this->shouldEncryptChat($chatId);

        $nonce = $tag = $ct = null;
        $contentText = null;

        if ($hasText) {
            if ($encrypt) {
                [$nonce, $tag, $ct] = (new Envelope())->encryptForChat($chatId, $senderId, $text);
                $contentText = null;
            } else {
                // Публичный чат: храним текст как есть
                $contentText = $text;
                $nonce = $tag = $ct = null;
            }
        }

        // Валидация по типам
        switch ($type) {
            case MessageType::TEXT:
                // Для обычного сообщения либо должен быть текст, либо хотя бы одно вложение
                if (!$hasText && $attachmentsCount === 0) {
                    throw new \InvalidArgumentException('text_or_attachments_required');
                }
                if ($hasText && !$this->isTextValid($text)) {
                    throw new \InvalidArgumentException('invalid_text');
                }
                break;

            case MessageType::VOICE:
            case MessageType::VIDEO_NOTE:
                // Голосовое и видеосообщение — это отдельные типы сообщений,
                // поэтому вложение обязательно (аудио/видео), текст — опциональный caption.
                if ($attachmentsCount === 0) {
                    throw new \InvalidArgumentException('attachment_required');
                }
                if ($hasText && !$this->isTextValid($text)) {
                    throw new \InvalidArgumentException('invalid_text');
                }
                break;

            default:
                // Для прочих типов (image/audio/video/document/sticker и т.п.)
                // допускаем тот же принцип: либо текст, либо вложения.
                if (!$hasText && $attachmentsCount === 0) {
                    throw new \InvalidArgumentException('text_or_attachments_required');
                }
                if ($hasText && !$this->isTextValid($text)) {
                    throw new \InvalidArgumentException('invalid_text');
                }
                break;
        }

        $this->pdo->beginTransaction();
        try {
            // Идемпотентность по (chat_id, client_msg_id)
            $existing = $this->findByClientId($chatId, $clientMsgId);
            if ($existing) {
                $this->pdo->commit();
                return $existing;
            }

            $sql = "INSERT INTO messages (
                        chat_id,
                        sender_id,
                        type,
                        client_msg_id,
                        reply_to_id,
                        forward_from_id,
                        content_text,
                        content_nonce,
                        content_tag,
                        content_enc,
                        attachments_count,
                        duration_ms,
                        extra_data
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $st = $this->pdo->prepare($sql);
            $st->bindValue(1, $chatId, PDO::PARAM_INT);
            $st->bindValue(2, $senderId, PDO::PARAM_INT);
            $st->bindValue(3, $type, PDO::PARAM_STR);
            $st->bindValue(4, $clientMsgId, PDO::PARAM_STR);

            // reply_to_id
            if ($replyToId === null) {
                $st->bindValue(5, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue(5, $replyToId, PDO::PARAM_INT);
            }

            // forward_from_id
            if ($forwardFromId === null) {
                $st->bindValue(6, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue(6, $forwardFromId, PDO::PARAM_INT);
            }

            // content_text и поля шифрования
            if ($contentText !== null) {
                // Публичный чат: текст в content_text, шифрование = NULL
                $st->bindValue(7, $contentText, PDO::PARAM_STR);
                $st->bindValue(8, null, PDO::PARAM_NULL);
                $st->bindValue(9, null, PDO::PARAM_NULL);
                $st->bindValue(10, null, PDO::PARAM_NULL);
            } else {
                // Приватный чат: content_text = NULL, шифрование заполнено
                $st->bindValue(7, null, PDO::PARAM_NULL);
                $st->bindValue(8, $nonce, $nonce !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $st->bindValue(9, $tag,   $tag   !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $st->bindValue(10, $ct,    $ct    !== null ? PDO::PARAM_LOB  : PDO::PARAM_NULL);
            }

            $st->bindValue(11, $attachmentsCount, PDO::PARAM_INT);

            if ($durationMs !== null) {
                $st->bindValue(12, $durationMs, PDO::PARAM_INT);
            } else {
                $st->bindValue(12, null, PDO::PARAM_NULL);
            }

            if ($extraData !== null) {
                $st->bindValue(13, json_encode($extraData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
            } else {
                $st->bindValue(13, null, PDO::PARAM_NULL);
            }

            $st->execute();
            $id = (int)$this->pdo->lastInsertId();

            // Привязка вложений к сообщению (ожидается, что в attachments уже есть status='uploaded')
            if ($attachmentsCount > 0) {
                $attRepo = new AttachmentsRepository();
                foreach ($attachmentIds as $aid) {
                    $attRepo->attachToMessage($aid, $senderId, $id, $chatId);
                }
            }

            // Превью для списка диалогов
            $previewText = $hasText ? (string)$text : '';
            $preview = $this->makePreviewForType($type, $previewText, $attachmentsCount);

            $this->pdo
                ->prepare("UPDATE chats SET last_event_at=NOW(), last_message_id=?, last_sender_id=?, last_preview=? WHERE id=?")
                ->execute([$id, $senderId, $preview, $chatId]);

            $this->pdo->prepare("UPDATE chat_members SET last_event_at=NOW() WHERE chat_id=?")
                ->execute([$chatId]);

            $this->pdo->prepare("UPDATE chat_members SET unread_count=unread_count+1 WHERE chat_id=? AND user_id<>?")
                ->execute([$chatId, $senderId]);

            $this->pdo->commit();
            return $this->getById($id);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Обёртка над insertMessageFanout для текстовых сообщений (обратная совместимость).
     */
    public function insertTextFanout(
        int $chatId,
        int $senderId,
        string $clientMsgId,
        string $text,
        ?int $replyToId = null,
        ?int $forwardFromId = null
    ): array {
        return $this->insertMessageFanout(
            $chatId,
            $senderId,
            $clientMsgId,
            MessageType::TEXT,
            $text,
            [],           // вложений нет
            $replyToId,
            $forwardFromId,
            null,
            null
        );
    }

    public function forwardTextFanout(
        int $chatId,
        int $senderId,
        string $clientMsgId,
        int $forwardFromId
    ): array {
        if (!$this->isClientMsgIdValid($clientMsgId)) {
            throw new \InvalidArgumentException('invalid_client_msg_id');
        }

        $st = $this->pdo->prepare(
            "SELECT id, chat_id, sender_id, type, content_text, content_nonce, content_tag, content_enc
             FROM messages
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1"
        );
        $st->execute([$forwardFromId]);
        $src = $st->fetch(PDO::FETCH_ASSOC);

        if (!$src) {
            throw new \InvalidArgumentException('source_message_not_found');
        }

        if ((string)$src['type'] !== MessageType::TEXT) {
            throw new \InvalidArgumentException('source_type_not_supported');
        }

        $text = (string)($src['content_text'] ?? '');
        if ($text === '') {
            $nonce = $this->normalizeBlob($src['content_nonce'] ?? null);
            $tag   = $this->normalizeBlob($src['content_tag'] ?? null);
            $enc   = $this->normalizeBlob($src['content_enc'] ?? null);

            if (!$nonce || !$tag || !$enc) {
                throw new \RuntimeException('source_message_broken');
            }

            $text = (new Envelope())->decryptForChat(
                (int)$src['chat_id'],
                (int)$src['sender_id'],
                $nonce,
                $tag,
                $enc
            );
        }

        return $this->insertTextFanout($chatId, $senderId, $clientMsgId, $text, null, $forwardFromId);
    }

    public function createMessageWithAttachments(
        int $chatId,
        int $senderId, 
        string $clientMsgId,
        ?string $text,
        array $attachmentIds,
        ?int $replyToId = null
    ): array {
        return $this->insertMessageFanout(
            $chatId,
            $senderId,
            $clientMsgId,
            MessageType::TEXT,
            $text,
            $attachmentIds,
            $replyToId,
            null, // forward_from_id
            null, // duration_ms  
            null  // extra_data
        );
    }

    public function listByChat(int $chatId, int $limit = 50, ?int $beforeId = null): array
    {
        $limit = max(1, min(200, $limit));
        $sql = "SELECT id, chat_id, sender_id, type, client_msg_id, reply_to_id, forward_from_id, content_text,
                       content_nonce, content_tag, content_enc,
                       attachments_count, duration_ms, extra_data, created_at, edited_at, deleted_at
                FROM messages
                WHERE chat_id = :cid
                  AND deleted_at IS NULL
                  AND (:before IS NULL OR id < :before)
                ORDER BY id DESC
                LIMIT :lim";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':cid', $chatId, PDO::PARAM_INT);
        if ($beforeId === null) {
            $st->bindValue(':before', null, PDO::PARAM_NULL);
        } else {
            $st->bindValue(':before', $beforeId, PDO::PARAM_INT);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $messageIds = [];
        foreach ($rows as $r) {
            $messageIds[] = (int)$r['id'];
        }
        $attachmentsByMessage = $this->loadAttachmentsForMessages($messageIds);

        $items = [];
        foreach ($rows as $r) {
            $msg = $this->mapRow($r);
            $mid = (int)$r['id'];
            $msg['attachments'] = $attachmentsByMessage[$mid] ?? [];
            $items[] = $msg;
        }

        $hasMore = count($rows) === $limit;
        $nextBefore = null;
        if (!empty($rows)) {
            $last = end($rows);
            $nextBefore = (int)$last['id'];
        }
        return [
            'items' => $items,
            'next_before_id' => $hasMore ? $nextBefore : null,
            'has_more' => $hasMore
        ];
    }

    public function getById(int $id): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, chat_id, sender_id, type, client_msg_id, reply_to_id, forward_from_id, content_text,
                    content_nonce, content_tag, content_enc,
                    attachments_count, duration_ms, extra_data, created_at, edited_at, deleted_at
             FROM messages
             WHERE id=? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }
        $msg = $this->mapRow($row);
        $attachmentsByMessage = $this->loadAttachmentsForMessages([(int)$row['id']]);
        $msg['attachments'] = $attachmentsByMessage[(int)$row['id']] ?? [];
        return $msg;
    }

    public function updateText(int $messageId, string $newText): array
    {
        if (!$this->isTextValid($newText)) {
            throw new \InvalidArgumentException('invalid_text');
        }

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT id, chat_id, sender_id, type, attachments_count FROM messages WHERE id=? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$messageId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                $this->pdo->commit();
                return [];
            }

            $cid = (int)$cur['chat_id'];
            $sid = (int)$cur['sender_id'];
            $type = (string)$cur['type'];
            $attachmentsCount = (int)($cur['attachments_count'] ?? 0);

            $encrypt = $this->shouldEncryptChat($cid);

            if ($encrypt) {
                [$n, $t, $ct] = (new Envelope())->encryptForChat($cid, $sid, $newText);
                $upd = $this->pdo->prepare("UPDATE messages SET content_text=NULL, content_nonce=?, content_tag=?, content_enc=?, edited_at=NOW() WHERE id=?");
                $upd->bindValue(1, $n, PDO::PARAM_STR);
                $upd->bindValue(2, $t, PDO::PARAM_STR);
                $upd->bindValue(3, $ct, PDO::PARAM_LOB);
                $upd->bindValue(4, $messageId, PDO::PARAM_INT);
            } else {
                // Публичный чат: хранить просто текст
                $upd = $this->pdo->prepare("UPDATE messages SET content_text=?, content_nonce=NULL, content_tag=NULL, content_enc=NULL, edited_at=NOW() WHERE id=?");
                $upd->bindValue(1, $newText, PDO::PARAM_STR);
                $upd->bindValue(2, $messageId, PDO::PARAM_INT);
            }

            $upd->execute();

            $st2 = $this->pdo->prepare("SELECT last_message_id FROM chats WHERE id=? LIMIT 1");
            $st2->execute([$cid]);
            $lastId = (int)$st2->fetchColumn();
            if ($lastId === (int)$messageId) {
                $preview = $this->makePreviewForType($type, $newText, $attachmentsCount);
                $updChat = $this->pdo->prepare("UPDATE chats SET last_preview=?, last_event_at=NOW() WHERE id=? AND last_message_id=?");
                $updChat->execute([$preview, $cid, $messageId]);
            } else {
                $this->pdo->prepare("UPDATE chats SET last_event_at=NOW() WHERE id=?")
                    ->execute([$cid]);
            }

            $this->pdo->prepare("UPDATE chat_members SET last_event_at=NOW() WHERE chat_id=?")
                ->execute([$cid]);

            $this->pdo->commit();
            return $this->getById($messageId);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function softDelete(int $messageId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT id, chat_id FROM messages WHERE id=? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$messageId]);
            $msg = $st->fetch(PDO::FETCH_ASSOC);
            if (!$msg) {
                $this->pdo->commit();
                return false;
            }

            $queue = [[
                'id' => (int)$msg['id'],
                'chat_id' => (int)$msg['chat_id'],
            ]];
            $visited = [];

            while ($queue) {
                $current = array_shift($queue);
                $curId = $current['id'];
                $curChatId = $current['chat_id'];

                if (isset($visited[$curId])) {
                    continue;
                }
                $visited[$curId] = true;

                $stDel = $this->pdo->prepare("UPDATE messages SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL");
                $stDel->execute([$curId]);
                if ($stDel->rowCount() === 0) {
                    continue;
                }

                $this->updateChatAfterSoftDelete($curChatId, $curId);

                $stFwd = $this->pdo->prepare("SELECT id, chat_id FROM messages WHERE forward_from_id=? AND deleted_at IS NULL");
                $stFwd->execute([$curId]);
                $forwards = $stFwd->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($forwards as $row) {
                    $queue[] = [
                        'id' => (int)$row['id'],
                        'chat_id' => (int)$row['chat_id'],
                    ];
                }
            }

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function updateChatAfterSoftDelete(int $chatId, int $deletedMessageId): void
    {
        $stLast = $this->pdo->prepare("SELECT last_message_id FROM chats WHERE id=? LIMIT 1");
        $stLast->execute([$chatId]);
        $curLast = (int)($stLast->fetchColumn() ?? 0);

        if ($curLast === $deletedMessageId) {
            $stPrev = $this->pdo->prepare(
                "SELECT id, sender_id, type, attachments_count, content_text, content_nonce, content_tag, content_enc
                 FROM messages
                 WHERE chat_id=? AND deleted_at IS NULL AND id<?
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stPrev->execute([$chatId, $deletedMessageId]);
            $prev = $stPrev->fetch(PDO::FETCH_ASSOC);

            if ($prev) {
                $previewText = $this->normalizeBlob($prev['content_text'] ?? null);
                if ($previewText === null || $previewText === '') {
                    $nonce = $this->normalizeBlob($prev['content_nonce'] ?? null);
                    $tag   = $this->normalizeBlob($prev['content_tag'] ?? null);
                    $enc   = $this->normalizeBlob($prev['content_enc'] ?? null);
                    if ($nonce && $tag && $enc) {
                        $previewText = (new Envelope())->decryptForChat(
                            $chatId,
                            (int)$prev['sender_id'],
                            $nonce,
                            $tag,
                            $enc
                        );
                    } else {
                        $previewText = '';
                    }
                }

                $type = (string)$prev['type'];
                $attachmentsCount = (int)($prev['attachments_count'] ?? 0);
                $preview = $this->makePreviewForType($type, $previewText, $attachmentsCount);

                $updChat = $this->pdo->prepare(
                    "UPDATE chats
                     SET last_message_id=?, last_sender_id=?, last_preview=?, last_event_at=NOW()
                     WHERE id=? AND last_message_id=?"
                );
                $updChat->execute([
                    (int)$prev['id'],
                    (int)$prev['sender_id'],
                    $preview,
                    $chatId,
                    $deletedMessageId
                ]);
            } else {
                $updChat = $this->pdo->prepare(
                    "UPDATE chats
                     SET last_message_id=NULL, last_sender_id=NULL, last_preview=NULL, last_event_at=NOW()
                     WHERE id=? AND last_message_id=?"
                );
                $updChat->execute([$chatId, $deletedMessageId]);
            }
        } else {
            $this->pdo->prepare("UPDATE chats SET last_event_at=NOW() WHERE id=?")->execute([$chatId]);
        }

        $this->pdo->prepare("UPDATE chat_members SET last_event_at=NOW() WHERE chat_id=?")
            ->execute([$chatId]);
    }

    /**
     * Подгружает вложения для списка сообщений одним запросом.
     *
     * Возвращает массив вида [message_id => [attachments...]].
     */
    private function loadAttachmentsForMessages(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
        $messageIds = array_filter($messageIds, static fn(int $id): bool => $id > 0);
        if (!$messageIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $sql = "SELECT
                    id,
                    owner_id,
                    chat_id,
                    message_id,
                    s3_key,
                    mime_type,
                    size_bytes,
                    etag,
                    status,
                    preview_s3_key,
                    preview_width,
                    preview_height,
                    preview_ready,
                    processed_s3_key,
                    processed_mime,
                    media_width,
                    media_height,
                    duration_ms,
                    enc_alg,
                    enc_meta,
                    processed_ready
                FROM attachments
                WHERE message_id IN ($placeholders)
                ORDER BY id ASC";

        $st = $this->pdo->prepare($sql);
        foreach ($messageIds as $i => $mid) {
            $st->bindValue($i + 1, $mid, PDO::PARAM_INT);
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byMessage = [];
        foreach ($rows as $r) {
            $mid = (int)$r['message_id'];

            $encMeta = null;
            if (!empty($r['enc_meta']) && is_string($r['enc_meta'])) {
                $decoded = json_decode($r['enc_meta'], true);
                if (is_array($decoded)) {
                    $encMeta = $decoded;
                }
            }

            $att = [
                'id'         => (int)$r['id'],
                'owner_id'   => (int)$r['owner_id'],
                'chat_id'    => $r['chat_id'] !== null ? (int)$r['chat_id'] : null,
                'message_id' => $mid,
                's3_key'     => $r['s3_key'],
                'mime_type'  => $r['mime_type'],
                'size_bytes' => $r['size_bytes'] !== null ? (int)$r['size_bytes'] : null,
                'etag'       => $r['etag'],
                'status'     => $r['status'],
                'preview'    => [
                    'ready'  => (int)($r['preview_ready'] ?? 0) === 1,
                    's3_key' => $r['preview_s3_key'] ?? null,
                    'width'  => $r['preview_width'] !== null ? (int)$r['preview_width'] : null,
                    'height' => $r['preview_height'] !== null ? (int)$r['preview_height'] : null,
                ],
                'processed'  => [
                    'ready'       => (int)($r['processed_ready'] ?? 0) === 1,
                    's3_key'      => $r['processed_s3_key'] ?? null,
                    'mime'        => $r['processed_mime'] ?? null,
                    'width'       => $r['media_width'] !== null ? (int)$r['media_width'] : null,
                    'height'      => $r['media_height'] !== null ? (int)$r['media_height'] : null,
                    'duration_ms' => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
                ],
                'enc'        => [
                    'alg'  => $r['enc_alg'] ?? 'none',
                    'meta' => $encMeta,
                ],
            ];

            $byMessage[$mid][] = $att;
        }

        return $byMessage;
    }
    
    private function mapRow(array $r): array
    {
        if (!$r) {
            return [];
        }

        $plainText = array_key_exists('content_text', $r) && $r['content_text'] !== null
            ? (string)$r['content_text']
            : null;

        $nonce = $this->normalizeBlob($r['content_nonce'] ?? null);
        $tag   = $this->normalizeBlob($r['content_tag'] ?? null);
        $enc   = $this->normalizeBlob($r['content_enc'] ?? null);

        $content = null;
        if ($plainText === null && $nonce !== null && $tag !== null && $enc !== null) {
            // Есть шифртекст – готовим envelope для клиента
            $content = [
                'alg'       => 'AES-256-GCM',
                'v'         => 1,
                'nonce_b64' => base64_encode($nonce),
                'tag_b64'   => base64_encode($tag),
                'ct_b64'    => base64_encode($enc),
                'aad_hint'  => "chat:{$r['chat_id']};sender:{$r['sender_id']}",
            ];
        }

        $msg = [
            'id' => (int)$r['id'],
            'chat_id' => (int)$r['chat_id'],
            'sender_id' => (int)$r['sender_id'],
            'type' => (string)$r['type'],
            'client_msg_id' => (string)$r['client_msg_id'],
            'reply_to_id' => array_key_exists('reply_to_id', $r) && $r['reply_to_id'] !== null
                ? (int)$r['reply_to_id']
                : null,
            'forward_from_id' => array_key_exists('forward_from_id', $r) && $r['forward_from_id'] !== null
                ? (int)$r['forward_from_id']
                : null,
            'content_text' => $plainText,  // Теперь может быть не null для публичных чатов
            'content' => $content,         // Теперь может быть null для публичных чатов
            'attachments_count' => (int)$r['attachments_count'],
            'duration_ms' => array_key_exists('duration_ms', $r) && $r['duration_ms'] !== null
                ? (int)$r['duration_ms']
                : null,
            'extra_data' => isset($r['extra_data'])
                ? (is_string($r['extra_data'])
                    ? (json_decode($r['extra_data'], true) ?: null)
                    : $r['extra_data'])
                : null,
            'created_at' => (string)$r['created_at'],
            'edited_at' => $r['edited_at'],
            'deleted_at' => $r['deleted_at'],
        ];
        return $msg;
    }

    /**
     * Превью с учётом типа сообщения и количества вложений.
     */
    private function makePreviewForType(string $type, string $text, int $attachmentsCount): string
    {
        $t = trim($text);

        if ($type === MessageType::VOICE) {
            return 'Голосовое сообщение';
        }
        if ($type === MessageType::VIDEO_NOTE) {
            return 'Видеосообщение';
        }
        if ($type === MessageType::SYSTEM) {
            return $this->truncateText($t);
        }

        // Если есть текст — используем его
        if ($t !== '') {
            return $this->truncateText($t);
        }

        // Текста нет, но есть вложения
        if ($attachmentsCount > 0) {
            if ($attachmentsCount === 1) {
                return 'Вложение';
            }
            return 'Вложения (' . $attachmentsCount . ')';
        }

        return '';
    }

    private function truncateText(string $text): string
    {
        $t = trim($text);
        if (mb_strlen($t, 'UTF-8') > 120) {
            $t = mb_substr($t, 0, 120, 'UTF-8') . '…';
        }
        return $t;
    }

    private function makePreview(string $text): string
    {
        // Оставляем для обратной совместимости, но теперь это просто truncateText
        return $this->truncateText($text);
    }

    private function normalizeBlob($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\is_resource($value)) {
            $data = stream_get_contents($value);
            if ($data === false) {
                return '';
            }
            return $data;
        }
        return (string)$value;
    }

    /** Репо-инварианты (дублируют контроллер, но гарантируют целостность) */
    private function isClientMsgIdValid(string $id): bool
    {
        if ($id === '' || mb_strlen($id, 'UTF-8') > 64) {
            return false;
        }
        return (bool)preg_match('/^[A-Za-z0-9._:\\-]+$/u', $id);
    }

    private function isTextValid(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        if (preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', $t)) {
            return false;
        }
        $len = mb_strlen($t, 'UTF-8');
        return $len >= 1 && $len <= 4000;
    }

    private function shouldEncryptChat(int $chatId): bool
    {
        static $cache = [];

        if (isset($cache[$chatId])) {
            return $cache[$chatId];
        }

        $st = $this->pdo->prepare(
            "SELECT type, visibility, is_encrypted
            FROM chats
            WHERE id=? LIMIT 1"
        );
        $st->execute([$chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Нет чата — безопаснее не шифровать
            return $cache[$chatId] = false;
        }

        $type       = (string)($row['type'] ?? '');
        $visibility = (string)($row['visibility'] ?? 'private');
        $flag       = (int)($row['is_encrypted'] ?? 0);

        // Если флаг выключен — вообще не шифруем
        if ($flag === 0) {
            return $cache[$chatId] = false;
        }

        // Direct-чаты всегда шифруем (если флаг не выключен)
        if ($type === 'direct') {
            return $cache[$chatId] = true;
        }

        // Группы/каналы: шифруем только private
        if ($visibility === 'private') {
            return $cache[$chatId] = true;
        }

        // Публичные группы/каналы — не шифруем
        return $cache[$chatId] = false;
    }

    public function insertSystemFanout(int $chatId, int $actorId, string $text): array
    {
        $this->pdo->beginTransaction();
        try {
            $clientMsgId = 'sys_' . bin2hex(random_bytes(8));

            $encrypt = $this->shouldEncryptChat($chatId);

            $contentText = null;
            $nonce = $tag = $ct = null;

            if ($encrypt) {
                [$nonce, $tag, $ct] = (new Envelope())->encryptForChat($chatId, $actorId, $text);
            } else {
                $contentText = $text;
            }

            $sql = "INSERT INTO messages (chat_id, sender_id, type, client_msg_id, reply_to_id, content_text, content_nonce, content_tag, content_enc, attachments_count)
                    VALUES (?, ?, 'system', ?, NULL, ?, ?, ?, ?, 0)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, $chatId, PDO::PARAM_INT);
            $stmt->bindValue(2, $actorId, PDO::PARAM_INT);
            $stmt->bindValue(3, $clientMsgId, PDO::PARAM_STR);

            if ($contentText !== null) {
                // Публичный чат
                $stmt->bindValue(4, $contentText, PDO::PARAM_STR);
                $stmt->bindValue(5, null, PDO::PARAM_NULL);
                $stmt->bindValue(6, null, PDO::PARAM_NULL);
                $stmt->bindValue(7, null, PDO::PARAM_NULL);
            } else {
                // Приватный чат
                $stmt->bindValue(4, null, PDO::PARAM_NULL);
                $stmt->bindValue(5, $nonce, PDO::PARAM_STR);
                $stmt->bindValue(6, $tag, PDO::PARAM_STR);
                $stmt->bindValue(7, $ct, PDO::PARAM_LOB);
            }

            $stmt->execute();
            $id = (int)$this->pdo->lastInsertId();

            $preview = $this->makePreviewForType(MessageType::SYSTEM, $text, 0);
            $this->pdo->prepare("UPDATE chats SET last_event_at=NOW(), last_message_id=?, last_sender_id=?, last_preview=? WHERE id=?")
                ->execute([$id, $actorId, $preview, $chatId]);
            $this->pdo->prepare("UPDATE chat_members SET last_event_at=NOW() WHERE chat_id=?")->execute([$chatId]);
            $this->pdo->prepare("UPDATE chat_members SET unread_count=unread_count+1 WHERE chat_id=? AND user_id<>?")
                ->execute([$chatId, $actorId]);
            $this->pdo->commit();
            return $this->getById($id);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}