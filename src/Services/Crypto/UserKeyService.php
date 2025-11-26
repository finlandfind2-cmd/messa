<?php
declare(strict_types=1);
namespace Messa\Services\Crypto;

use Messa\Core\Config;
use Messa\Core\Db;
use PDO;

final class UserKeyService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    public function generateAndStore(int $userId): array
    {
        // Берём только из конфигурации/окружения, без дефолта
        $kekHex = (string)Config::get('MASTER_KEY_HEX');
        $kekHex = trim($kekHex);

        if ($kekHex === '' || strlen($kekHex) !== 64 || !ctype_xdigit($kekHex)) {
            throw new \RuntimeException('MASTER_KEY_HEX not set or invalid hex length (expected 64 hex chars)');
        }

        $userKey = random_bytes(32);
        $wrapped = Envelope::wrapAesGcm($kekHex, $userKey);

        $stmt = $this->pdo->prepare(
            "UPDATE `users`
             SET `user_key_wrapped` = JSON_OBJECT('alg', ?, 'iv', ?, 'ciphertext', ?, 'tag', ?)
             WHERE id = ?"
        );
        $stmt->execute([
            $wrapped['alg'],
            $wrapped['iv'],
            $wrapped['ciphertext'],
            $wrapped['tag'],
            $userId
        ]);

        return $wrapped;
    }
}
