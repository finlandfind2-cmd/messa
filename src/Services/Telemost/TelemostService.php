<?php
declare(strict_types=1);

namespace Messa\Services\Telemost;

use Messa\Core\Env;
use Messa\Core\Logger;

final class TelemostService
{
    /**
     * Endpoint создания конференций Telemost.
     * Пример: https://cloud-api.yandex.net/v1/telemost-api/conferences
     * См. доку: https://yandex.ru/dev/telemost/doc/ru/conference-create
     */
    private string $createUrl;

    /**
     * Публичная база для join_url (обычно https://telemost.yandex.ru/j)
     */
    private string $publicBase;

    /**
     * Режим моков (без внешних вызовов).
     * TELEMOST_MOCK=1 -> включен.
     */
    private bool $mock;

    public function __construct()
    {
        $this->createUrl  = (string) Env::get(
            'TELEMOST_CREATE_ROOM_URL',
            'https://cloud-api.yandex.net/v1/telemost-api/conferences'
        );
        $this->publicBase = rtrim(
            (string) Env::get('TELEMOST_PUBLIC_BASE', 'https://telemost.yandex.ru/j'),
            '/'
        );
        $this->mock       = ((string) Env::get('TELEMOST_MOCK', '0')) === '1';
    }

    /**
     * УСТАРЕВШЕЕ: не использовать. Telemost требует OAuth-токен пользователя.
     * Оставлено как «сигнал» — чтобы случайные вызовы сразу подсвечивались.
     */
    public function createRoom(string $title = 'MESSA call', ?int $expiresSec = null): array
    {
        throw new \RuntimeException(
            'createRoom() is deprecated. Use createConference($oauthAccessToken, ...) with a user OAuth token.'
        );
    }

    /**
     * Создать конференцию в Telemost, используя OAuth-токен пользователя/интеграционного аккаунта.
     *
     * $options поддерживает поля спецификации Telemost:
     *   - waiting_room_level: 'PUBLIC' | 'ORGANIZATION' | 'ADMINS'
     *   - cohosts: string[] (список email адресов со-организаторов)
     *   - live_stream: ['access_level' => 'PUBLIC'|'ORGANIZATION'|'UNKNOWN', 'title' => string, 'description' => string]
     *
     * Возвращает массив:
     *   ['room_id' => string, 'join_url' => string, (опц.) 'live_stream' => array]
     */
    public function createConference(
        string $oauthAccessToken,
        string $title = 'MESSA call',
        ?int $expiresSec = null,
        array $options = []
    ): array {
        if ($this->mock) {
            $rid = $this->ulid();
            $url = $this->publicBase . '/' . $rid;
            return ['room_id' => $rid, 'join_url' => $url];
        }

        // Сбор тела запроса строго по доке Telemost
        $payload = [];

        // waiting_room_level
        $wrl = strtoupper((string) ($options['waiting_room_level'] ?? 'PUBLIC'));
        if (!in_array($wrl, ['PUBLIC', 'ORGANIZATION', 'ADMINS'], true)) {
            $wrl = 'PUBLIC';
        }
        $payload['waiting_room_level'] = $wrl;

        // cohosts (массив email -> [{"email": "..."}])
        if (!empty($options['cohosts']) && is_array($options['cohosts'])) {
            $cohosts = [];
            foreach ($options['cohosts'] as $email) {
                $email = trim((string) $email);
                if ($email !== '') {
                    $cohosts[] = ['email' => $email];
                }
            }
            if ($cohosts) {
                $payload['cohosts'] = $cohosts;
            }
        }

        // live_stream
        if (!empty($options['live_stream']) && is_array($options['live_stream'])) {
            $ls        = $options['live_stream'];
            $lsPayload = [];

            if (isset($ls['access_level'])) {
                $al = strtoupper((string) $ls['access_level']);
                if (in_array($al, ['PUBLIC', 'ORGANIZATION', 'UNKNOWN'], true)) {
                    $lsPayload['access_level'] = $al;
                }
            }
            if (isset($ls['title'])) {
                $lsPayload['title'] = (string) $ls['title'];
            }
            if (isset($ls['description'])) {
                $lsPayload['description'] = (string) $ls['description'];
            }
            if ($lsPayload) {
                $payload['live_stream'] = $lsPayload;
            }
        }

        // Вызов Telemost API
        [$code, $data, $raw] = $this->httpJson(
            'POST',
            $this->createUrl,
            $payload,
            ['Authorization: OAuth ' . $oauthAccessToken]
        );

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            throw new \RuntimeException(
                'Telemost create conference error, code=' . $code . ' response_length=' . strlen($raw)
            );
        }

        $rid  = (string) ($data['id'] ?? $data['room_id'] ?? '');
        $join = (string) ($data['join_url'] ?? ($this->publicBase . '/' . $rid));

        if ($rid === '') {
            throw new \RuntimeException('Telemost response missing conference id');
        }

        $result = ['room_id' => $rid, 'join_url' => $join];
        if (isset($data['live_stream']) && is_array($data['live_stream'])) {
            $result['live_stream'] = $data['live_stream'];
        }
        return $result;
    }

    /**
     * Простой ULID-подобный идентификатор (для мок-режима).
     */
    private function ulid(): string
    {
        $time = microtime(true);
        $ms   = (int) round($time * 1000);
        $rand = bin2hex(random_bytes(8));
        return strtoupper(dechex($ms)) . substr($rand, 0, 16);
    }

    /**
     * HTTP JSON вызов с ретраями и экспоненциальной паузой.
     *
     * @return array{int,mixed,string,?string} [httpCode, jsonDecodedOrNull, rawBody, errorOrNull]
     */
    private function httpJson(string $method, string $url, array $payload, array $extraHeaders = []): array
    {
        $attempts = (int) \Messa\Core\ConfigHelper::getInt('TELEMOST_RETRY_ATTEMPTS', 3);
        $base     = (int) \Messa\Core\ConfigHelper::getInt('TELEMOST_RETRY_BASE_MS', 150);
        $traceId  = bin2hex(random_bytes(8));

        $headers = array_merge(
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'Connection: close',
                'X-Request-Id: ' . $traceId,
            ],
            $extraHeaders
        );

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        for ($i = 0; $i < $attempts; $i++) {
            [$code, $data, $raw, $err] = $this->doHttp($method, $url, $headers, $body);
            $retryable = ($code === 429 || ($code >= 500 && $code <= 599) || $err !== null);

            if (!$retryable) {
                return [$code, $data, $raw];
            }

            if ($i < $attempts - 1) {
                $sleepMs = (int) ($base * (2 ** $i) + random_int(0, 50));
                usleep($sleepMs * 1000);
                continue;
            }
            return [$code, $data, $raw, $err ?: 'Retry attempts exhausted'];
        }

        return [0, null, '', 'Retry attempts exhausted (attempts=0)'];
    }

    /**
     * Низкоуровневый HTTP-вызов (cURL).
     *
     * @return array{int,mixed,string,?string} [code, jsonOrNull, raw, errorOrNull]
     */
    private function doHttp(string $method, string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_errno($ch) ? curl_error($ch) : null;
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            Logger::error('Telemost HTTP error', ['error' => $err]);
        }

        $data = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
        return [$code, $data, (string) $raw, $err];
    }
}
