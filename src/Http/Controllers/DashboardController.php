<?php

declare(strict_types=1);

namespace AIPanel\Http\Controllers;

use AIPanel\Domain\NodeRepository;
use AIPanel\Domain\SiteRepository;
use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Http\View;
use AIPanel\Infra\Database;
use AIPanel\Jobs\JobQueue;

final class DashboardController
{
    public function __construct(
        private Database $db,
        private SiteRepository $sites,
        private NodeRepository $nodes,
        private JobQueue $queue,
        private View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');

        $data = [
            'sites' => $this->sites->forAccount($accountId),
            // Dashboards read cached node facts, never live nodes, so panel
            // latency does not grow with the size of the fleet.
            'nodes' => $this->nodes->all(),
            'jobs' => $this->queue->recentForAccount($accountId, 15),
            'promotions' => $this->db->select(
                'SELECT p.*, s.domain FROM promotions p
                 JOIN sites s ON s.id = p.site_id
                 WHERE p.account_id = ? ORDER BY p.id DESC LIMIT 10',
                [$accountId]
            ),
        ];

        if ($request->wantsJson()) {
            return Response::json($data);
        }

        return Response::html($this->view->render('dashboard', $data));
    }

    public function health(Request $request): Response
    {
        try {
            $this->db->selectOne('SELECT 1 AS ok');
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }

        return Response::json([
            'ok' => $dbOk,
            'database' => $dbOk ? 'up' : 'down',
        ], $dbOk ? 200 : 503);
    }
}
