<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

final class ChatsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    
   public function getById(int $chatId): ?array
    {
        $sql = "SELECT
                    id,
                    type,
                    title,
                    created_by,
                    history_visibility,
                    allow_invites,
                    visibility,
                    is_encrypted,
                    last_message_id,
                    last_sender_id,
                    last_preview,
                    last_event_at
                FROM chats
                WHERE id = ?
                LIMIT 1";

        $st = $this->pdo->prepare($sql);
        $st->execute([$chatId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }

        return [
            'id' => (int)$r['id'],
            'type' => (string)$r['type'],
            'title' => $r['title'],
            'created_by' => (int)$r['created_by'],
            'history_visibility' => (string)$r['history_visibility'],
            'allow_invites' => (int)$r['allow_invites'],
            'visibility' => (string)$r['visibility'],
            'is_encrypted' => (int)$r['is_encrypted'] === 1,
            'last_message_id' => $r['last_message_id'] !== null ? (int)$r['last_message_id'] : null,
            'last_sender_id' => $r['last_sender_id'] !== null ? (int)$r['last_sender_id'] : null,
            'last_preview' => $r['last_preview'],
            'last_event_at' => (string)$r['last_event_at'],
        ];
    }


    public function patchSettings(int $chatId, ?string $title, $historyVisibility, $allowInvites): void
    {
        $sets = [];
        $params = [];
        if ($title !== null) {
            $sets[] = "title=?";
            $params[] = $title;
        }
        if ($historyVisibility !== null) {
            $sets[] = "history_visibility=?";
            $params[] = $historyVisibility;
        }
        if ($allowInvites !== null) {
            $sets[] = "allow_invites=?";
            $params[] = (int)$allowInvites;
        }
        if (!$sets) {
            return;
        }
        $params[] = $chatId;
        $sql = "UPDATE chats SET " . implode(',', $sets) . " WHERE id=?";
        $this->pdo->prepare($sql)->execute($params);
    }

    public function transferOwner(int $chatId, int $newOwnerId): void
    {
        $this->pdo->prepare("UPDATE chats SET created_by=? WHERE id=?")->execute([$newOwnerId, $chatId]);
    }

    public function deleteDirectHard(int $chatId): void
    {
        $st = $this->pdo->prepare("
            DELETE FROM chats 
            WHERE id = ? AND type = 'direct'
            LIMIT 1
        ");
        $st->execute([$chatId]);
    }

    /**
     * Список диалогов пользователя с keyset-пагинацией.
     *
     * Возвращает:
     * - items[]:
     *      - chat {id,type,title,last_event_at,last_preview,last_sender_id,last_message_id,last_message_type}
     *      - member {unread_count, muted, pinned, last_read_message_id}
     *      - peer|null — только для direct
     * - next_cursor
     * - has_more
     */
    public function listDialogs(int $userId, int $limit, ?string $cursor): array
    {
        $pinnedRepo = new PinnedChatsRepository();
        $pinnedChats = $pinnedRepo->getPinnedChats($userId);
        
        [$ts, $cid] = $this->decodeCursor($cursor);

        if ($limit < 1) {
            $limit = 20;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $sql = "
            SELECT
                c.id,
                c.type,
                c.title,
                c.visibility,
                c.is_encrypted,
                c.last_event_at,
                c.last_preview,
                c.last_sender_id,
                c.last_message_id,
                cm.unread_count,
                cm.muted,
                cm.pinned,
                cm.last_read_message_id,
                cm.last_event_at AS member_last_event_at,
                -- peer (для direct)
                u_peer.id              AS peer_id,
                u_peer.login           AS peer_login,
                sp_peer.display_name   AS peer_display_name,
                sp_peer.avatar_key     AS peer_avatar_key,
                u_peer.last_login_at   AS peer_last_login_at,
                lm.type                AS last_message_type,
                lm.attachments_count   AS last_attachments_count
            FROM chat_members cm
            JOIN chats c ON c.id = cm.chat_id
            LEFT JOIN messages lm ON lm.id = c.last_message_id
            LEFT JOIN (
                SELECT
                    cm1.chat_id,
                    cm2.user_id AS peer_id
                FROM chat_members cm1
                JOIN chat_members cm2
                    ON cm2.chat_id = cm1.chat_id
                   AND cm2.user_id <> cm1.user_id
                JOIN chats c2 ON c2.id = cm1.chat_id
                WHERE cm1.user_id = :uid
                  AND c2.type = 'direct'
            ) d ON d.chat_id = c.id
            LEFT JOIN users u_peer ON u_peer.id = d.peer_id
            LEFT JOIN settings_profile sp_peer ON sp_peer.user_id = u_peer.id
            WHERE cm.user_id = :uid
        ";

        if ($ts !== null && $cid !== null) {
            $sql .= "
              AND (
                  cm.last_event_at < :ts
                  OR (cm.last_event_at = :ts AND c.id < :cid)
              )
            ";
        }

        // Сначала непрочитанные, затем по времени
        $sql .= "
            ORDER BY
                (cm.unread_count > 0) DESC,
                cm.last_event_at DESC,
                c.id DESC
            LIMIT :lim
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        if ($ts !== null && $cid !== null) {
            $st->bindValue(':ts', $ts);
            $st->bindValue(':cid', $cid, PDO::PARAM_INT);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(static function (array $r): array {
            $chat = [
                'id'               => (int)$r['id'],
                'type'             => (string)$r['type'],
                'title'            => $r['title'],
                'visibility'       => (string)$r['visibility'],
                'is_encrypted'     => (int)$r['is_encrypted'] === 1,
                'last_event_at'    => (string)$r['last_event_at'],
                'last_preview'     => $r['last_preview'],
                'last_sender_id'   => $r['last_sender_id'] !== null ? (int)$r['last_sender_id'] : null,
                'last_message_id'  => $r['last_message_id'] !== null ? (int)$r['last_message_id'] : null,
                'last_message_type'=> $r['last_message_type'] ?? null,
            ];

            $chat['is_pinned'] = false; // Добавим по умолчанию
            $chat['pinned_position'] = null;

            $member = [
                'unread_count'        => (int)$r['unread_count'],
                'muted'               => (bool)((int)$r['muted'] === 1),
                'pinned'              => (bool)((int)$r['pinned'] === 1),
                'last_read_message_id'=> $r['last_read_message_id'] !== null ? (int)$r['last_read_message_id'] : null,
            ];

            $peer = null;
            if ($r['type'] === 'direct' && $r['peer_id'] !== null) {
                $peer = [
                    'id'           => (int)$r['peer_id'],
                    'login'        => $r['peer_login'],
                    'display_name' => $r['peer_display_name'],
                    'avatar_key'   => $r['peer_avatar_key'],
                    'last_seen_at' => $r['peer_last_login_at'],
                ];
            }

            return [
                'chat'   => $chat,
                'member' => $member,
                'peer'   => $peer,
            ];
        }, $rows);

        // Обработка pinned: создаем карты для быстрого поиска
        $pinnedMap = [];
        foreach ($pinnedChats as $pinned) {
            $pinnedMap[(int)$pinned['chat_id']] = (int)$pinned['position'];
        }

        // Разделяем items на pinned и unpinned
        $pinnedItems = [];
        $unpinnedItems = $items;
        foreach ($items as $key => $item) {
            $chatId = $item['chat']['id'];
            if (isset($pinnedMap[$chatId])) {
                $item['chat']['is_pinned'] = true;
                $item['chat']['pinned_position'] = $pinnedMap[$chatId];
                $item['member']['pinned'] = true; // Синхронизируем с БД, но для полноты
                $pinnedItems[] = $item;
                unset($unpinnedItems[$key]);
            }
        }

        // Сортируем pinned по position (предполагаем position ASC, как в старом коде)
        usort($pinnedItems, static function ($a, $b) {
            return $a['chat']['pinned_position'] <=> $b['chat']['pinned_position'];
        });

        // Объединяем: pinned сверху, затем unpinned (уже отсортированы SQL)
        $items = array_merge($pinnedItems, $unpinnedItems);

        $hasMore = count($rows) === $limit;
        $nextCursor = null;
        if (!empty($rows)) {
            $last = end($rows);
            $nextCursor = $this->encodeCursor(
                (string)$last['member_last_event_at'],
                (int)$last['id']
            );
        }

        return [
            'items'       => $items,
            'next_cursor' => $hasMore ? $nextCursor : null,
            'has_more'    => $hasMore,
        ];
    }

    /**
     * Найти id direct-чата между двумя пользователями (если существует).
     */
    public function findDirectBetween(int $userA, int $userB): ?int
    {
        $sql = "
            SELECT cm1.chat_id AS chat_id
            FROM chat_members cm1
            JOIN chat_members cm2
                ON cm2.chat_id = cm1.chat_id
            JOIN chats c ON c.id = cm1.chat_id
            WHERE c.type = 'direct'
              AND cm1.user_id = :u1
              AND cm2.user_id = :u2
            LIMIT 1
        ";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':u1', $userA, PDO::PARAM_INT);
        $st->bindValue(':u2', $userB, PDO::PARAM_INT);
        $st->execute();

        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Создание группы или канала.
     *
     * @param int    $creatorId          ID создателя (становится owner)
     * @param string $type               'group' | 'channel'
     * @param string $title              Название
     * @param string $visibility         'private' | 'public'
     * @param string $historyVisibility  'all'|'since_join'|'none'
     * @param bool   $allowInvites       Разрешены ли приглашения
     * @param int[]  $memberIds          Дополнительные участники (без создателя)
     * @param string|null $avatarKey     Ключ аватара (если есть)
     * @return int ID созданного чата
     */
    public function createGroupOrChannel(
        int $creatorId,
        string $type,
        string $title,
        string $visibility,
        string $historyVisibility,
        bool $allowInvites,
        array $memberIds,
        ?string $avatarKey = null
    ): int {
        // Нормализация списка участников: >0, не создатель, без дублей
        $cleanMembers = [];
        foreach ($memberIds as $id) {
            $id = (int)$id;
            if ($id > 0 && $id !== $creatorId) {
                $cleanMembers[$id] = $id;
            }
        }
        $memberIds = array_values($cleanMembers);

        // Простое правило шифрования:
        // private → is_encrypted = 1, public → is_encrypted = 0
        $isEncrypted = ($visibility === 'private') ? 1 : 0;

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $stChat = $this->pdo->prepare(
                'INSERT INTO chats
                    (type, title, avatar_key, created_by, history_visibility, allow_invites, last_event_at, visibility, is_encrypted, created_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stChat->execute([
                $type,
                $title,
                $avatarKey,
                $creatorId,
                $historyVisibility,
                $allowInvites ? 1 : 0,
                $now,
                $visibility,
                $isEncrypted,
                $now
            ]);

            $chatId = (int)$this->pdo->lastInsertId();

            $stMember = $this->pdo->prepare(
                'INSERT INTO chat_members
                    (chat_id, user_id, role, muted, pinned, last_read_message_id, unread_count, last_event_at, joined_at)
                 VALUES
                    (?, ?, ?, 0, 0, NULL, 0, ?, ?)'
            );

            // Создатель — всегда owner
            $stMember->execute([
                $chatId,
                $creatorId,
                'owner',
                $now,
                $now,
            ]);

            // Остальные — member
            foreach ($memberIds as $uid) {
                $stMember->execute([
                    $chatId,
                    $uid,
                    'member',
                    $now,
                    $now,
                ]);
            }

            $this->pdo->commit();
            return $chatId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function searchPublicGroups(string $query, int $limit, int $offset): array
    {
        $q = '%' . mb_strtolower($query, 'UTF-8') . '%';

        $sql = "
            SELECT
                c.id,
                c.title,
                c.avatar_key,
                c.visibility,
                c.history_visibility,
                c.allow_invites,
                c.last_event_at,
                COUNT(m.user_id) AS member_count
            FROM chats c
            LEFT JOIN chat_members m ON m.chat_id = c.id
            WHERE c.type = 'group'
            AND c.visibility = 'public'
            AND c.deleted_at IS NULL
            AND (c.title_lower LIKE :q OR c.title LIKE :q)
            GROUP BY c.id
            ORDER BY member_count DESC, c.last_event_at DESC
            LIMIT :lim OFFSET :off
        ";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':q', $q, PDO::PARAM_STR);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id'              => (int)$r['id'],
                'title'           => $r['title'],
                'avatar_key'      => $r['avatar_key'] ?? null,
                'visibility'      => $r['visibility'],
                'history_visibility' => $r['history_visibility'],
                'allow_invites'   => (bool)$r['allow_invites'],
                'last_event_at'   => (string)$r['last_event_at'],
                'member_count'    => (int)$r['member_count'],
            ];
        }, $rows);
    }
    

    /**
     * Найти существующий или создать новый direct-чат.
     * Гарантирует: один чат на пару пользователей.
     */
    public function findOrCreateDirect(int $userA, int $userB): int
    {
        if ($userA === $userB) {
            throw new \InvalidArgumentException('Direct chat with self is not allowed');
        }

        // Нормализуем порядок пользователей чисто для консистентности
        $u1 = min($userA, $userB);
        $u2 = max($userA, $userB);

        // Быстрая проверка без транзакции
        $existing = $this->findDirectBetween($u1, $u2);
        if ($existing !== null) {
            return $existing;
        }

        $this->pdo->beginTransaction();
        try {
            // Повторная проверка уже под транзакцией (на случай гонки)
            $existing = $this->findDirectBetween($u1, $u2);
            if ($existing !== null) {
                $this->pdo->commit();
                return $existing;
            }

            // Создаём новый direct-чат
            $stmt = $this->pdo->prepare("
                INSERT INTO chats (
                    type,
                    created_by,
                    history_visibility,
                    allow_invites,
                    last_event_at,
                    created_at,
                    visibility,
                    is_encrypted
                ) VALUES (
                    'direct',
                    ?,
                    'all',
                    1,
                    NOW(),
                    NOW(),
                    'private',
                    1
                )
            ");
            $stmt->execute([$u1]);
            $chatId = (int)$this->pdo->lastInsertId();

            // Добавляем обоих участников
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_members (chat_id, user_id, role, joined_at, last_event_at)
                VALUES (?, ?, 'member', NOW(), NOW())
            ");
            $stmt->execute([$chatId, $u1]);
            $stmt->execute([$chatId, $u2]);

            $this->pdo->commit();
            return $chatId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }


    public function getDirectChatPeer(int $chatId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.login, sp.display_name, sp.avatar_key, u.last_login_at
            FROM chat_members cm
            INNER JOIN users u ON cm.user_id = u.id
            LEFT JOIN settings_profile sp ON u.id = sp.user_id
            WHERE cm.chat_id = ? AND cm.user_id != ?
            LIMIT 1
        ");
        $stmt->execute([$chatId, $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function encodeCursor(string $ts, int $chatId): string
    {
        $raw = json_encode(['ts' => $ts, 'id' => $chatId], JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{0:?string,1:?int} */
    private function decodeCursor(?string $cursor): array
    {
        if (!$cursor) {
            return [null, null];
        }
        $pad = strlen($cursor) % 4 ? str_repeat('=', 4 - (strlen($cursor) % 4)) : '';
        $json = base64_decode(strtr($cursor . $pad, '-_', '+/'), true);
        if ($json === false) {
            return [null, null];
        }
        $a = json_decode($json, true);
        if (!is_array($a) || !isset($a['ts']) || !isset($a['id'])) {
            return [null, null];
        }
        return [(string)$a['ts'], (int)$a['id']];
    }
}
