<?php
declare(strict_types=1);

namespace Messa\Core;

use Messa\Http\JsonResponder;
use Messa\Http\Request;
use Messa\Http\Response;

final class MicroserviceProxy
{
    /** @var array<string, mixed>|null */
    private static ?array $cachedConfig = null;

    /** @var array<string, array{enabled: bool, base_url: string}> */
    private array $services;

    /** @var array<string, list<string>> */
    private array $pathMap = [
        'auth' => ['/v1/auth', '/v1/me', '/oauth/yandex'],
        'chat' => [
            '/v1/chats',
            '/v1/messages',
            '/v1/dialogs',
            '/v1/groups',
            '/v1/users',
            '/v1/contacts',
            '/v1/calls',
            '/v1/updates',
        ],
        'media' => ['/v1/media'],
        'notification' => ['/v1/bot/telegram'],
        'health' => ['/v1/health', '/v1/ready'],
    ];

    private int $timeout;
    private int $connectTimeout;

    public function __construct()
    {
        $enabledGlobally = ConfigHelper::getBool('SERVICE_PROXY_ENABLED', false);

        $this->services = [
            'auth' => [
                'enabled' => ConfigHelper::getBool('AUTH_SERVICE_PROXY_ENABLED', $enabledGlobally),
                'base_url' => rtrim(ConfigHelper::getString('AUTH_SERVICE_URL', ''), '/'),
            ],
            'chat' => [
                'enabled' => ConfigHelper::getBool('CHAT_SERVICE_PROXY_ENABLED', $enabledGlobally),
                'base_url' => rtrim(ConfigHelper::getString('CHAT_SERVICE_URL', ''), '/'),
            ],
            'media' => [
                'enabled' => ConfigHelper::getBool('MEDIA_SERVICE_PROXY_ENABLED', $enabledGlobally),
                'base_url' => rtrim(ConfigHelper::getString('MEDIA_SERVICE_URL', ''), '/'),
            ],
            'notification' => [
                'enabled' => ConfigHelper::getBool('NOTIFICATION_SERVICE_PROXY_ENABLED', $enabledGlobally),
                'base_url' => rtrim(ConfigHelper::getString('NOTIFICATION_SERVICE_URL', ''), '/'),
            ],
            'health' => [
                'enabled' => ConfigHelper::getBool('HEALTH_SERVICE_PROXY_ENABLED', $enabledGlobally),
                'base_url' => rtrim(ConfigHelper::getString('HEALTH_SERVICE_URL', ''), '/'),
            ],
        ];

        $config = $this->loadConfig();
        if (isset($config['path_map']) && is_array($config['path_map'])) {
            $this->pathMap = $this->normalizePathMap($config['path_map']);
        }

        $this->applyServiceOverrides($config['services'] ?? []);

        $this->timeout = max(1, (int)($config['timeout'] ?? ConfigHelper::getInt('SERVICE_PROXY_TIMEOUT', 5)));
        $this->connectTimeout = max(1, (int)($config['connect_timeout'] ?? ConfigHelper::getInt('SERVICE_PROXY_CONNECT_TIMEOUT', 2)));
    }

    public function tryProxy(Request $req): ?Response
    {
        $service = $this->matchService($req->path);
        if ($service === null) {
            return null;
        }

        $config = $this->services[$service] ?? null;
        if ($config === null || !$config['enabled'] || $config['base_url'] === '') {
            return null;
        }

        return $this->forward($config['base_url'], $req, $service);
    }

