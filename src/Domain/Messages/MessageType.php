<?php
declare(strict_types=1);
namespace Messa\Domain\Messages;

final class MessageType
{
    public const TEXT       = 'text';
    public const IMAGE      = 'image';
    public const AUDIO      = 'audio';
    public const VOICE      = 'voice';
    public const VIDEO      = 'video';
    public const VIDEO_NOTE = 'video_note';
    public const DOCUMENT   = 'document';
    public const STICKER    = 'sticker';
    public const SYSTEM     = 'system';

    public static function isValid(string $t): bool
    {
        return in_array($t, [
            self::TEXT, self::IMAGE, self::AUDIO, self::VOICE,
            self::VIDEO, self::VIDEO_NOTE, self::DOCUMENT, self::STICKER, self::SYSTEM
        ], true);
    }
}
