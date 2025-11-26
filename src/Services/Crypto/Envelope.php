<?php
declare(strict_types=1);
namespace Messa\Services\Crypto;

use Messa\Core\Env;
use Messa\Core\Db;
use PDO;

/**
 * Envelope crypto: per-chat DEK (32b) wrapped by KEK (from .env).
 * AES-256-GCM; nonce 12b; tag 16b. Бинарные значения в БД, в API — base64.
 */
final class Envelope
{
    private const ALG = 'aes-256-gcm';
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /* ---------- base64url helpers для JSON-переноса ---------- */
    private static function b64u(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    private static function b64uDec(string $s): string {
        $pad = strlen($s) % 4 ? 4 - (strlen($s) % 4) : 0;
        return base64_decode(strtr($s . str_repeat('=', $pad), '-_', '+/'));
    }

    // ./src/Services/Crypto/Envelope.php
    private function kek(): string
    {
        // 1) Основной вариант — KEK_B64: base64 от 32 байт
        $b64 = trim((string)Env::get('KEK_B64', ''));
        if ($b64 !== '') {
            $raw = base64_decode($b64, true);
            if (!is_string($raw) || strlen($raw) !== 32) {
                throw new \RuntimeException('Invalid KEK_B64: expected base64-encoded 32-byte key');
            }
            return $raw;
        }

        // 2) Альтернатива — KEK_HEX: 64 hex-символа
        $hex = trim((string)Env::get('KEK_HEX', ''));
        if ($hex !== '') {
            if (!ctype_xdigit($hex) || strlen($hex) !== 64) {
                throw new \RuntimeException('Invalid KEK_HEX: expected 64 hex chars (32 bytes)');
            }
            return (string)hex2bin($hex);
        }

        // 3) Разрешаем слабый fallback ТОЛЬКО в dev/test
        $env = strtolower((string)Env::get('APP_ENV', 'prod'));
        if (in_array($env, ['dev','local','development','testing'], true)) {
            $rawStr = (string)Env::get('KEK_STRING', 'dev-only-kek');
            // для dev можно спокойно хэшнуть строку
            return hash('sha256', $rawStr, true);
        }

        // 4) На проде — только явный KEK, иначе фатальная ошибка
        throw new \RuntimeException('KEK is not configured (set KEK_B64 or KEK_HEX)');
    }


    public function getDekForChat(int $chatId): string
    {
        $st = $this->pdo->prepare("SELECT dek_nonce, dek_tag, dek_ct FROM chat_keys WHERE chat_id=? LIMIT 1");
        $st->execute([$chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $this->unwrapDek($row['dek_nonce'], $row['dek_tag'], $row['dek_ct'], $chatId);
        }
        $dek = random_bytes(32);
        [$n,$t,$ct] = $this->wrapDek($dek, $chatId);
        $ins = $this->pdo->prepare("INSERT INTO chat_keys (chat_id, alg, dek_nonce, dek_tag, dek_ct) VALUES (?,?,?,?,?)");
        $ins->bindValue(1, $chatId, PDO::PARAM_INT);
        $ins->bindValue(2, 'AES-256-GCM', PDO::PARAM_STR);
        $ins->bindValue(3, $n, PDO::PARAM_STR);
        $ins->bindValue(4, $t, PDO::PARAM_STR);
        $ins->bindValue(5, $ct, PDO::PARAM_LOB);
        $ins->execute();
        return $dek;
    }

    private function wrapDek(string $dek, int $chatId): array
    {
        $nonce = random_bytes(12);
        $tag = '';
        $aad = "chatkey:$chatId:v1";
        $ct = openssl_encrypt($dek, self::ALG, $this->kek(), OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($ct === false || strlen($tag) !== 16) {
            throw new \RuntimeException('openssl_encrypt failed for DEK');
        }
        return [$nonce, $tag, $ct];
    }

    private function unwrapDek(string $nonce, string $tag, string $ct, int $chatId): string
    {
        $aad = "chatkey:$chatId:v1";
        $pt = openssl_decrypt($ct, self::ALG, $this->kek(), OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($pt === false || strlen($pt) !== 32) {
            throw new \RuntimeException('Invalid wrapped DEK');
        }
        return $pt;
    }

    public function encryptForChat(int $chatId, int $senderId, string $plaintext): array
    {
        $dek = $this->getDekForChat($chatId);
        $nonce = random_bytes(12);
        $tag = '';
        $aad = "chat:$chatId;sender:$senderId";
        $ct = openssl_encrypt($plaintext, self::ALG, $dek, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($ct === false) throw new \RuntimeException('openssl_encrypt failed');
        return [$nonce, $tag, $ct];
    }

    /**
     * Расшифровка контента сообщения в чате (AES-256-GCM), AAD=chat+sender.
     */
    public function decryptForChat(int $chatId, int $senderId, string $nonce, string $tag, string $ct): string
    {
        $dek = $this->getDekForChat($chatId);
        $aad = "chat:$chatId;sender:$senderId";
        $pt = openssl_decrypt($ct, self::ALG, $dek, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($pt === false) {
            throw new \RuntimeException('openssl_decrypt failed');
        }
        return $pt;
    }

    /* ---------- Статика для UserKeyService: envelope ключей ---------- */
    /**
     * Обёртка произвольного ключа (например, userKey 32b) KEK-ом (HEX 64 символа).
     * Возвращает массив для JSON: {alg, iv, ciphertext, tag} (base64url).
     */
    public static function wrapAesGcm(string $kekHex, string $plaintext, string $aad = 'userkey:v1'): array
    {
        $kek = @hex2bin($kekHex);
        if ($kek === false || strlen($kek) !== 32) {
            throw new \RuntimeException('Invalid KEK hex (expected 32 bytes)');
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, self::ALG, $kek, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($ct === false || strlen($tag) !== 16) {
            throw new \RuntimeException('openssl_encrypt failed (wrap)');
        }
        return [
            'alg'        => 'AES-256-GCM',
            'iv'         => self::b64u($iv),
            'ciphertext' => self::b64u($ct),
            'tag'        => self::b64u($tag),
        ];
    }

    /**
     * Распаковка ключа из {alg, iv, ciphertext, tag} KEK-ом (HEX).
     * Возвращает бинарный ключ (plaintext).
     */
    public static function unwrapAesGcm(string $kekHex, array $wrapped, string $aad = 'userkey:v1'): string
    {
        $kek = @hex2bin($kekHex);
        if ($kek === false || strlen($kek) !== 32) {
            throw new \RuntimeException('Invalid KEK hex (expected 32 bytes)');
        }
        $ivB = self::b64uDec((string)($wrapped['iv'] ?? ''));
        $ctB = self::b64uDec((string)($wrapped['ciphertext'] ?? ''));
        $tgB = self::b64uDec((string)($wrapped['tag'] ?? ''));
        if (strlen($ivB) !== 12 || strlen($tgB) !== 16 || $ctB === '') {
            throw new \RuntimeException('Invalid wrapped object');
        }
        $pt = openssl_decrypt($ctB, self::ALG, $kek, OPENSSL_RAW_DATA, $ivB, $tgB, $aad);
        if ($pt === false) {
            throw new \RuntimeException('openssl_decrypt failed (unwrap)');
        }
        return $pt;
    }
}
