<?php
declare(strict_types=1);
namespace Messa\Controllers;

use Messa\Http\Request;
use Messa\Http\Response;
use Messa\Repos\AdminStatsRepository;
use Messa\Core\Logger;

final class AdminStatsController extends BaseController
{
    /** GET /v1/admin/stats?days=14 */
    public function stats(Request $req, Response $res): array
    {
        $this->requireAdmin($req);
        $days = (int)($req->query('days') ?? 14);
        $days = max(1, min(90, $days));
        $repo = new AdminStatsRepository();
        $out = [
            'totals' => $repo->totals(),
            'activity' => [
                'active_users' => $repo->activeUsers($days),
                'active_chats' => $repo->activeChats($days),
            ],
            'series' => [
                'messages_per_day' => $repo->messagesPerDay($days),
                'calls_per_day'    => $repo->callsPerDay($days),
            ],
        ];
        Logger::info('admin.stats', ['user_id'=>$req->getUserId(), 'days'=>$days]);
        return ['status'=>'ok'] + $out;
    }
}
