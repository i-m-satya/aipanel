<?php

declare(strict_types=1);

namespace AIPanel\AI;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;
use AIPanel\Git\GitHubApp;

/**
 * The AI author.
 *
 * Given a change request for one site, this clones that site's repository into
 * an ephemeral sandbox, lets Claude read and edit the working tree, runs the
 * repo's own checks, and opens a pull request. It deliberately has:
 *
 *   - no access to any other site's repository (the installation token is
 *     minted for one repo, by the caller);
 *   - no path to a web node — deploys happen only via GitHub's webhook, so the
 *     AI cannot ship anything that did not land on the deploy branch;
 *   - no shell tool. File reads/writes go through a sandbox-rooted API, and the
 *     only command that ever runs is the repo's own check command.
 *
 * The sandbox is expected to be a throwaway container on a build node. The
 * path containment below is the second line of defence, not the first.
 */
final class CodeAgent
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are the engineer for a single website hosted on aipanel. You are the only
        author of this codebase: there is no manual editing on the server, and whatever
        lands on the deploy branch goes live.

        Work like a careful contributor to someone else's production site:
        - Read before you write. Use list_files and read_file to understand the
          existing structure, conventions and framework before changing anything.
        - Make the smallest change that fully does what was asked. Do not
          reformat, restructure, upgrade dependencies, or "improve" code you were
          not asked to touch.
        - Match the surrounding code: its naming, its idioms, its comment density.
        - Never write secrets into the repo. Runtime configuration lives in the
          site's shared/.env on the server, which you cannot see and must not
          recreate.
        - Never touch deploy plumbing, .github workflows, or anything that would
          change how the site is built, unless that is explicitly the request.
        - When the request is ambiguous in a way that changes what you would
          write, say so in your summary instead of guessing.

        Call run_checks when you believe the change is complete. If checks fail,
        read the output and fix the cause; do not weaken or delete tests to get
        green. Finish with a commit message and a short summary of what changed
        and why.
        PROMPT;

    public function __construct(
        private Client $client,
        private GitHubApp $github,
        private Sandbox $sandbox,
        private string $model = 'claude-opus-5',
    ) {
    }

    /**
     * @return array{
     *   ok:bool, summary:string, commit_message:string, files_changed:list<string>,
     *   checks:array{ran:bool,passed:bool,output:string}, turns:int
     * }
     */
    public function applyChangeRequest(string $instruction, string $requestedByEmail): array
    {
        $messages = [[
            'role' => 'user',
            'content' => "Change request for this site:\n\n{$instruction}",
        ]];

        $checks = ['ran' => false, 'passed' => false, 'output' => ''];
        $summary = '';
        $turns = 0;

        $response = $this->send($messages);

        // Bounded agentic loop. The bound is a cost and blast-radius control:
        // a request that cannot be finished in this many turns is escalated to
        // a human rather than left looping.
        for (; $turns < 40 && $response->stopReason === 'tool_use'; $turns++) {
            $toolResults = [];

            foreach ($response->content as $block) {
                if (!$block instanceof ToolUseBlock) {
                    continue;
                }

                $input = is_array($block->input) ? $block->input : [];
                $toolResults[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $block->id,
                    'content' => $this->dispatch($block->name, $input, $checks),
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $toolResults];

            $response = $this->send($messages);
        }

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $summary .= $block->text;
            }
        }

        $changed = $this->sandbox->changedFiles();

        return [
            'ok' => $changed !== [] && (!$checks['ran'] || $checks['passed']),
            'summary' => trim($summary),
            'commit_message' => $this->commitMessage($instruction, $summary, $requestedByEmail),
            'files_changed' => $changed,
            'checks' => $checks,
            'turns' => $turns,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array{ran:bool,passed:bool,output:string} $checks
     */
    private function dispatch(string $tool, array $input, array &$checks): string
    {
        try {
            return match ($tool) {
                'list_files' => $this->sandbox->listFiles((string) ($input['path'] ?? '.')),
                'read_file' => $this->sandbox->readFile((string) $input['path']),
                'write_file' => $this->sandbox->writeFile((string) $input['path'], (string) $input['content']),
                'delete_file' => $this->sandbox->deleteFile((string) $input['path']),
                'search' => $this->sandbox->search((string) $input['pattern']),
                'run_checks' => $this->runChecks($checks),
                default => "Error: unknown tool '{$tool}'.",
            };
        } catch (\RuntimeException $e) {
            // Tool errors go back to the model as text: a rejected path or a
            // missing file is something it should recover from, not a crash.
            return 'Error: ' . $e->getMessage();
        }
    }

    /** @param array{ran:bool,passed:bool,output:string} $checks */
    private function runChecks(array &$checks): string
    {
        $result = $this->sandbox->runChecks();
        $checks = ['ran' => true, 'passed' => $result['passed'], 'output' => $result['output']];

        return ($result['passed'] ? "Checks passed.\n" : "Checks FAILED.\n") . $result['output'];
    }

    /** @param list<array{role:string,content:mixed}> $messages */
    private function send(array $messages): object
    {
        return $this->client->messages->create(
            model: $this->model,
            maxTokens: 32000,
            system: self::SYSTEM_PROMPT,
            thinking: ['type' => 'adaptive'],
            outputConfig: ['effort' => 'high'],
            tools: $this->tools(),
            messages: $messages,
        );
    }

    private function commitMessage(string $instruction, string $summary, string $requestedBy): string
    {
        $subject = trim(explode("\n", trim($instruction))[0]);
        if (mb_strlen($subject) > 68) {
            $subject = mb_substr($subject, 0, 65) . '...';
        }

        $body = trim(strip_tags($summary));
        if (mb_strlen($body) > 1200) {
            $body = mb_substr($body, 0, 1200) . "\n[…]";
        }

        // Trailers make every production line traceable to a request and a
        // requester, which is the audit story for "the AI wrote this".
        return "{$subject}\n\n{$body}\n\nRequested-by: {$requestedBy}\nAuthored-by: aipanel code agent\n";
    }

    /** @return list<array<string,mixed>> */
    private function tools(): array
    {
        return [
            [
                'name' => 'list_files',
                'description' => 'List files and directories at a path inside the repository. Start here to learn the layout.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Repository-relative directory. Use "." for the root.']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'read_file',
                'description' => 'Read a file from the repository.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Repository-relative file path.']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'write_file',
                'description' => 'Create or overwrite a file with complete content. Always read a file before overwriting it.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Repository-relative file path.'],
                        'content' => ['type' => 'string', 'description' => 'The complete new file content.'],
                    ],
                    'required' => ['path', 'content'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'delete_file',
                'description' => 'Delete a file from the repository.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Repository-relative file path.']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'search',
                'description' => 'Search the repository for a fixed string or regular expression, returning matching files and lines.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['pattern' => ['type' => 'string', 'description' => 'Pattern to search for.']],
                    'required' => ['pattern'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'run_checks',
                'description' => "Run this repository's own tests and linters. Call this when the change is complete; a pull request is only opened if it passes.",
                'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false],
                'strict' => true,
            ],
        ];
    }
}
