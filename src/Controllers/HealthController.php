<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;

final class HealthController
{
    /** GET /v1/health[?full=1] */
    public function index(Request $req, Response $res): array
    {
        $okDb = \Messa\Core\Db::ping();
        $okRedis = \Messa\Core\Redis::ping();
        $okS3 = \Messa\Core\S3::isAlive();
        $metrics = [
            'time' => time(),
            'db_version' => $this->dbVersionSafe(),
            'redis' => $this->redisInfoSafe(),
            's3_bucket' => (string)\Messa\Core\Env::get('S3_BUCKET',''),
        ];
        $full = (string)($req->query('full') ?? '0') === '1';
        if ($full && $okDb) {
            try {
                $pdo = \Messa\Core\Db::pdo();
                $metrics['totals'] = [
                    'users'    => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
                    'chats'    => (int)$pdo->query("SELECT COUNT(*) FROM chats")->fetchColumn(),
                    'messages' => (int)$pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn(),
                    'calls'    => (int)$pdo->query("SELECT COUNT(*) FROM calls")->fetchColumn(),
                ];
            } catch (\Throwable $e) {
                $metrics['totals_error'] = 'unavailable';
            }
        }
        return ['status' => 'ok', 'db' => $okDb, 'redis' => $okRedis, 's3' => $okS3, 'metrics' => $metrics];
    }

     /** alias for routes expecting ::check */
    public function check(Request $req, Response $res): array
    {
        return $this->index($req, $res);
    }

    private function dbVersionSafe(): ?string {
        try {
            $pdo = \Messa\Core\DB::pdo();
            return (string)$pdo->query("SELECT VERSION()")->fetchColumn();
        } catch (\Throwable $e) { return null; }
    }
    private function redisInfoSafe(): array {
        try {
            $r = \Messa\Core\Redis::client()->info('memory');
            $c = \Messa\Core\Redis::client()->info('clients');
            return [
                'used_memory' => isset($r['used_memory']) ? (int)$r['used_memory'] : null,
                'connected_clients' => isset($c['connected_clients']) ? (int)$c['connected_clients'] : null,
            ];
        } catch (\Throwable $e) { return ['used_memory'=>null,'connected_clients'=>null]; }
    }
}
