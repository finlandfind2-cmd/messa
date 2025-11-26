<?php
declare(strict_types=1);

namespace Messa\Services\Media;

use Messa\Services\S3\S3Service;
use Messa\Core\Env;
use Messa\Core\Logger;

final class FFmpegTranscoder
{
    private string $ffmpeg;
    private string $ffprobe;
    private int $timeoutSec;
    private int $maxInputBytes;
    private int $threads;
    private string $logLevel;

    public function __construct()
    {
        $this->ffmpeg  = (string)Env::get('FFMPEG_BIN', '/usr/bin/ffmpeg');
        $this->ffprobe = (string)Env::get('FFPROBE_BIN', '/usr/bin/ffprobe');
        $this->timeoutSec   = max(30, (int)Env::get('MEDIA_FFMPEG_TIMEOUT_SEC', '120'));
        $this->maxInputBytes = max(1, (int)Env::get('MEDIA_FFMPEG_MAX_INPUT_MB','80')) * 1024 * 1024;
        $this->threads = max(1, (int)Env::get('MEDIA_FFMPEG_THREADS', '1'));
        $this->logLevel = (string)Env::get('MEDIA_FFMPEG_LOGLEVEL', 'error'); // error|warning
    }

    private function checkProc(): void
    {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            throw new \RuntimeException('proc_open disabled on host');
        }
    }

    /** Возвращает [tmpPath, sizeBytes] */
    private function downloadFromS3(string $s3Key): array
    {
        $s3 = new S3Service();
        $head = $s3->head($s3Key);
        $size = (int)($head['content_length'] ?? 0);
        if ($size <= 0 || $size > $this->maxInputBytes) {
            throw new \RuntimeException('Source size is invalid or exceeds limit');
        }

        $url = $s3->presignGet($s3Key, 300);
        $tmp = tempnam(sys_get_temp_dir(), 'messa_in_') ?: '/tmp/messa_in_' . bin2hex(random_bytes(4));
        $data = file_get_contents($url);
        if ($data === false) {
            throw new \RuntimeException('Failed to download source');
        }
        file_put_contents($tmp, $data);
        return [$tmp, $size];
    }

    /** @return array{duration_ms:int|null,width:int|null,height:int|null} */
    private function probe(string $path): array
    {
        $this->checkProc();
        $cmd = [
            $this->ffprobe, '-v', 'error', '-print_format', 'json',
            '-show_streams', '-select_streams', 'v:0', $path
        ];
        [$code, $out, $_] = $this->run($cmd);
        if ($code !== 0) {
            return ['duration_ms'=>null,'width'=>null,'height'=>null];
        }
        $j = json_decode($out, true);
        $s = $j['streams'][0] ?? [];
        $dur = isset($s['duration']) ? (int)round((float)$s['duration'] * 1000) : null;
        $w = isset($s['width']) ? (int)$s['width'] : null;
        $h = isset($s['height']) ? (int)$s['height'] : null;
        return ['duration_ms'=>$dur,'width'=>$w,'height'=>$h];
    }

    /** Общий раннер с таймаутом, без шелла (аргументы — массив) */
    private function run(array $cmd): array
    {
        $this->checkProc();
        $des = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $des, $pipes, null, null);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('proc_open failed');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = ''; $err = '';
        $deadline = time() + $this->timeoutSec;
        while (true) {
            $read = [$pipes[1], $pipes[2]]; $write = null; $except = null;
            $tmo = max(0, $deadline - time());
            if ($tmo === 0) {
                proc_terminate($proc, 9);
                throw new \RuntimeException('ffmpeg timeout');
            }
            if (stream_select($read, $write, $except, $tmo, 0) === false) {
                break;
            }
            foreach ($read as $r) {
                $chunk = fread($r, 65536) ?: '';
                if ($r === $pipes[1]) $out .= $chunk; else $err .= $chunk;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) break;
        }
        foreach ($pipes as $p) @fclose($p);
        $code = proc_close($proc);
        return [$code, $out, $err];
    }

    /** Транскод аудио: OGG/Opus (voip) либо M4A (AAC) с безопасными флагами */
    public function transcodeAudio(string $srcKey, string $outKeyBase): array
    {
        [$in, $_] = $this->downloadFromS3($srcKey);
        $useOpus = $this->isEncoderAvailable('libopus');
        $bitOpus = (string)Env::get('MEDIA_OPUS_BITRATE_K', '24') . 'k';
        $bitAac  = (string)Env::get('MEDIA_AAC_BITRATE_K', '96') . 'k';

        if ($useOpus) {
            $out = tempnam(sys_get_temp_dir(), 'messa_out_') . '.ogg';
            $cmd = [
                $this->ffmpeg, '-y', '-nostdin', '-hide_banner', '-loglevel', $this->logLevel, '-threads', (string)$this->threads,
                '-i', $in,
                '-vn',
                '-c:a', 'libopus', '-b:a', $bitOpus, '-vbr', 'on',
                '-compression_level', '10', '-application', 'voip', '-frame_duration', '20',
                '-map_metadata', '-1', '-map_chapters', '-1', '-sn',
                $out,
            ];
            [$code, , $err] = $this->run($cmd);
            if ($code !== 0) throw new \RuntimeException('ffmpeg audio opus failed: ' . $err);
            $mime = 'audio/ogg';
            $s3Key = $outKeyBase . '.ogg';
        } else {
            $out = tempnam(sys_get_temp_dir(), 'messa_out_') . '.m4a';
            $cmd = [
                $this->ffmpeg, '-y', '-nostdin', '-hide_banner', '-loglevel', $this->logLevel, '-threads', (string)$this->threads,
                '-i', $in,
                '-vn',
                '-c:a', 'aac', '-b:a', $bitAac, '-movflags', '+faststart',
                '-map_metadata', '-1', '-map_chapters', '-1', '-sn',
                $out,
            ];
            [$code, , $err] = $this->run($cmd);
            if ($code !== 0) throw new \RuntimeException('ffmpeg audio aac failed: ' . $err);
            $mime = 'audio/m4a';
            $s3Key = $outKeyBase . '.m4a';
        }

        $probe = $this->probe($out);
        (new S3Service())->putFile($s3Key, $out, $mime);
        @unlink($in); @unlink($out);

        return [
            's3_key' => $s3Key,
            'mime' => $mime,
            'duration_ms' => $probe['duration_ms'],
            'width' => null, 'height' => null,
        ];
    }

    /** Квадратное MP4 для «кружков» + нормализация видео; также делает постер JPEG (безопасные флаги) */
    public function transcodeVideoSquare(string $srcKey, string $outKeyBase, int $edge, int $crf, string $preset, int $posterEdge): array
    {
        [$in, $_] = $this->downloadFromS3($srcKey);
        $out = tempnam(sys_get_temp_dir(), 'messa_out_') . '.mp4';
        $poster = tempnam(sys_get_temp_dir(), 'messa_pic_') . '.jpg';

        $vf = sprintf('scale=%1$d:%1$d:force_original_aspect_ratio=decrease,pad=%1$d:%1$d:(ow-iw)/2:(oh-ih)/2,format=yuv420p', $edge);

        // Если во входе нет аудио — не пытаться кодировать звук
        $hasAudio = $this->hasAudio($in);
        $cmd = [
            $this->ffmpeg, '-y', '-nostdin', '-hide_banner', '-loglevel', $this->logLevel, '-threads', (string)$this->threads,
            '-i', $in,
            '-vf', $vf, '-map', '0:v:0',
            '-c:v', 'libx264', '-preset', $preset, '-crf', (string)$crf,
            '-movflags', '+faststart', '-map_metadata', '-1', '-map_chapters', '-1', '-sn',
        ];
        if ($hasAudio) {
            array_push($cmd, '-map', '0:a:0?', '-c:a', 'aac', '-b:a', '96k');
        } else {
            $cmd[] = '-an';
        }
        $cmd[] = $out;
        [$code, , $err] = $this->run($cmd);
        if ($code !== 0) throw new \RuntimeException('ffmpeg video failed: ' . $err);

        // постер из первого кадра
        [$code2, , $err2] = $this->run([
            $this->ffmpeg, '-y', '-nostdin', '-hide_banner', '-loglevel', $this->logLevel, '-threads', '1',
            '-ss', '0', '-i', $in, '-map', '0:v:0', '-frames:v', '1',
            '-vf', 'scale='.(string)$posterEdge.':-2', '-an', '-sn', '-map_metadata', '-1', '-map_chapters', '-1',
            $poster
        ]);
        if ($code2 !== 0) Logger::warning('ffmpeg poster failed', ['e'=>$err2]);

        $probe = $this->probe($out);
        $s3 = new S3Service();
        $videoKey = $outKeyBase . '.mp4';
        $posterKey = $outKeyBase . '.jpg';
        $s3->putFile($videoKey, $out, 'video/mp4');
        if (is_file($poster)) {
            $s3->putFile($posterKey, $poster, 'image/jpeg');
        }

        @unlink($in); @unlink($out); @unlink($poster);

        return [
            's3_key' => $videoKey,
            'poster_s3_key' => is_file($poster) ? $posterKey : null,
            'mime' => 'video/mp4',
            'duration_ms' => $probe['duration_ms'],
            'width' => $probe['width'], 'height' => $probe['height'],
        ];
    }

    /** Быстрая проверка наличия аудиопотока во входном файле */
    private function hasAudio(string $path): bool
    {
        try {
            [$code, $out, ] = $this->run([$this->ffprobe, '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
            return $code === 0 && trim((string)$out) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function isEncoderAvailable(string $enc): bool
    {
        try {
            [$code,,] = $this->run([$this->ffmpeg, '-v', 'error', '-h', 'encoder='.$enc]);
            return $code === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Транскод видео в несколько профилей (360p / 480p / 720p и т.п.)
     *
     * @param string $srcKey     S3 ключ исходного файла
     * @param string $outKeyBase Базовый ключ БЕЗ суффикса качества и расширения
     *                           (например: "media/proc/chat-media/.../uuid")
     * @param array  $profiles   Ассоциативный массив: ['360p' => 360, '480p' => 480, ...]
     *                           значение = целевая высота (px)
     * @param int    $posterEdge Максимальный размер постера (по короткой стороне)
     *
     * @return array{
     *   default_s3_key:string,
     *   mime:string,
     *   duration_ms:int|null,
     *   width:int|null,
     *   height:int|null,
     *   poster_s3_key:?string,
     *   profiles: array<string, array{ s3_key:string, width:int|null, height:int|null }>
     * }
     */
    public function transcodeVideoMultiProfiles(
        string $srcKey,
        string $outKeyBase,
        array $profiles,
        int $posterEdge
    ): array {
        // Скачиваем оригинал
        [$in, $_] = $this->downloadFromS3($srcKey);

        // Общая информация об исходнике
        $probeIn = $this->probe($in);
        $durationMs = $probeIn['duration_ms'];

        $hasAudio = $this->hasAudio($in);
        $crf    = (int)Env::get('MEDIA_VIDEO_CRF', '23');
        $preset = (string)Env::get('MEDIA_VIDEO_PRESET', 'veryfast');

        // Профили по умолчанию, если массив пустой
        if ($profiles === []) {
            $profiles = [
                '360p' => 360,
                '480p' => 480,
                '720p' => 720,
            ];
        }

        $results = [];
        $s3 = new S3Service();

        foreach ($profiles as $label => $height) {
            $height = (int)$height;
            if ($height <= 0) {
                continue;
            }

            $out = tempnam(sys_get_temp_dir(), 'messa_v_') . '.mp4';

            // Масштабирование с сохранением пропорций, высота = $height
            $vf = sprintf(
                'scale=-2:%d:force_original_aspect_ratio=decrease,format=yuv420p',
                $height
            );

            $cmd = [
                $this->ffmpeg, '-y', '-nostdin', '-hide_banner',
                '-loglevel', $this->logLevel,
                '-threads', (string)$this->threads,
                '-i', $in,
                '-vf', $vf,
                '-map', '0:v:0',
                '-c:v', 'libx264',
                '-preset', $preset,
                '-crf', (string)$crf,
                '-movflags', '+faststart',
                '-map_metadata', '-1',
                '-map_chapters', '-1',
                '-sn',
            ];

            if ($hasAudio) {
                $cmd[] = '-map';
                $cmd[] = '0:a:0?';
                $cmd[] = '-c:a';
                $cmd[] = 'aac';
                $cmd[] = '-b:a';
                $cmd[] = '96k';
            } else {
                $cmd[] = '-an';
            }

            $cmd[] = $out;

            [$code, , $err] = $this->run($cmd);
            if ($code !== 0) {
                @unlink($out);
                @unlink($in);
                throw new \RuntimeException('ffmpeg video profile '.$label.' failed: '.$err);
            }

            $probeOut = $this->probe($out);
            $key = $outKeyBase . '_' . $label . '.mp4';

            $s3->putFile($key, $out, 'video/mp4');
            @unlink($out);

            $results[$label] = [
                's3_key' => $key,
                'width'  => $probeOut['width'],
                'height' => $probeOut['height'],
            ];
        }

        // Постер из первого кадра
        $poster = tempnam(sys_get_temp_dir(), 'messa_pic_') . '.jpg';
        [$code2, , $err2] = $this->run([
            $this->ffmpeg, '-y', '-nostdin', '-hide_banner',
            '-loglevel', $this->logLevel,
            '-threads', '1',
            '-ss', '0',
            '-i', $in,
            '-map', '0:v:0',
            '-frames:v', '1',
            '-vf', 'scale='.(string)$posterEdge.':-2',
            '-an', '-sn',
            '-map_metadata', '-1',
            '-map_chapters', '-1',
            $poster,
        ]);

        $posterKey = null;
        if ($code2 === 0 && is_file($poster)) {
            $posterKey = $outKeyBase . '_poster.jpg';
            $s3->putFile($posterKey, $poster, 'image/jpeg');
            @unlink($poster);
        } else {
            @unlink($poster);
            Logger::warning('ffmpeg poster multi-profile failed', ['e' => $err2]);
        }

        @unlink($in);

        // Выбираем дефолтный профиль (из env или 480p)
        $defaultProfile = (string)Env::get('MEDIA_VIDEO_DEFAULT_PROFILE', '480p');
        if (!isset($results[$defaultProfile])) {
            // Берём первый доступный
            $first = reset($results);
        } else {
            $first = $results[$defaultProfile];
        }

        return [
            'default_s3_key' => $first['s3_key'],
            'poster_s3_key'  => $posterKey,
            'mime'           => 'video/mp4',
            'duration_ms'    => $durationMs,
            'width'          => $first['width'],
            'height'         => $first['height'],
            'profiles'       => $results,
        ];
    }
}
