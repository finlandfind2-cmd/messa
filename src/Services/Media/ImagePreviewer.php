<?php
declare(strict_types=1);
namespace Messa\Services\Media;

use Messa\Services\S3\S3Service;
use Messa\Core\Env;

final class ImagePreviewer
{
    /**
     * Скачивает оригинал из S3 во временный файл, создаёт превью maxEdge,
     * загружает JPEG в S3 и возвращает [previewKey, width, height].
     */
    public function makeAndUpload(string $origKey, string $mime): array
    {
        $s3 = new S3Service();

        // Временные файлы
        $tmpOrig = tempnam(sys_get_temp_dir(), 'messa_o_') ?: '/tmp/messa_o_' . bin2hex(random_bytes(4));
        $tmpPrev = tempnam(sys_get_temp_dir(), 'messa_p_') ?: '/tmp/messa_p_' . bin2hex(random_bytes(4));

        // Скачиваем оригинал
        // Используем presign GET и file_get_contents для совместимости с shared
        $url = $s3->presignGet($origKey, 300);
        $data = file_get_contents($url);
        if ($data === false) {
            throw new \RuntimeException('Failed to download source for preview');
        }
        file_put_contents($tmpOrig, $data);

        // Готовим изображение
        [$src, $w, $h] = $this->loadImage($tmpOrig, $mime);
        [$nw, $nh] = $this->fitBox($w, $h, (int)Env::get('MEDIA_PREVIEW_MAX_EDGE', '512'));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        // Сохраняем в JPEG (универсально для предпросмотра)
        $q = max(50, min(95, (int)Env::get('MEDIA_PREVIEW_JPEG_QUALITY', '82')));
        imagejpeg($dst, $tmpPrev, $q);
        imagedestroy($dst);
        imagedestroy($src);

        // Имя превью
        $previewKey = $this->makePreviewKey($origKey);
        // Грузим превью в S3
        $this->putFileToS3($previewKey, $tmpPrev, 'image/jpeg');

        // Чистим временные файлы
        @unlink($tmpOrig); @unlink($tmpPrev);

        return [$previewKey, $nw, $nh];
    }

    private function loadImage(string $path, string $mime): array
    {
        switch ($mime) {
            case 'image/jpeg':
                $src = imagecreatefromjpeg($path); break;
            case 'image/png':
                $src = imagecreatefrompng($path); break;
            case 'image/webp':
                if (!function_exists('imagecreatefromwebp')) {
                    // деградируем: пробуем через jpegdecode — не надёжно, лучше отказ
                    throw new \RuntimeException('WEBP not supported by GD');
                }
                $src = imagecreatefromwebp($path); break;
            default:
                throw new \RuntimeException('Unsupported image mime: ' . $mime);
        }
        if (!$src) throw new \RuntimeException('Failed to load image');
        $w = imagesx($src); $h = imagesy($src);
        return [$src, $w, $h];
    }

    private function fitBox(int $w, int $h, int $maxEdge): array
    {
        if ($w <= $maxEdge && $h <= $maxEdge) return [$w, $h];
        if ($w >= $h) {
            $nw = $maxEdge;
            $nh = (int)round($h * ($maxEdge / $w));
        } else {
            $nh = $maxEdge;
            $nw = (int)round($w * ($maxEdge / $h));
        }
        return [$nw, $nh];
    }

    private function makePreviewKey(string $origKey): string
    {
        // заменяем префикс на previews/ сохраняя структуру
        if (str_starts_with($origKey, 'chat-media/')) {
            $tail = substr($origKey, strlen('chat-media/'));
            // меняем расширение на .jpg
            $tail = preg_replace('~\.[A-Za-z0-9]+$~', '.jpg', $tail) ?? ($tail . '.jpg');
            return 'previews/' . $tail;
        }
        return 'previews/' . ltrim($origKey, '/');
    }

    private function putFileToS3(string $key, string $localPath, string $mime): void
    {
        // Чтобы не тащить весь SDK для PUT, используем presign PUT и curl/file_get_contents
        $s3 = new S3Service();
        $pres = $s3->presignPut($key, $mime, 300);
        $opts = [
            'http' => [
                'method' => 'PUT',
                'header' => "Content-Type: $mime\r\n",
                'content' => file_get_contents($localPath),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ];
        $ctx = stream_context_create($opts);
        $resp = file_get_contents($pres['url'], false, $ctx);
        // проверим код ответа
        $hdr = $http_response_header ?? [];
        $code = 0;
        foreach ($hdr as $line) {
            if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $line, $m)) { $code = (int)$m[1]; break; }
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Failed to upload preview to S3, code='.$code);
        }
    }
}
