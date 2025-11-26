<?php
declare(strict_types=1);
namespace Messa\Core;

final class Logger
{
    private static ?string $logFile = null;

    private static function ensure(): void
    {
        $dir = Config::get('LOG_DIR', 'var/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $logPath = $dir . '/app.log';
        if (is_file($logPath)) {
            $mtime = (int)@filemtime($logPath);
            if ($mtime > 0 && gmdate('Y-m-d', $mtime) !== $today) {
                $rotated = sprintf('%s/app-%s.log', $dir, gmdate('Y-m-d', $mtime));
                @rename($logPath, $rotated);
            }
        }
        self::$logFile = $logPath;
    }

    public static function info(string $msg, array $ctx = []): void { self::write('INFO', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::write('ERROR', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::write('WARNING', $msg, $ctx); }
    public static function debug(string $msg, array $ctx = []): void {
        if (ConfigHelper::getBool('APP_DEBUG', false)) {
            self::write('DEBUG', $msg, $ctx);
        }
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        if (self::$logFile === null) self::ensure();
        $line = [
            'ts' => gmdate('c'),
            'level' => $level,
            'msg' => $msg,
            'ctx' => $ctx,
        ];
        $payload = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $fh = @fopen(self::$logFile, 'ab');
        if ($fh) {
            @flock($fh, LOCK_EX);
            @fwrite($fh, $payload);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}