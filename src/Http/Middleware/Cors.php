<?php
declare(strict_types=1);
namespace Messa\Http\Middleware;

use Messa\Core\ConfigHelper;
use Messa\Http\Request;
use Messa\Http\Response;

final class Cors
{
    public static function handle(Request $req, Response $res, callable $next): Response
    {
        $securityConfig = ConfigHelper::getSecurity();
        $corsConfig = (array)($securityConfig['cors'] ?? []);

        $origin = $req->header('origin');
        $allowOrigin = null;

        if ($origin && in_array($origin, (array)($corsConfig['allowed_origins'] ?? []), true)) {
            $allowOrigin = $origin;
        } else {
            return $next($req, $res);
        }

        $res->header('Access-Control-Allow-Origin', $allowOrigin);
        // чтобы прокси не кэшировали под другой Origin
        $res->header('Vary', 'Origin');
        $res->header(
            'Access-Control-Allow-Credentials',
            (array_key_exists('allow_credentials', $corsConfig) ? ($corsConfig['allow_credentials'] ? 'true' : 'false') : 'true')
        );
        $res->header('Access-Control-Allow-Headers', implode(', ', (array)($corsConfig['allowed_headers'] ?? [])));
        $res->header('Access-Control-Expose-Headers', implode(', ', (array)($corsConfig['expose_headers'] ?? [])));
        $res->header('Access-Control-Allow-Methods', implode(', ', (array)($corsConfig['allowed_methods'] ?? [])));
        $res->header('Access-Control-Max-Age', (string)($corsConfig['max_age'] ?? 600));

        // Надёжно определяем метод (учтём и метод-геттер, и публичное поле)
        $method = \is_callable([$req, 'method']) ? \strtoupper((string)$req->method()) : \strtoupper((string)($req->method ?? ''));
        if ($method === 'OPTIONS') {
            return $res->status(204)
                ->header('Content-Length', '0')
                ->body('');
        }

        return $next($req, $res);
    }
}
