<?php
declare(strict_types=1);

namespace Messa\Http\Middleware;

use Messa\Core\MicroserviceProxy as Proxy;
use Messa\Http\Request;
use Messa\Http\Response;

final class MicroserviceProxy
{
    public static function handle(Request $req, Response $res, callable $next): Response
    {
        $proxy = new Proxy();
        $proxied = $proxy->tryProxy($req);
        if ($proxied !== null) {
            return $proxied;
        }

        return $next($req, $res);
    }
}
