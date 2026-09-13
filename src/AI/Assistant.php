<?php

declare(strict_types=1);

namespace AIPanel\AI;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;
use AIPanel\Domain\NodeRepository;
use AIPanel\Domain\SiteRepository;
use AIPanel\Tasks\Catalogue;
use AIPanel\Tasks\Plan;
use AIPanel\Tasks\TaskValidator;
use AIPanel\Tasks\ValidationException;

/**
 * The assistant plans; it never executes.
 *
 * Claude is given one tool per catalogue entry plus read-only inventory tools.
 * Read-only tools run immediately so the model can look at real state.
 * Mutating tool calls are captured into a Plan, validated against the
 * catalogue, and returned for the user to approve — the model's output is
 * data, never a command line.
 */
final class Assistant
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are the operations assistant inside aipanel, a hosting control panel for
        LAMP-style servers. You help the signed-in account owner manage their sites,
        databases, DNS and mail.

        How you work:
        - You may only act through the provided tools. There is no shell.
        - Read-only tools (list_sites, list_nodes) execute immediately; use them to
          check real state before proposing anything.
        - Mutating tools do not execute when you call them: they are collected into a
          plan the user must approve. Call them in the order they should run.
        - Choose the target node yourself from list_nodes, matching the task's
          required role. If no node with the required role exists, say so instead of
          guessing an id.
        - Destructive tasks (deleting a site, dropping a database, restoring a
          backup) need an explicit, unambiguous request. If the user's intent is
          vague, ask rather than planning a deletion.
        - Finish with a short plain-language summary of what the plan will do, and
          name anything the user still needs to decide.
        PROMPT;

    public function __construct(
        private Client $client,
        private NodeRepository $nodes,
        private SiteRepository $sites,
        private TaskValidator $validator,
        private string $model = 'claude-opus-5',
    ) {
    }

    /**
     * @param list<array{role:string,content:mixed}> $history
     * @return array{reply:string,plan:Plan,rejected:list<string>,history:list<array{role:string,content:mixed}>}
     */
    public function chat(string $message, int $accountId, array $history = []): array
    {
        $messages = [...$history, ['role' => 'user', 'content' => $message]];
        $plan = new Plan();
        $rejected = [];
        $reply = '';

        $response = $this->client->messages->create(
            model: $this->model,
            maxTokens: 16000,
            system: self::SYSTEM_PROMPT,
            thinking: ['type' => 'adaptive'],
            tools: $this->toolDefinitions(),
            messages: $messages,
        );

        // Bounded loop: read-only tools feed real state back, mutating tools are
        // captured. The bound keeps a confused model from looping forever.
        for ($turn = 0; $turn < 8 && $response->stopReason === 'tool_use'; $turn++) {
            $toolResults = [];

            foreach ($response->content as $block) {
                if (!$block instanceof ToolUseBlock) {
                    continue;
                }

                $input = is_array($block->input) ? $block->input : [];
                $toolResults[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $block->id,
                    'content' => $this->handleToolCall($block->name, $input, $accountId, $plan, $rejected),
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $toolResults];

            $response = $this->client->messages->create(
                model: $this->model,
                maxTokens: 16000,
                system: self::SYSTEM_PROMPT,
                thinking: ['type' => 'adaptive'],
                tools: $this->toolDefinitions(),
                messages: $messages,
            );
        }

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $reply .= $block->text;
            }
        }

        $messages[] = ['role' => 'assistant', 'content' => $response->content];

        return [
            'reply' => $reply,
            'plan' => $plan,
            'rejected' => $rejected,
            'history' => $messages,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $rejected
     */
    private function handleToolCall(string $name, array $input, int $accountId, Plan $plan, array &$rejected): string
    {
        if ($name === 'list_sites') {
            return (string) json_encode(array_map(
                static fn (array $s): array => [
                    'domain' => $s['domain'],
                    'php_version' => $s['php_version'],
                    'status' => $s['status'],
                    'node_id' => (int) $s['node_id'],
                    'node' => $s['node_hostname'],
                ],
                $this->sites->forAccount($accountId)
            ), JSON_UNESCAPED_SLASHES);
        }

        if ($name === 'list_nodes') {
            return (string) json_encode(array_map(
                static fn (array $n): array => [
                    'id' => (int) $n['id'],
                    'hostname' => $n['hostname'],
                    'role' => $n['role'],
                    'status' => $n['status'],
                ],
                $this->nodes->all()
            ), JSON_UNESCAPED_SLASHES);
        }

        $task = $this->taskNameFromTool($name);
        if ($task === null) {
            $rejected[] = "unknown tool '{$name}'";

            return 'Error: unknown tool. Use only the provided tools.';
        }

        $nodeId = isset($input['node_id']) ? (int) $input['node_id'] : 0;
        unset($input['node_id']);

        $node = $this->nodes->find($nodeId);
        if ($node === null) {
            $rejected[] = "task '{$task}' referenced unknown node {$nodeId}";

            return "Error: node {$nodeId} does not exist. Call list_nodes and pick a real node id.";
        }

        try {
            $clean = $this->validator->validate($task, $input, (string) $node['role']);
        } catch (ValidationException $e) {
            $rejected[] = "task '{$task}': " . implode('; ', $e->errors);

            return 'Error: ' . implode('; ', $e->errors);
        }

        $plan->addStep($task, $nodeId, $clean);

        return sprintf(
            'Added to the plan (step %d, not yet executed): %s on %s.',
            count($plan->steps()),
            $task,
            (string) $node['hostname']
        );
    }

    /** @return list<array<string,mixed>> */
    private function toolDefinitions(): array
    {
        $tools = [
            [
                'name' => 'list_sites',
                'description' => 'List the sites in this account, with their node, PHP version and status. Read-only; executes immediately.',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
            [
                'name' => 'list_nodes',
                'description' => 'List managed nodes with their id, hostname, role (web, mysql, dns, mail) and status. Read-only; executes immediately.',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
        ];

        foreach (Catalogue::all() as $task => $spec) {
            $properties = [
                'node_id' => [
                    'type' => 'integer',
                    'description' => "Id of the node to run this on. Must have role '{$spec['role']}'.",
                ],
            ];
            $required = ['node_id'];

            foreach ($spec['params'] as $param => $rules) {
                $schema = [
                    'type' => match ($rules['type']) {
                        'int' => 'integer',
                        'bool' => 'boolean',
                        'array' => 'array',
                        default => 'string',
                    },
                    'description' => $rules['description'],
                ];
                if ($rules['type'] === 'array') {
                    $schema['items'] = ['type' => 'string'];
                }
                if (isset($rules['enum'])) {
                    $schema['enum'] = $rules['enum'];
                }
                $properties[$param] = $schema;
                if ($rules['required']) {
                    $required[] = $param;
                }
            }

            $tools[] = [
                'name' => $this->toolNameForTask($task),
                'description' => $spec['description']
                    . ($spec['destructive'] ? ' DESTRUCTIVE: only plan this when the user asked for it unambiguously.' : '')
                    . ' Calling this adds a step to the plan for the user to approve; it does not execute.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ];
        }

        return $tools;
    }

    private function toolNameForTask(string $task): string
    {
        return str_replace('.', '_', $task);
    }

    private function taskNameFromTool(string $tool): ?string
    {
        foreach (Catalogue::names() as $task) {
            if ($this->toolNameForTask($task) === $tool) {
                return $task;
            }
        }

        return null;
    }
}
