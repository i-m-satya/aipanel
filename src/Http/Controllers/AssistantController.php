<?php

declare(strict_types=1);

namespace AIPanel\Http\Controllers;

use AIPanel\AI\Assistant;
use AIPanel\Domain\SiteRepository;
use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Http\View;
use AIPanel\Infra\Database;
use AIPanel\Jobs\JobQueue;
use AIPanel\Tasks\ValidationException;

/**
 * Two AI entry points:
 *   - the ops assistant, which proposes infrastructure plans for approval;
 *   - code change requests, which the code agent turns into a pull request.
 *
 * Neither can execute anything directly.
 */
final class AssistantController
{
    public function __construct(
        private Assistant $assistant,
        private SiteRepository $sites,
        private JobQueue $queue,
        private Database $db,
        private View $view,
    ) {
    }

    public function page(Request $request): Response
    {
        return Response::html($this->view->render('assistant/index', [
            'pending' => $this->db->select(
                "SELECT * FROM plans WHERE account_id = ? AND state = 'pending' ORDER BY id DESC LIMIT 5",
                [(int) $request->param('account_id')]
            ),
        ]));
    }

    /** Chat with the ops assistant. The response is a plan, not an action. */
    public function chat(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');
        $userId = (int) $request->param('user_id');
        $message = trim((string) ($request->json()['message'] ?? $request->input('message', '')));

        if ($message === '') {
            return Response::json(['error' => 'empty_message'], 422);
        }

        $result = $this->assistant->chat($message, $accountId);
        $plan = $result['plan'];

        $planId = null;
        if (!$plan->isEmpty()) {
            $planId = $this->storePlan($plan, $accountId, $userId);
        }

        return Response::json([
            'reply' => $result['reply'],
            'plan_id' => $planId,
            'plan' => $plan,
            'rejected' => $result['rejected'],
        ]);
    }

    /**
     * Approving a plan is the only way its steps reach the queue, and
     * authorization is re-checked here against the signed-in user's account —
     * never against anything the model said.
     */
    public function approvePlan(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');
        $planId = (string) $request->param('plan_id');

        $plan = $this->db->selectOne(
            "SELECT * FROM plans WHERE id = ? AND account_id = ? AND state = 'pending'",
            [$planId, $accountId]
        );
        if ($plan === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $steps = json_decode((string) $plan['steps'], true);
        $steps = is_array($steps) ? $steps : [];

        $jobIds = [];
        try {
            foreach ($steps as $step) {
                $jobIds[] = $this->queue->enqueue(
                    task: (string) $step['task'],
                    nodeId: (int) $step['node_id'],
                    params: is_array($step['params']) ? $step['params'] : [],
                    accountId: $accountId,
                    requestedByUserId: (int) $request->param('user_id'),
                    planId: $planId,
                );
            }
        } catch (ValidationException $e) {
            return Response::json(['error' => 'plan_invalid', 'detail' => $e->errors], 422);
        }

        $this->db->execute(
            "UPDATE plans SET state = 'approved', decided_at = NOW() WHERE id = ?",
            [$planId]
        );

        return Response::json(['plan_id' => $planId, 'jobs' => $jobIds], 202);
    }

    public function rejectPlan(Request $request): Response
    {
        $this->db->execute(
            "UPDATE plans SET state = 'rejected', decided_at = NOW()
             WHERE id = ? AND account_id = ? AND state = 'pending'",
            [(string) $request->param('plan_id'), (int) $request->param('account_id')]
        );

        return Response::json(['plan_id' => $request->param('plan_id'), 'state' => 'rejected']);
    }

    /**
     * Queue a code change request for one site. A worker picks it up, the code
     * agent edits the repo in a sandbox and opens a PR; merging it to main is
     * what deploys.
     */
    public function requestChange(Request $request): Response
    {
        $accountId = (int) $request->param('account_id');
        $site = $this->sites->findForAccount((int) $request->param('id'), $accountId);
        if ($site === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $instruction = trim((string) ($request->json()['instruction'] ?? $request->input('instruction', '')));
        if ($instruction === '') {
            return Response::json(['error' => 'empty_instruction'], 422);
        }
        if (mb_strlen($instruction) > 8000) {
            return Response::json(['error' => 'instruction_too_long'], 422);
        }

        $this->db->execute(
            "INSERT INTO change_requests (site_id, account_id, requested_by, instruction, state, created_at)
             VALUES (?, ?, ?, ?, 'queued', NOW())",
            [(int) $site['id'], $accountId, (int) $request->param('user_id'), $instruction]
        );

        return Response::json([
            'change_request_id' => $this->db->lastInsertId(),
            'state' => 'queued',
        ], 202);
    }

    private function storePlan(\AIPanel\Tasks\Plan $plan, int $accountId, int $userId): string
    {
        $planId = sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );

        $this->db->execute(
            "INSERT INTO plans (id, account_id, created_by, summary, steps, state, created_at)
             VALUES (?, ?, ?, ?, ?, 'pending', NOW())",
            [
                $planId,
                $accountId,
                $userId,
                $plan->summary,
                json_encode($plan->steps(), JSON_UNESCAPED_SLASHES),
            ]
        );

        return $planId;
    }
}
