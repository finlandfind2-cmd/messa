<?php
declare(strict_types=1);

namespace Messa\Controllers\OAuth;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Core\Env;
use Messa\Core\Redis as RedisCore;
use Messa\Repos\OAuthYandexRepository;
use Messa\Services\Telemost\OAuthService;
use Messa\Controllers\BaseController;

final class YandexController extends BaseController
{
    public function start(Request $req, Response $res): array
    {
        $userId = $this->requireAuth($req);

        // Жёстко используем redirect из ENV (чтобы не было рассинхрона/открытого редиректа)
        $redirect = (string) Env::get('OAUTH_YANDEX_REDIRECT_URI', 'https://api.chatzee.ru/oauth/yandex/callback');
        $scopes   = (string) Env::get('OAUTH_YANDEX_SCOPES', 'telemost-api:conferences.create telemost-api:conferences.read telemost-api:conferences.update');

        $state = bin2hex(random_bytes(16));

        $prefix = (string) Env::get('REDIS_PREFIX', 'messa:');
        $key    = $prefix . 'oauth:yandex:state:' . $state;

        try {
            $r = RedisCore::client();
            $ok = $r->setex($key, 600, json_encode(['u' => $userId, 'redir' => $redirect], JSON_UNESCAPED_SLASHES));
            if ($ok !== true && $ok !== 'OK') {
                $res->setStatus(503);
                return ['error' => 'unavailable', 'code' => 'redis_set_failed'];
            }
        } catch (\Throwable $e) {
            $res->setStatus(503);
            return ['error' => 'unavailable', 'code' => 'redis_unavailable'];
        }

        $authUrl = (new OAuthService())->authUrl($state, $redirect, $scopes);
        return ['status' => 'ok', 'auth_url' => $authUrl, 'state' => $state];
    }

    public function callback(Request $req, Response $res): array
    {
        $code  = (string) ($req->query('code') ?? '');
        $state = (string) ($req->query('state') ?? '');

        if ($code === '' || $state === '') {
            $res->setStatus(400);
            return ['error' => 'bad_request', 'code' => 'missing_code_or_state'];
        }

        $prefix = (string) Env::get('REDIS_PREFIX', 'messa:');
        $key    = $prefix . 'oauth:yandex:state:' . $state;

        try {
            $r  = RedisCore::client();
            $st = $r->get($key);
            if (!$st) {
                $res->setStatus(400);
                return ['error' => 'bad_request', 'code' => 'state_expired'];
            }
            $r->del($key);
        } catch (\Throwable $e) {
            $res->setStatus(503);
            return ['error' => 'unavailable', 'code' => 'redis_unavailable'];
        }

        $data     = json_decode((string) $st, true) ?: [];
        $userId   = (int) ($data['u'] ?? 0);
        // Используем только тот redirect, который мы сами же клали в state на старте
        $redirect = (string) ($data['redir'] ?? Env::get('OAUTH_YANDEX_REDIRECT_URI', 'https://api.chatzee.ru/oauth/yandex/callback'));

        if ($userId <= 0) {
            $res->setStatus(400);
            return ['error' => 'bad_request', 'code' => 'invalid_user'];
        }

        try {
            $oauth = new OAuthService();
            $tok   = $oauth->exchangeCode($code, $redirect);
        } catch (\Throwable $e) {
            $res->setStatus(502);
            return ['error' => 'bad_gateway', 'code' => 'oauth_exchange_failed'];
        }

        try {
            (new OAuthYandexRepository())->upsert($userId, $tok);
        } catch (\Throwable $e) {
            $res->setStatus(500);
            return ['error' => 'server_error', 'code' => 'token_persist_failed'];
        }

        $next = (string) Env::get('OAUTH_YANDEX_DONE_URL', 'https://app.chatzee.ru/oauth/yandex/done?status=ok');
        return ['status' => 'ok', 'next' => $next];
    }
}
