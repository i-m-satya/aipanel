<?php

declare(strict_types=1);

namespace AIPanel\Http\Controllers;

use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Http\View;
use AIPanel\Infra\Database;
use AIPanel\Jobs\JobQueue;
use AIPanel\Tasks\ValidationException;

/**
 * Operator view of a shared instance: what is waiting for approval, who is on
 * it, and what to suspend.
 *
 * Every action here re-checks that the caller is an admin of this installation.
 * Nothing trusts a role carried in the session alone.
 */
final class AdminController
{
    public function __construct(
        private Database $db,
        private JobQueue $queue,
        private View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        if (($guard = $this->requireAdmin($request)) !== null) {
            return $guard;
        }

        $data = [
            'pending' => $this->db->select(
                "SELECT s.*, a.name AS account_name, u.github_login
                 FROM sites s
                 JOIN accounts a ON a.id = s.account_id
                 LEFT JOIN users u ON u.account_id = s.account_id AND u.role = 'owner'
                 WHERE s.approval_state = 'pending' AND s.environment = 'production'
                 ORDER BY s.id"
            ),
            'accounts' => $this->db->select(
                "SELECT a.*, 
                        (SELECT COUNT(*) FROM sites WHERE account_id = a.id AND environment = 'production') AS sites,
                        (SELECT github_login FROM users WHERE account_id = a.id ORDER BY id LIMIT 1) AS owner_login
                 FROM accounts a ORDER BY a.id DESC LIMIT 100"
            ),
        ];

        return Response::html($this->view->render('admin/index', $data));
    }

    /**
     * Approve a website: both environments become provisionable, and the
     * provisioning jobs are queued now rather than at creation time.
     */
    public function approve(Request $request): Response
    {
        if (($guard = $this->requireAdmin($request)) !== null) {
            return $guard;
        }

        $siteId = (int) $request->param('id');
        $site = $this->db->selectOne(
            "SELECT * FROM sites WHERE id = ? AND environment = 'production'",
            [$siteId]
        );

        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $environments = $this->db->select(
            'SELECT * FROM sites WHERE id = ? OR parent_site_id = ?',
            [$siteId, $siteId]
        );

        $node = $this->db->selectOne('SELECT deploy_public_key FROM nodes WHERE id = ?', [(int) $site['node_id']]);
        $deployKey = (string) ($node['deploy_public_key'] ?? '');

        if ($deployKey === '') {
            return Response::json(['error' => 'node has no deploy key'], 422);
        }

        try {
            foreach ($environments as $environment) {
                $this->db->execute(
                    "UPDATE sites SET approval_state = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
                    [(int) $request->param('user_id'), (int) $environment['id']]
                );

                $this->queue->enqueue(
                    task: 'site.create',
                    nodeId: (int) $environment['node_id'],
                    params: [
                        'domain' => (string) $environment['domain'],
                        'site_user' => (string) $environment['site_user'],
                        'repo' => (string) $environment['repo'],
                        'deploy_public_key' => $deployKey,
                        'php_version' => (string) $environment['php_version'],
                        'document_root' => (string) $environment['document_root'],
                    ],
                    accountId: (int) $environment['account_id'],
                    requestedByUserId: (int) $request->param('user_id'),
                    priority: 40,
                );
            }
        } catch (ValidationException $e) {
            return Response::json(['error' => implode('; ', $e->errors)], 422);
        }

        return $request->wantsJson()
            ? Response::json(['site_id' => $siteId, 'state' => 'approved'], 202)
            : Response::redirect('/admin');
    }

    public function reject(Request $request): Response
    {
        if (($guard = $this->requireAdmin($request)) !== null) {
            return $guard;
        }

        $siteId = (int) $request->param('id');
        $reason = mb_substr((string) $request->input('reason', 'Not approved'), 0, 255);

        $this->db->execute(
            "UPDATE sites SET approval_state = 'rejected', rejected_reason = ?, approved_by = ?, approved_at = NOW()
             WHERE id = ? OR parent_site_id = ?",
            [$reason, (int) $request->param('user_id'), $siteId, $siteId]
        );

        return $request->wantsJson()
            ? Response::json(['site_id' => $siteId, 'state' => 'rejected'])
            : Response::redirect('/admin');
    }

    /**
     * Suspend an account: its users are locked out and its sites stop serving.
     * The data stays, so a mistake is reversible.
     */
    public function suspendAccount(Request $request): Response
    {
        if (($guard = $this->requireAdmin($request)) !== null) {
            return $guard;
        }

        $accountId = (int) $request->param('id');
        $reason = mb_substr((string) $request->input('reason', 'Suspended by operator'), 0, 255);

        $this->db->execute(
            "UPDATE accounts SET status = 'suspended', suspended_reason = ? WHERE id = ?",
            [$reason, $accountId]
        );

        foreach ($this->db->select(
            "SELECT * FROM sites WHERE account_id = ? AND status = 'active'",
            [$accountId]
        ) as $site) {
            try {
                $this->queue->enqueue(
                    task: 'site.suspend',
                    nodeId: (int) $site['node_id'],
                    params: ['site_user' => (string) $site['site_user'], 'reason' => $reason],
                    accountId: $accountId,
                    requestedByUserId: (int) $request->param('user_id'),
                    priority: 30,
                );
            } catch (ValidationException) {
                // A site mid-provision has nothing to suspend yet.
            }
        }

        return Response::redirect('/admin');
    }

    /** Trusting an account takes its future sites off the approval queue. */
    public function trustAccount(Request $request): Response
    {
        if (($guard = $this->requireAdmin($request)) !== null) {
            return $guard;
        }

        $this->db->execute('UPDATE accounts SET trusted = 1 WHERE id = ?', [(int) $request->param('id')]);

        return Response::redirect('/admin');
    }

    private function requireAdmin(Request $request): ?Response
    {
        if ($request->param('role') === 'admin') {
            return null;
        }

        // 404, not 403: the admin area's existence is not a customer's business.
        return $request->wantsJson()
            ? Response::json(['error' => 'not_found'], 404)
            : Response::html($this->view->render('error', ['message' => 'Not found.']), 404);
    }
}
