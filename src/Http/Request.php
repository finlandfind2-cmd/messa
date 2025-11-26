<?php
declare(strict_types=1);
namespace Messa\Http;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Core\ConfigHelper;

final class Request
{
    /** Маршрутные параметры ({id} и пр.) */
    public array $params = [];

    /** Данные пользователя из JWT */
    public array $user = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly ?string $rawBody
    ) {}

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Нормализуем: один ведущий слэш
        $path = '/' . ltrim($path, '/');

        // Базовый префикс API (например, "/api"), задаётся в .env через APP_BASE_PATH
        $basePrefix = rtrim(\Messa\Core\ConfigHelper::getString('APP_BASE_PATH', ''), '/');

        if ($basePrefix !== '' && $basePrefix !== '/') {
            // Если путь начинается с "/api/..." — обрезаем "/api"
            if (str_starts_with($path, $basePrefix . '/')) {
                $path = substr($path, strlen($basePrefix)); // станет "/v1/..."
            } elseif ($path === $basePrefix) {
                // /api → /
                $path = '/';
            }
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $query = $_GET ?? [];
        $raw = file_get_contents('php://input');

        return new self(
            $method,
            $path,
            array_change_key_case($headers, CASE_LOWER),
            $query,
            $raw === false ? null : $raw
        );
    }

    public function jsonBody(): ?array
    {
        if ($this->rawBody === null || $this->rawBody === '') return null;
        $data = json_decode($this->rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new UnprocessableException('Invalid JSON: ' . json_last_error_msg());
        }
        return is_array($data) ? $data : null;
    }

    /** Совместимость: как в контроллерах */
    public function json(bool $assoc = true): array|object|null {
        $j = $this->jsonBody();
        if ($j === null) return null;
        return $assoc ? $j : (object)$j;
    }
    
    public function method(): string { return $this->method; }
    
    public function header(string $name): ?string {
        $k = strtolower($name);
        return $this->headers[$k] ?? null;
    }
    
    /** query(null) → весь массив; query('k', 'def') → значение/дефолт */
    public function query(?string $key = null, mixed $default = null): mixed {
        if ($key === null) return $this->query;
        return $this->query[$key] ?? $default;
    }
    
    /** Доступ к параметрам пути */
    public function param(string $name, mixed $default = null): mixed {
        return $this->params[$name] ?? $default;
    }

    public function getUserId(): int
    {
        // совместимость с AuthMiddleware: кладёт 'id', а не 'sub'
        return (int)($this->user['id'] ?? $this->user['sub'] ?? 0);
    }

    public function getUserRole(): string
    {
        return (string)($this->user['role'] ?? 'user');
    }

    public function ip(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
        // Получаем trusted proxies из конфига
        $trustedProxies = ConfigHelper::getArray('TRUSTED_PROXIES', []);
    
        // Проверяем, является ли REMOTE_ADDR доверенным прокси
        if (in_array($remoteAddr, $trustedProxies, true)) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null) {
                // Разбиваем цепочку и берём первый (реальный) IP
                $ips = array_map('trim', explode(',', $forwarded));
                $clientIp = array_shift($ips);
    
                // Валидация: простой фильтр на IPv4/IPv6
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }
        }
    
        // Fallback на REMOTE_ADDR
        return $remoteAddr;
    }
}