    private function matchService(string $path): ?string
    {
        foreach ($this->pathMap as $service => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($path, $prefix)) {
                    return $service;
                }
            }
        }

        return null;
    }

    private function forward(string $baseUrl, Request $req, string $service): Response
    {
        $url = $baseUrl . $req->path;
        if (!empty($req->query)) {
            $url .= '?' . http_build_query($req->query);
        }

        $ch = curl_init($url);
        $headers = $this->prepareOutgoingHeaders($req);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $req->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        if ($this->shouldSendBody($req) && $req->rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req->rawBody);
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            Logger::error('Microservice proxy failed', [
                'service' => $service,
                'path' => $req->path,
                'error' => $error,
                'errno' => $errno,
            ]);

            return JsonResponder::error(new Response(), 502, 'upstream_unavailable', 'Service temporarily unavailable');
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerBlock = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        $response = new Response();
        $response->status($status > 0 ? $status : 502);

        $remoteHeaders = $this->parseHeaders($headerBlock);
        foreach ($remoteHeaders as $name => $value) {
            $key = strtolower($name);
            if (in_array($key, ['transfer-encoding', 'content-length', 'connection'], true)) {
                continue;
            }
            $response->header($name, $value);
        }

        $response->body($body);
        return $response;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyServiceOverrides(array $config): void
    {
        foreach ($config as $service => $settings) {
            if (!isset($this->services[$service]) || !is_array($settings)) {
                continue;
            }

            if (array_key_exists('enabled', $settings) && $settings['enabled'] !== null) {
                $this->services[$service]['enabled'] = (bool)$settings['enabled'];
            }

            if (isset($settings['base_url']) && $settings['base_url'] !== null) {
                $this->services[$service]['base_url'] = rtrim((string)$settings['base_url'], '/');
            }

            if (isset($settings['paths']) && is_array($settings['paths'])) {
                $this->pathMap[$service] = $this->normalizePathList($settings['paths']);
            }
        }
    }

    /**
     * @param array<string, mixed> $pathMap
     * @return array<string, list<string>>
     */
    private function normalizePathMap(array $pathMap): array
    {
        $normalized = [];
        foreach ($pathMap as $service => $paths) {
            if (!is_array($paths)) {
                continue;
            }
            $normalized[$service] = $this->normalizePathList($paths);
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $paths
     * @return list<string>
     */
    private function normalizePathList(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $path = '/' . ltrim($path, '/');
            $out[$path] = $path;
        }

        return array_values($out);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }

        $configPath = __DIR__ . '/../../config/microservices.php';
        if (!file_exists($configPath)) {
            self::$cachedConfig = [];
            return self::$cachedConfig;
        }

        $config = require $configPath;
        self::$cachedConfig = is_array($config) ? $config : [];

        return self::$cachedConfig;
    }

    private function shouldSendBody(Request $req): bool
    {
        return in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * @return list<string>
     */
    private function prepareOutgoingHeaders(Request $req): array
    {
        $headers = [];
        $lowered = [];
        foreach ($req->headers as $name => $value) {
            $canonical = $this->canonicalizeHeaderName($name);
            if ($canonical === 'Host') {
                continue;
            }
            $headers[] = $canonical . ': ' . $value;
            $lowered[strtolower($canonical)] = true;
        }

        $requestId = $this->resolveRequestId($req);
        if ($requestId !== null) {
            if (!isset($lowered['request-id'])) {
                $headers[] = 'Request-Id: ' . $requestId;
            }
            if (!isset($lowered['x-request-id'])) {
                $headers[] = 'X-Request-Id: ' . $requestId;
            }
        }

        $headers[] = 'X-Forwarded-For: ' . $req->ip();
        $headers[] = 'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? '');
        $headers[] = 'X-Forwarded-Proto: ' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');

        return $headers;
    }

    private function canonicalizeHeaderName(string $name): string
    {
        $parts = array_map('ucfirst', explode('-', str_replace('_', '-', strtolower($name))));
        return implode('-', $parts);
    }

    private function resolveRequestId(Request $req): ?string
    {
        foreach (['request-id', 'x-request-id', 'x-correlation-id'] as $header) {
            $value = $req->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $blocks = preg_split("/(?:\r\n){2}|(?:\n){2}/", trim($headerBlock));
        $lastBlock = $blocks === false ? $headerBlock : (string)array_pop($blocks);
        $lines = preg_split('/\r\n|\n|\r/', $lastBlock) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with(strtoupper($line), 'HTTP/')) {
                continue;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if ($name === '') {
                continue;
            }
            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
