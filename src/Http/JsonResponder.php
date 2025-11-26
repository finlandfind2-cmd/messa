<?php
declare(strict_types=1);
namespace Messa\Http;

final class JsonResponder
{
    public static function ok(Response $res, array $data = []): Response {
    // единообразный Request-Id и для 2xx
    $res->header('Request-Id', bin2hex(random_bytes(8)));
    return $res->status(200)->json($data);
 }

    public static function error(Response $res, int $status, string $code, string $message, array $details = []): Response
    {
        return $res->status($status)->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'trace_id' => bin2hex(random_bytes(8)),
            ]
        ]);
    }
}
