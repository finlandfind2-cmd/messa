<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Http\Exceptions\NotFoundException;
use Messa\Services\S3\S3Service;
use Messa\Core\ConfigHelper;
use Messa\Core\Logger;
use Messa\Repos\AttachmentsRepository;
use Messa\Queue\Jobs\PostprocessMediaJob;
use Messa\Repos\ChatMembersRepository;
use Messa\Queue\RedisQueue;

final class MediaController extends BaseController
{
    public function uploadInit(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $b = $req->json();
        
        $mime = trim((string)($b['mime_type'] ?? ''));
        $size = isset($b['size_bytes']) ? (int)$b['size_bytes'] : null;
        $chatId = isset($b['chat_id']) ? (int)$b['chat_id'] : null;
        $encAlg = (string)($b['enc_alg'] ?? 'none');
        $encMeta = isset($b['enc_meta']) && is_array($b['enc_meta']) ? $b['enc_meta'] : null;

        $this->validateMimeAndSize($mime, $size);

        // если указали чат — проверяем, что пользователь в нём состоит
        if ($chatId !== null && $chatId > 0) {
            $memRepo = new ChatMembersRepository();
            if ($memRepo->role($chatId, $userId) === null) {
                throw new NotFoundException('chat_not_found');
            }
        }

        // мягкая валидация параметров шифрования (без изменения контракта)
        [$encAlg, $encMeta] = $this->validateEncryptionParams($encAlg, $encMeta);

        $key = $this->generateKey($mime);
        $s3 = new S3Service();
        
        $expires = ConfigHelper::getInt('MEDIA_PRESIGN_EXPIRES', 
            ConfigHelper::getInt('S3_PRESIGN_EXPIRE_SEC', 900)
        );
        
        $presign = $s3->presignPut($key, $mime, $expires);

        $repo = new AttachmentsRepository();
        $attachId = $repo->createDraft($userId, $chatId, $key, $mime, $encAlg, $encMeta);

        return [
            'attachment_id' => $attachId,
            's3_key' => $key,
            'upload' => [
                'method' => 'PUT',
                'url' => $presign['url'],
                'headers' => $presign['headers'],
                'expires_in' => $presign['expires_in']
            ],
            'limits' => [
                'max_size_bytes' => $this->getMaxSizeBytes()
            ]
        ];
    }

    public function complete(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);
        $id = (int)($req->json()['attachment_id'] ?? 0);
        
        if ($id <= 0) {
            throw new UnprocessableException('invalid_attachment_id');
        }

        $repo = new AttachmentsRepository();
        $att = $repo->getByIdOwned($id, $userId);
        
        if (!$att) {
            throw new NotFoundException('attachment_not_found');
        }
        
        if (($att['status'] ?? '') === 'uploaded') {
            $url = (new S3Service())->presignGet((string)$att['s3_key'], 600);
            return [
                'status'    =>  'ok',
                'attachment'    =>  [
                    'id'            =>  (int)$att['id'],
                    's3_key'        =>  $att['s3_key'],
                    'mime_type'     =>  $att['mime_type'],
                    'size_bytes'    =>  (int)($att['size_bytes'] ?? 0),
                    'etag'          =>  $att['etag'],
                    'get_url'       =>  $url,
                    'status'        =>  $att['status'],
                    'preview'   =>[
                        'ready'     =>  (int)($att['preview_ready'] ?? 0)===1,
                        's3_key'    =>  $att['preview_s3_key']  ?? null,
                        'width'     =>  $att['preview_width']   ?? null,
                        'height'    =>  $att['preview_height']  ?? null,
                    ],
                    'processed' => [
                        'ready'       => (int)($att['processed_ready'] ?? 0) === 1,
                        's3_key'      => $att['processed_s3_key'] ?? null,
                        'mime'        => $att['processed_mime'] ?? null,
                        'width'       => $att['media_width'] ?? null,
                        'height'      => $att['media_height'] ?? null,
                        'duration_ms' => $att['duration_ms'] ?? null,
                    ],
                    'video_profiles' => $this->decodeVideoProfiles($att['video_profiles_json'] ?? null),
                ],
            ];
        }

        $s3 = new S3Service();

        try {
            $head = $s3->head((string)$att['s3_key']);
        } catch (\Throwable $e) {
            throw new UnprocessableException('storage_unavailable');
        }

