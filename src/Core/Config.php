<?php
declare(strict_types=1);
namespace Messa\Core;

final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    }
}
