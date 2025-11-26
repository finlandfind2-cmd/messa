<?php
declare(strict_types=1);
namespace Messa\Core;

final class ConfigHelper
{
    public static function getInt(string $key, int $default = 0): int
    {
        $value = Config::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        
        return (int)$value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = Config::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function getString(string $key, string $default = ''): string
    {
        $value = Config::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        
        return (string)$value;
    }

    public static function getArray(string $key, array $default = []): array
    {
        $value = Config::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        
        return array_filter(array_map('trim', explode(',', $value)));
    }

    private static array $security = [];

    public static function getSecurity(): array
    {
        if (empty(self::$security)) {
            self::$security = require __DIR__ . '/../../config/security.php';
        }
        return self::$security;
    }
}