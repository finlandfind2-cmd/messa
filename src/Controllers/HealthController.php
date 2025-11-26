<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Core\ConfigHelper;
use Messa\Http\Request;
use Messa\Http\Response;

final class HealthController
{
    private bool $checkDb;
    private bool $checkRedis;
    private bool $checkS3;
    private bool $checkTotals;
    private bool $failOpen;

    public function __construct()
    {
        $this->checkDb = ConfigHelper::getBool('HEALTH_CHECK_DB', true);
        $this->checkRedis = ConfigHelper::getBool('HEALTH_CHECK_REDIS', true);
        $this->checkS3 = ConfigHelper::getBool('HEALTH_CHECK_S3', true);
        $this->checkTotals = ConfigHelper::getBool('HEALTH_CHECK_TOTALS', true);
        $this->failOpen = ConfigHelper::getBool('HEALTH_CHECK_FAIL_OPEN', false);
    }

    /** GET /v1/health[?full=1] */
    public function index(Request $req, Response $res): array
    {
        $skipped = [];

        $okDb = $this->checkDb ? \Messa\Core\Db::ping() : true;
        if (!$this->checkDb) {
            $skipped[] = 'db';
        }

        $okRedis = $this->checkRedis ? \Messa\Core\Redis::ping() : true;
        if (!$this->checkRedis) {
            $skipped[] = 'redis';
        }

        $okS3 = $this->checkS3 ? \Messa\Core\S3::isAlive() : true;
        if (!$this->checkS3) {
            $skipped[] = 's3';
        }

        $metrics = [
            'time' => time(),
            'db_version' => $this->checkDb ? $this->dbVersionSafe() : null,
            'redis' => $this->checkRedis ? $this->redisInfoSafe() : ['used_memory' => null, 'connected_clients' => null],
            's3_bucket' => (string)\Messa\Core\Env::get('S3_BUCKET',''),
            'skipped_checks' => $skipped,
            'fail_open' => $this->failOpen,
        ];
        $full = (string)($req->query('full') ?? '0') === '1';
        if ($full && $okDb && $this->checkTotals) {
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
        } elseif (!$this->checkTotals) {
            $metrics['totals_skipped'] = true;
        }

        $allOk = $okDb && $okRedis && $okS3;
        $status = $allOk ? 'ok' : ($this->failOpen ? 'degraded' : 'fail');

        if (!$allOk && $this->failOpen) {
            $metrics['degraded_due_to'] = array_values(array_diff(['db', 'redis', 's3'], $skipped, $this->successfulChecks($okDb, $okRedis, $okS3)));
        }

        return ['status' => $status, 'db' => $okDb, 'redis' => $okRedis, 's3' => $okS3, 'metrics' => $metrics];
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

    /**
     * @return list<string>
     */
    private function successfulChecks(bool $okDb, bool $okRedis, bool $okS3): array
    {
        $ok = [];
        if ($okDb) {
            $ok[] = 'db';
        }
        if ($okRedis) {
            $ok[] = 'redis';
        }
        if ($okS3) {
            $ok[] = 's3';
        }

        return $ok;
    }
}
