<?php
declare(strict_types=1);

namespace Messa\Controllers;

use Messa\Core\Db;
use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Http\Exceptions\UnauthorizedException;
use Messa\Http\Exceptions\UnprocessableException;
use Messa\Services\SessionService;
use Messa\Support\Validators;
use PDO;

final class PasswordChangeController extends BaseController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }
    
    /** POST /v1/me/password  {current_password,new_password} */
    public function change(Request $req, Response $res): array
    {
        $uid = $this->requireAuth($req);
        $b = $req->json();
        $cur = (string)($b['current_password'] ?? '');
        $new = (string)($b['new_password'] ?? '');
        
        if ($cur === '' || !Validators::passwordStrong($new)) {
            throw new UnprocessableException('Неверные параметры (проверьте длину нового пароля ≥10)');
        }

        $st = $this->pdo->prepare("SELECT id, password_hash FROM users WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC); // ← ТЕПЕРЬ PDO КОРРЕКТНО
        
        if (!$u || !password_verify($cur, (string)$u['password_hash'])) {
            throw new UnauthorizedException('Текущий пароль неверен');
        }
        if (password_verify($new, (string)$u['password_hash'])) {
            throw new UnprocessableException('Новый пароль не должен совпадать с текущим');
        }

        $hash = $this->argon2id($new);
        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?");
            $upd->execute([$hash, $uid]);
            (new SessionService())->revokeAllByUserId($uid); // ← ТЕПЕРЬ РАБОТАЕТ
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['status' => 'ok'];
    }

    private function argon2id(string $password): string
    {
        $options = [
            'memory_cost' => 1 << 16, // 64MB
            'time_cost' => 3,
            'threads' => 1,
        ];
        
        $hash = password_hash($password, PASSWORD_ARGON2ID, $options);
        if ($hash === false) {
            throw new \RuntimeException('Argon2id not available');
        }
        
        return $hash;
    }
}