        try {
            $headMime = isset($head['content_type']) 
                ? (string)$head['content_type'] 
                : (string)$att['mime_type'];

            $this->validateMimeAndSize($headMime, (int)$head['content_length']);
        } catch (UnprocessableException $e) {
            // Файл не прошёл валидацию по типу / размеру — удаляем его из S3,
            // чтобы не копился мусор.
            try {
                $s3->delete((string)$att['s3_key']);
            } catch (\Throwable $e2) {
                // глушим — для нас главное: не дать пройти дальше
                Logger::error('Failed to delete invalid media from S3: '.$e2->getMessage());
            }

            // При желании можно ещё пометить attachment в БД как "rejected"
            // (новый статус), но это уже следующий шаг.
            throw $e;
        }

        $repo->markUploaded(
            $id, 
            $userId, 
            (int)$head['content_length'], 
            (string)$head['etag']
        );

        // Постановка задачи пост-обработки
        $job = PostprocessMediaJob::fromAttachment([
            'id' => $id,
            'owner_id' => $userId,
            's3_key' => (string)$att['s3_key'],
            'mime_type' => (string)$att['mime_type'],
            'etag' => (string)$head['etag'],
        ]);
        
        (new RedisQueue())->enqueueUnique(
            'q:media:post', $job['job_id'], 
            json_encode($job, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), 
            86400
        );

        $getUrl = $s3->presignGet((string)$att['s3_key'], 600);

