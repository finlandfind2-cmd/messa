<?php
declare(strict_types=1);

namespace Messa\Queue\Jobs;

use Messa\Services\Media\ImagePreviewer;
use Messa\Services\Media\FFmpegTranscoder;
use Messa\Repos\AttachmentsRepository;
use Messa\Core\Logger;

final class PostprocessMediaJob
{
    private int $attachmentId;
    private int $ownerId;
    private string $s3Key;
    private string $mimeType;
    private string $jobId;

    public function __construct(
        int $attachmentId,
        int $ownerId,
        string $s3Key,
        string $mimeType,
        string $jobId
    ) {
        $this->attachmentId = $attachmentId;
        $this->ownerId      = $ownerId;
        $this->s3Key        = $s3Key;
        $this->mimeType     = $mimeType;
        $this->jobId        = $jobId;
    }

    /** Гидратация из payload (после json_decode(true)) */
    public static function fromArray(array $p): self
    {
        foreach (['attachment_id', 'owner_id', 's3_key', 'mime_type', 'job_id'] as $k) {
            if (!array_key_exists($k, $p)) {
                throw new \InvalidArgumentException("Job payload missing key: {$k}");
            }
        }
        return new self(
            (int)$p['attachment_id'],
            (int)$p['owner_id'],
            (string)$p['s3_key'],
            (string)$p['mime_type'],
            (string)$p['job_id']
        );
    }

    /** Основной запуск из cron-воркера (идемпотентен) */
    public function handle(): void
    {
        $r = \Messa\Core\Redis::client();
        $ttl = (int)($_ENV['WORKER_LOCK_TTL_SEC'] ?? 600); // 10 минут по умолчанию
        $lockKey = "lock:media:{$this->attachmentId}";

        // Простой совместимый lock (SETNX + EXPIRE) — Predis точно умеет
        if ($r->setnx($lockKey, '1') === 0) {
            return; // уже обрабатывается другим запуском
        }
        $r->expire($lockKey, max(30, $ttl));

        try {
            $repo = new AttachmentsRepository();
            $att = $repo->getByIdOwned($this->attachmentId, $this->ownerId);
            if (!$att) {
                Logger::warning('Attachment not found for postprocess', [
                    'attachment_id' => $this->attachmentId,
                    'owner_id' => $this->ownerId,
                ]);
                return;
            }

            // Быстрый выход, если уже всё обработано
            if (strncmp($this->mimeType, 'image/', 6) === 0) {
                // Для изображений достаточно готового превью
                if ((int)($att['preview_ready'] ?? 0) === 1) {
                    return;
                }
            } elseif (strncmp($this->mimeType, 'audio/', 6) === 0 || strncmp($this->mimeType, 'video/', 6) === 0) {
                // Для аудио/видео ориентируемся на processed_ready
                if ((int)($att['processed_ready'] ?? 0) === 1) {
                    return;
                }
            }


             // Если вложение шифрованное — сервер не может его пост-обработать
            if (($att['enc_alg'] ?? 'none') !== 'none') {
                Logger::info('Skip postprocess for encrypted attachment', [
                    'attachment_id' => $this->attachmentId,
                    'enc_alg' => $att['enc_alg'] ?? null,
                ]);
                return;
            }

            // Изображения: делаем превью
            if (strncmp($this->mimeType, 'image/', 6) === 0) {
                $previewer = new ImagePreviewer(); // :contentReference[oaicite:5]{index=5}
                [$previewKey, $w, $h] = $previewer->makeAndUpload($this->s3Key, $this->mimeType); // :contentReference[oaicite:6]{index=6}
                $repo->setPreview($this->attachmentId, $previewKey, (int)$w, (int)$h); // :contentReference[oaicite:7]{index=7}
                return;
            }

            // Аудио/видео: FFMPEG-транскод + (для видео) постер
            $t = new FFmpegTranscoder();
            $base = preg_replace('~\\.[a-z0-9]+$~i', '', $this->s3Key) ?? $this->s3Key;
            $outBase = 'media/proc/' . ltrim($base, '/');

            if (strncmp($this->mimeType, 'audio/', 6) === 0) {
                $res = $t->transcodeAudio($this->s3Key, $outBase);
                $repo->setProcessedAudio(
                    $this->attachmentId, $res['s3_key'], $res['mime'],
                    (int)($res['duration_ms'] ?? 0)
                );
                return;
            }

            if (strncmp($this->mimeType, 'video/', 6) === 0) {
                $pedge = (int)($_ENV['MEDIA_POSTER_EDGE'] ?? 512);
            
                // Профили качества можно задавать через env, напр.:
                // MEDIA_VIDEO_PROFILES="360p:360,480p:480,720p:720"
                $profilesRaw = (string)($_ENV['MEDIA_VIDEO_PROFILES'] ?? '360p:360,480p:480,720p:720');
                $profiles = [];
                foreach (explode(',', $profilesRaw) as $part) {
                    $part = trim($part);
                    if ($part === '' || strpos($part, ':') === false) {
                        continue;
                    }
                    [$label, $h] = array_map('trim', explode(':', $part, 2));
                    $hInt = (int)$h;
                    if ($label !== '' && $hInt > 0) {
                        $profiles[$label] = $hInt;
                    }
                }
            
                $t = new FFmpegTranscoder();
                $res = $t->transcodeVideoMultiProfiles($this->s3Key, $outBase, $profiles, $pedge);
            
                if (!empty($res['poster_s3_key'])) {
                    $repo->setPreview(
                        $this->attachmentId,
                        (string)$res['poster_s3_key'],
                        (int)$pedge,
                        (int)$pedge
                    );
                }
            
                // По-прежнему у нас в attachments ровно ОДИН "основной" processed-вариант
                // (например, 480p по умолчанию)
                $repo->setProcessedVideo(
                    $this->attachmentId,
                    (string)$res['default_s3_key'],
                    (string)$res['mime'],
                    (int)($res['width'] ?? 0),
                    (int)($res['height'] ?? 0),
                    (int)($res['duration_ms'] ?? 0)
                );
            
                // Все профили качеств — в video_profiles_json
                if (!empty($res['profiles']) && is_array($res['profiles'])) {
                    $repo->setVideoProfiles($this->attachmentId, $res['profiles']);
                }
            
                return;
            }

            Logger::info('Skip postprocess: unsupported mime', [
                'attachment_id' => $this->attachmentId,
                'mime' => $this->mimeType,
            ]);
        } finally {
            // Снимаем лок
            $r->del([$lockKey]);
        }
    }

    /**
     * Формирование payload для постановки в очередь из Attachment-модели.
     * Этот массив нужно json_encode и класть в Redis через RedisQueue::enqueueUnique(...).
     */
    public static function fromAttachment(array $att): array
    {
        $aid  = (int)$att['id'];
        $oid  = (int)$att['owner_id'];
        $key  = (string)$att['s3_key'];
        $mime = (string)$att['mime_type'];
        $etag = (string)($att['etag'] ?? '');

        $base  = sprintf('media:pp:%d:%s:%s', $aid, $mime, $etag);
        $jobId = 'media:pp:' . sha1($base, false);

        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);

        return [
            'type'          => 'media_post',
            'attachment_id' => $aid,
            'owner_id'      => $oid,
            's3_key'        => $key,
            'mime_type'     => $mime,
            'created_at'    => $nowUtc,
            'job_id'        => $jobId,
            'attempts'      => 0,
        ];
    }
}
