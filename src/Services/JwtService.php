<?php
declare(strict_types=1);
namespace Messa\Services;

use Messa\Http\Request;
use Messa\Http\Exceptions\UnauthorizedException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Messa\Core\ConfigHelper;

class JwtService
{
    private string $secret;
    private int $accessTtl;
    private int $refreshTtl;
    private string $issuer;
    private string $audience;
    private const MAX_TOKEN_LENGTH = 8192;

    public function __construct()
    {
        $cfg = ConfigHelper::getSecurity();
        $jwtCfg = $cfg['jwt'];
        
        $this->secret = ConfigHelper::getString('JWT_SECRET', '');
        
        $this->accessTtl = $jwtCfg['access_ttl'];
        $this->refreshTtl = $jwtCfg['refresh_ttl'];
        $this->issuer = $jwtCfg['issuer'];
        $this->audience = $jwtCfg['audience'];

        if ($this->secret === '') {
            throw new \RuntimeException('JWT_SECRET not configured');
        }

        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET too short: use at least 32 bytes');
        }
    }

    public function issueAccess(array $payload): array
    {
        $now = time();
        $exp = $now + $this->accessTtl;
        
        $tokenPayload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $exp,
            'type' => 'access',
            'sub' => (string)($payload['id'] ?? ''),
            'login' => (string)($payload['login'] ?? ''),
            'role' => (string)($payload['role'] ?? 'user'),
        ];

        $token = JWT::encode($tokenPayload, $this->secret, 'HS256');
        
        return [
            'token' => $token,
            'exp' => $exp
        ];
    }

    public function verifyAccess(string $token): array
    {
        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new UnauthorizedException('Token too long');
        }

        if (empty(trim($token))) {
            throw new UnauthorizedException('Empty token');
        }
        
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            $payload = (array)$decoded;

            // Проверка обязательных claims
            $requiredClaims = ['iss', 'aud', 'iat', 'exp', 'type', 'sub'];
            foreach ($requiredClaims as $claim) {
                if (!isset($payload[$claim])) {
                    throw new UnauthorizedException("Missing required claim: $claim");
                }
            }

            // Валидация значений claims
            if ($payload['iss'] !== $this->issuer) {
                throw new UnauthorizedException('Invalid token issuer');
            }
            
            if ($payload['aud'] !== $this->audience) {
                throw new UnauthorizedException('Invalid token audience');
            }
            
            if ($payload['type'] !== 'access') {
                throw new UnauthorizedException('Invalid token type');
            }

            // Проверка expiration
            if ($payload['exp'] < time()) {
                throw new UnauthorizedException('Token expired');
            }

            return $payload;
        } catch (\Exception $e) {
            throw new UnauthorizedException('Invalid access token: ' . $e->getMessage());
        }
    }

    public function getAccessTtl(): int
    {
        return $this->accessTtl;
    }

    public function getRefreshTtl(): int
    {
        return $this->refreshTtl;
    }

    public function requireAuth(Request $req): array
    {
        // Берём заголовок Authorization
        $authHeader = trim((string)($req->header('Authorization') ?? ''));

        if ($authHeader === '') {
            throw new UnauthorizedException('Authorization header required');
        }

        // Должно начинаться с "Bearer "
        if (stripos($authHeader, 'Bearer ') !== 0) {
            throw new UnauthorizedException('Invalid Authorization header format');
        }

        // Вырезаем сам токен
        $token = trim(substr($authHeader, 7)); // "Bearer " = 7 символов

        if ($token === '') {
            throw new UnauthorizedException('Empty Bearer token');
        }

        // Дальше обычная проверка access-токена
        return $this->verifyAccess($token);
    }
}