        return [
            'status'=>'ok',
            'attachment'=>[
                'id'        =>  $id,
                's3_key'    =>  $att['s3_key'],
                'mime_type' =>  $att['mime_type'],
                'size_bytes'=>  (int)$head['content_length'],
                'etag'      =>  $head['etag'],
                'get_url'   =>  $getUrl,
                'status'    =>  'uploaded',
                'preview'   =>[
                    'ready'     =>  false,
                    's3_key'    =>  null,
                    'width'     =>  null,
                    'height'    =>  null
                ],
                'processed' =>[
                    'ready'         =>  false,
                    's3_key'        =>  null,
                    'mime'          =>  null,
                    'width'         =>  null,
                    'height'        =>  null,
                    'duration_ms'   =>  null
                ],
                'video_profiles' => null,
            ],
        ];
    }

    private function validateMimeAndSize(string $mime, ?int $size): void
    {
        // Нормализация, чтобы избежать ложных отказов из-за пробелов
        $mime = trim($mime);

        // Берём whitelist из окружения; если он пуст — используем безопасный дефолт (соответствует extByMime)
        $whitelist = ConfigHelper::getArray('MEDIA_MIME_WHITELIST', []);
        if ($whitelist === []) {
            $whitelist = [
                'image/jpeg','image/png','image/webp',
                'video/mp4','video/webm',
                'audio/ogg','audio/m4a',
                'application/pdf','text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        // Явный запрет неизвестных типов
        if (!in_array($mime, $whitelist, true)) {
            throw new UnprocessableException('unsupported_mime_type');
        }

        // Явный запрет отрицательных значений
        if ($size !== null && $size < 0) {
            throw new UnprocessableException('invalid_size');
        }

        $max = $this->getMaxSizeBytes();
        if ($size !== null && $size > $max) {
            throw new UnprocessableException('file_too_large');
        }
    }

    /**
     * Нормализует/проверяет enc_*.
     * Разрешённые значения enc_alg: 'none', 'aes-256-gcm'.
     * enc_meta допускает ключи: iv, tag, salt; строковые значения до 256 символов (hex/base64url/utf-8).
     * При enc_alg='none' enc_meta должен быть null.
     *
     * Возвращает [enc_alg, enc_meta_normalized].
     * При любой несостоятельности кидает UnprocessableException.
     */
    private function validateEncryptionParams(string $encAlg, ?array $encMeta): array
    {
        $alg = strtolower(trim($encAlg));
        if ($alg === '') {
            $alg = 'none';
        }

        $allowedAlgs = ['none', 'aes-256-gcm'];
        if (!in_array($alg, $allowedAlgs, true)) {
            throw new UnprocessableException('invalid_enc_alg');
        }

        // Без шифрования — enc_meta не должно быть
        if ($alg === 'none') {
            if ($encMeta !== null) {
                throw new UnprocessableException('invalid_enc_meta');
            }
            return ['none', null];
        }

        // enc_alg === 'aes-256-gcm'
        $meta = is_array($encMeta) ? $encMeta : [];
        $out = [];
        $allowedKeys = ['iv','tag','salt'];

        foreach ($allowedKeys as $k) {
            if (!array_key_exists($k, $meta)) {
                continue;
            }
            $v = $meta[$k];
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '' || mb_strlen($v, 'UTF-8') > 256) {
                continue;
            }
            $out[$k] = $v;
        }

        // Для aes-256-gcm обязательно наличие iv и tag
        if (!isset($out['iv']) || !isset($out['tag'])) {
            throw new UnprocessableException('invalid_enc_meta');
        }

        return [$alg, $out];
    }

    private function getMaxSizeBytes(): int
    {
        $max = ConfigHelper::getInt('MEDIA_MAX_SIZE_BYTES', 0);
        if ($max > 0) {
            return $max;
        }
        
        $mb = ConfigHelper::getInt('LIMITS_MEDIA_UPLOAD_MB_MAX', 50);
        return max(1, $mb) * 1024 * 1024;
    }

    private function generateKey(string $mime): string
    {
        $ext = $this->extByMime($mime);
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $h = bin2hex(random_bytes(16));
        $p1 = substr($h,0,2); 
        $p2 = substr($h,2,2);
        $dt = new \DateTimeImmutable('now');
        $y = $dt->format('Y'); 
        $m = $dt->format('m'); 
        $d = $dt->format('d');
        
        return "chat-media/{$p1}/{$p2}/{$y}/{$m}/{$d}/{$uuid}.{$ext}";
    }

    private function extByMime(string $mime): string
    {
        return match($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/m4a' => 'm4a',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }

    private function decodeVideoProfiles($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $data = json_decode($raw, true);
        } elseif (is_array($raw)) {
            // уже декодировано
            $data = $raw;
        } else {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $out = [];
        foreach ($data as $label => $p) {
            if (!is_string($label) || !is_array($p)) {
                continue;
            }
            if (empty($p['s3_key'])) {
                continue;
            }
            $out[$label] = [
                's3_key' => (string)$p['s3_key'],
                'width'  => isset($p['width']) ? (int)$p['width'] : null,
                'height' => isset($p['height']) ? (int)$p['height'] : null,
            ];
        }

        return $out === [] ? null : $out;
    }


    /**
     * GET /v1/media/file/{id}
     *
     * Параметры:
     *   - variant: preview|processed|original (по умолчанию processed)
     *   - quality: 360p|480p|720p (для видео; опционально)
     *
     * Ответ:
     *   { status: "ok", attachment_id, variant, quality, s3_key, url, expires_in }
     */
    public function file(Request $req, Response $res, array $args): array
    {
        $userId = $this->requireAuth($req);
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            throw new NotFoundException('attachment_not_found');
        }

        $variant = (string)($req->query('variant') ?? 'processed');
        if (!in_array($variant, ['preview', 'processed', 'original'], true)) {
            $variant = 'processed';
        }

        $quality = (string)($req->query('quality') ?? '');
        if ($quality !== '' && !in_array($quality, ['360p', '480p', '720p'], true)) {
            $quality = '';
        }

        $repo = new AttachmentsRepository();
        $att  = $repo->getAccessibleForUser($id, $userId);
        if (!$att) {
            throw new NotFoundException('attachment_not_found');
        }

        $s3Key = null;

        // 1) Выбираем базовый ключ для варианта
        switch ($variant) {
            case 'preview':
                if ((int)($att['preview_ready'] ?? 0) === 1 && !empty($att['preview_s3_key'])) {
                    $s3Key = (string)$att['preview_s3_key'];
                }
                break;

            case 'processed':
                if ((int)($att['processed_ready'] ?? 0) === 1 && !empty($att['processed_s3_key'])) {
                    $s3Key = (string)$att['processed_s3_key'];
                }
                break;

            case 'original':
            default:
                $s3Key = (string)$att['s3_key'];
                break;
        }

        // Если processed/preview ещё не готовы — fallback на original
        if ($s3Key === null) {
            $s3Key = (string)$att['s3_key'];
        }

        // 2) Если это видео и запрошено качество — подменяем суффикс (_360p/_480p/_720p)
        if ($quality !== '' && strncmp((string)$att['mime_type'], 'video/', 6) === 0) {
            // Ожидаем, что processed_s3_key имеет вид "..._480p.mp4"
            $baseKey = (string)($att['processed_s3_key'] ?: $s3Key);
            $candidate = preg_replace('~_(360p|480p|720p)\.mp4$~', "_{$quality}.mp4", $baseKey, 1, $count);

            if ($count === 1 && is_string($candidate)) {
                $s3Key = $candidate;
            }
            // Если паттерн не подошёл — остаёмся на базовом ключе
        }

        $s3 = new S3Service();
        $expires = ConfigHelper::getInt(
            'MEDIA_PRESIGN_EXPIRES',
            ConfigHelper::getInt('S3_PRESIGN_EXPIRE_SEC', 900)
        );

        $url = $s3->presignGet($s3Key, $expires);

        return [
            'status'         => 'ok',
            'attachment_id'  => $id,
            'variant'        => $variant,
            'quality'        => $quality ?: null,
            's3_key'         => $s3Key,
            'url'            => $url,
            'expires_in'     => $expires,
        ];
    }
}