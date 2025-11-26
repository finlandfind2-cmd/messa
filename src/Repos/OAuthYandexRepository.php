<?php
declare(strict_types=1);

namespace Messa\Repos;

use Messa\Core\Db;
use Messa\Core\Env;
use PDO;

final class OAuthYandexRepository
{
    private const ENC_PREFIX_SODIUM = 'enc:v1:sodium';
    private const ENC_PREFIX_GCM    = 'enc:v1:gcm';
    private ?string $key = null;
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function upsert(int $userId, array $tok): void
    {
        $accessPlain  = (string)($tok['access_token'] ?? '');
        $refreshPlain = isset($tok['refresh_token']) ? (string)$tok['refresh_token'] : null;
        $scope        = $tok['scope'] ?? null;
        $tokenType    = (string)($tok['token_type'] ?? 'OAuth');

        $expiresAt = $tok['expires_at'] ?? null;
        if ($expiresAt === null && isset($tok['expires_in'])) {
            $ttl = (int)$tok['expires_in'];
            if ($ttl > 0) {
                $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->add(new \DateInterval('PT' . $ttl . 'S'))
                    ->format('Y-m-d H:i:s');
            }
        }

        $accessEnc  = $this->encOrNull($accessPlain);
        $refreshEnc = $refreshPlain !== null ? $this->encOrNull($refreshPlain) : null;

        $sql = "INSERT INTO oauth_yandex_tokens
                (user_id, access_token, refresh_token, scope, token_type, expires_at, created_at, updated_at)
                VALUES (:u,:at,:rt,:sc,:tt,:ea, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  access_token = VALUES(access_token),
                  refresh_token= VALUES(refresh_token),
                  scope       = VALUES(scope),
                  token_type  = VALUES(token_type),
                  expires_at  = VALUES(expires_at),
                  updated_at  = NOW()";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':u',  $userId, PDO::PARAM_INT);
        $st->bindValue(':at', $accessEnc, PDO::PARAM_STR);
        if ($refreshEnc === null) {
            $st->bindValue(':rt', null, PDO::PARAM_NULL);
        } else {
            $st->bindValue(':rt', $refreshEnc, PDO::PARAM_STR);
        }
        if ($scope === null) {
            $st->bindValue(':sc', null, PDO::PARAM_NULL);
        } else {
            $st->bindValue(':sc', (string)$scope, PDO::PARAM_STR);
        }
        $st->bindValue(':tt', $tokenType, PDO::PARAM_STR);
        if ($expiresAt === null) {
            $st->bindValue(':ea', null, PDO::PARAM_NULL);
        } else {
            $st->bindValue(':ea', (string)$expiresAt, PDO::PARAM_STR);
        }
        $st->execute();
    }

    /** Вернуть расшифрованные токены пользователя (или null, если записи нет) */
    public function getByUser(int $userId): ?array
    {
        $st = $this->pdo->prepare("SELECT access_token, refresh_token, scope, token_type, expires_at
                               FROM oauth_yandex_tokens
                              WHERE user_id = :u
                              LIMIT 1");
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['access_token']  = $this->decOrNull($row['access_token'] ?? null) ?? '';
        $row['refresh_token'] = $this->decOrNull($row['refresh_token'] ?? null);
        // scope, token_type, expires_at оставляем как есть
        return $row;
    }

    /** Удалить запись токенов пользователя */
    public function delete(int $userId): void
    {
        $st = $this->pdo->prepare("DELETE FROM oauth_yandex_tokens WHERE user_id = :u");
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
    }

    // ====== crypto helpers ======

    private function encOrNull(?string $plaintext): ?string
    {
        if ($plaintext === null) {
            return null;
        }
        if ($plaintext === '') {
            // Пустоту храним как пустую строку без шифрования
            return '';
        }
        $key = $this->loadKey();

        // Пробуем sodium → secretbox
        if (\function_exists('sodium_crypto_secretbox')) {
            $nonce   = \random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24
            $cipher  = \sodium_crypto_secretbox($plaintext, $nonce, $key);
            $packed  = self::ENC_PREFIX_SODIUM . ':' . $this->b64e($nonce) . ':' . $this->b64e($cipher);
            return $packed;
        }

        // Fallback: OpenSSL AES-256-GCM
        if (\extension_loaded('openssl')) {
            $algo = 'aes-256-gcm';
            $ivLen = \openssl_cipher_iv_length($algo);
            if ($ivLen === false || $ivLen <= 0) {
                throw new \RuntimeException('OpenSSL GCM iv length error');
            }
            $iv   = \random_bytes($ivLen); // обычно 12
            $tag  = '';
            $cipher = \openssl_encrypt(
                $plaintext,
                $algo,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16 // tag len
            );
            if ($cipher === false) {
                throw new \RuntimeException('OpenSSL encrypt failed');
            }
            return self::ENC_PREFIX_GCM . ':' . $this->b64e($iv) . ':' . $this->b64e($tag) . ':' . $this->b64e($cipher);
        }

        throw new \RuntimeException('No crypto backend available (need sodium or openssl)');
    }

    private function decOrNull(?string $packed): ?string
    {
        if ($packed === null) {
            return null;
        }
        if ($packed === '') {
            return '';
        }

        if (\str_starts_with($packed, self::ENC_PREFIX_SODIUM . ':')) {
            $parts = \explode(':', $packed, 3);
            if (\count($parts) !== 3) {
                throw new \RuntimeException('Malformed sodium package');
            }
            [$prefix, $nonceB64, $cipherB64] = $parts;
            $nonce  = $this->b64d($nonceB64);
            $cipher = $this->b64d($cipherB64);
            $key    = $this->loadKey();

            if (!\function_exists('sodium_crypto_secretbox_open')) {
                throw new \RuntimeException('Sodium not available to decrypt');
            }
            $plain = \sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain === false) {
                throw new \RuntimeException('Decryption failed (sodium)');
            }
            return $plain;
        }

        if (\str_starts_with($packed, self::ENC_PREFIX_GCM . ':')) {
            $parts = \explode(':', $packed, 4);
            if (\count($parts) !== 4) {
                throw new \RuntimeException('Malformed gcm package');
            }
            [$prefix, $ivB64, $tagB64, $cipherB64] = $parts;
            $iv     = $this->b64d($ivB64);
            $tag    = $this->b64d($tagB64);
            $cipher = $this->b64d($cipherB64);
            $key    = $this->loadKey();

            if (!\extension_loaded('openssl')) {
                throw new \RuntimeException('OpenSSL not available to decrypt');
            }
            $plain = \openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                ''
            );
            if ($plain === false) {
                throw new \RuntimeException('Decryption failed (openssl gcm)');
            }
            return $plain;
        }

        // Старые незашифрованные записи (если вдруг остались): вернём как есть
        return $packed;
    }

    private function loadKey(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $raw = (string)Env::get('OAUTH_KMS_KEY', '');
        if ($raw === '') {
            throw new \RuntimeException('OAUTH_KMS_KEY is empty');
        }
        if (\str_starts_with($raw, 'base64:')) {
            $raw = \substr($raw, 7);
        }
        $bin = \base64_decode($raw, true);
        if ($bin === false) {
            $bin = $raw;
        }
        $this->key = \hash('sha256', $bin, true);
        return $this->key;
    }

    private function b64e(string $bin): string
    {
        return \rtrim(\strtr(\base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64d(string $url): string
    {
        $p = \strtr($url, '-_', '+/');
        $pad = \strlen($p) % 4;
        if ($pad) {
            $p .= \str_repeat('=', 4 - $pad);
        }
        $out = \base64_decode($p, true);
        if ($out === false) {
            throw new \RuntimeException('Invalid base64');
        }
        return $out;
    }
}
