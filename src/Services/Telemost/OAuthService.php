<?php
declare(strict_types=1);
namespace Messa\Services\Telemost;

use Messa\Core\Env;
use Messa\Core\Logger;

final class OAuthService
{
    private function baseAuth(): string { 
        return 'https://oauth.yandex.ru';
    }

    public function authUrl(string $state, string $redirectUri, string $scopes): string
    {
        $clientId = (string)Env::get('OAUTH_YANDEX_CLIENT_ID', '');
        if (empty($clientId)) {
            throw new \RuntimeException('OAuth client_id not configured');
        }
        $q = http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => $scopes,
        ], '', '&', PHP_QUERY_RFC3986);
        return $this->baseAuth() . '/authorize?' . $q;
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $clientId = (string)Env::get('OAUTH_YANDEX_CLIENT_ID', '');
        if (empty($clientId)) {
            throw new \RuntimeException('OAuth client_id not configured');
        }
        $secret   = (string)Env::get('OAUTH_YANDEX_CLIENT_SECRET', '');
        $tokenUrl = (string)Env::get('OAUTH_YANDEX_TOKEN_URL', $this->baseAuth() . '/token');
        $payload = http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'client_id'    => $clientId,
            'client_secret'=> $secret,
            'redirect_uri' => $redirectUri,
        ], '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)Env::get('HTTP_CONNECT_TIMEOUT', '3'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => (int)Env::get('HTTP_TIMEOUT', '5'),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $codeHttp >= 300) {
            $err = curl_error($ch);
            curl_close($ch);
            Logger::error('Yandex token exchange failed', [
                'http_code' => $codeHttp,
                'error' => $err
            ]);
            throw new \RuntimeException('OAuth service temporarily unavailable');
        }
        curl_close($ch);
        $data = json_decode((string)$resp, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new \RuntimeException('Invalid token response');
        }
        $now = time();
        $exp = isset($data['expires_in']) ? ($now + (int)$data['expires_in']) : null;
        return [
            'access_token'  => (string)$data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string)$data['refresh_token'] : null,
            'scope'         => isset($data['scope']) ? (string)$data['scope'] : null,
            'token_type'    => isset($data['token_type']) ? (string)$data['token_type'] : 'OAuth',
            'expires_at'    => $exp ? gmdate('Y-m-d H:i:s', $exp) : null,
        ];
    }

    public function refresh(string $refreshToken): array
    {
        $clientId = (string)Env::get('OAUTH_YANDEX_CLIENT_ID', '');
        if (empty($clientId)) {
            throw new \RuntimeException('OAuth client_id not configured');
        }
        $secret   = (string)Env::get('OAUTH_YANDEX_CLIENT_SECRET', '');
        $tokenUrl = (string)Env::get('OAUTH_YANDEX_TOKEN_URL', $this->baseAuth() . '/token');
        $payload = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientId,
            'client_secret' => $secret,
        ], '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)Env::get('HTTP_CONNECT_TIMEOUT', '3'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => (int)Env::get('HTTP_TIMEOUT', '5'),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $codeHttp >= 300) {
            Logger::error('Yandex OAuth request failed', [
                'http_code' => $codeHttp,
                'error' => $curlError
            ]);
            throw new \RuntimeException('OAuth service temporarily unavailable');
        }

        $data = json_decode((string)$resp, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            throw new \RuntimeException('Invalid refresh response');
        }
        
        $now = time();
        $exp = isset($data['expires_in']) ? ($now + (int)$data['expires_in']) : null;
        return [
            'access_token'  => (string)$data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string)$data['refresh_token'] : null,
            'scope'         => isset($data['scope']) ? (string)$data['scope'] : null,
            'token_type'    => isset($data['token_type']) ? (string)$data['token_type'] : 'OAuth',
            'expires_at'    => $exp ? gmdate('Y-m-d H:i:s', $exp) : null,
        ];
    }
}
