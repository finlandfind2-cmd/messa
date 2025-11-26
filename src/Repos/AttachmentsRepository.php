<?php
declare(strict_types=1);
namespace Messa\Repos;
use Messa\Core\Db;
use PDO;

final class AttachmentsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function createDraft(int $ownerId, ?int $chatId, string $s3Key, string $mime, string $encAlg='none', ?array $encMeta=null): int
    {
        $st = $this->pdo->prepare("INSERT INTO attachments (owner_id, chat_id, s3_key, mime_type, status, enc_alg, enc_meta) VALUES (?,?,?,?, 'uploading', ?, ?)");
        $st->bindValue(1, $ownerId, PDO::PARAM_INT);
        $st->bindValue(2, $chatId, $chatId===null?PDO::PARAM_NULL:PDO::PARAM_INT);
        $st->bindValue(3, $s3Key, PDO::PARAM_STR);
        $st->bindValue(4, $mime, PDO::PARAM_STR);
        $st->bindValue(5, $encAlg, PDO::PARAM_STR);
        $st->bindValue(6, $encMeta ? json_encode($encMeta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null, $encMeta?PDO::PARAM_STR:PDO::PARAM_NULL);
        $st->execute();
        return (int)$this->pdo->lastInsertId();
    }

    public function getAccessibleForUser(int $id, int $userId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT a.*
            FROM attachments a
            LEFT JOIN chat_members cm
            ON cm.chat_id = a.chat_id AND cm.user_id = ?
            WHERE a.id = ?
            AND (a.owner_id = ? OR cm.user_id IS NOT NULL)
            LIMIT 1"
        );
        $st->execute([$userId, $id, $userId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function attachToMessage(int $attachmentId, int $ownerId, int $messageId, int $chatId): void
    {
        $st = $this->pdo->prepare(
            "UPDATE attachments
            SET message_id = ?, chat_id = ?
            WHERE id = ? AND owner_id = ? AND status = 'uploaded'"
        );
        $st->execute([$messageId, $chatId, $attachmentId, $ownerId]);
    }

    public function listByMessage(int $messageId): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, owner_id, chat_id, message_id, s3_key, mime_type,
                    size_bytes, etag, status,
                    preview_ready, preview_s3_key, preview_width, preview_height,
                    processed_ready, processed_s3_key, processed_mime,
                    media_width, media_height, duration_ms,
                    enc_alg, enc_meta, video_profiles_json
            FROM attachments
            WHERE message_id = ?
            ORDER BY id ASC"
        );
        $st->execute([$messageId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markUploaded(int $id, int $ownerId, int $size, string $etag): void
    {
        $st = $this->pdo->prepare("UPDATE attachments SET size_bytes=?, etag=?, status='uploaded', uploaded_at=NOW() WHERE id=? AND owner_id=?");
        $st->execute([$size, $etag, $id, $ownerId]);
    }

    public function getByIdOwned(int $id, int $ownerId): ?array
    {
        $st = $this->pdo->prepare("SELECT id, owner_id, chat_id, s3_key, mime_type, size_bytes, etag, status, enc_alg, enc_meta, preview_s3_key, preview_width, preview_height, preview_ready, created_at, uploaded_at, video_profiles_json FROM attachments WHERE id=? AND owner_id=? LIMIT 1");
        $st->execute([$id, $ownerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function getById(int $id): ?array
    {
        $st = $this->pdo->prepare("
            SELECT id, owner_id, chat_id, message_id, s3_key, mime_type, size_bytes, etag, status,
                preview_ready, preview_s3_key, preview_width, preview_height,
                processed_ready, processed_s3_key, processed_mime,
                media_width, media_height, duration_ms, enc_alg, enc_meta
            FROM attachments 
            WHERE id = ? LIMIT 1
        ");
        $st->execute([$id]);
        $result = $st->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function setPreview(int $id, string $previewKey, int $w, int $h): void
    {
        $st = $this->pdo->prepare("UPDATE attachments SET preview_s3_key=?, preview_width=?, preview_height=?, preview_ready=1 WHERE id=?");
        $st->execute([$previewKey, $w, $h, $id]);
    }

    public function setProcessedAudio(int $id, string $key, string $mime, int $durationMs): void
    {   
        $st = $this->pdo->prepare("UPDATE attachments SET processed_ready=1, processed_s3_key=?, processed_mime=?, duration_ms=? WHERE id=?");
        $durationsMS = $durationMs > 0 ? $durationMs : null;
        $st->execute([$key, $mime, $durationsMS, $id]);
    }

    public function setProcessedVideo(int $id, string $key, string $mime, int $w, int $h, int $durationMs): void
    {
        $st = $this->pdo->prepare("UPDATE attachments SET processed_ready=1, processed_s3_key=?, processed_mime=?, media_width=?, media_height=?, duration_ms=? WHERE id=?");
        $st->execute([$key, $mime, $w > 0 ? $w : null, $h > 0 ? $h : null, $durationMs > 0 ? $durationMs : null, $id]);
    }

    public function setVideoProfiles(int $id, array $profiles): void
    {
        // Пустой массив трактуем как NULL (нет профилей)
        if ($profiles === []) {
            $json = null;
        } else {
            $json = json_encode(
                $profiles,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        $st = $this->pdo->prepare(
            "UPDATE attachments
            SET video_profiles_json = ?
            WHERE id = ?"
        );
        $st->execute([$json, $id]);
    }

    public function getVideoProfiles(int $id): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT video_profiles_json
            FROM attachments
            WHERE id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $raw = $row['video_profiles_json'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
