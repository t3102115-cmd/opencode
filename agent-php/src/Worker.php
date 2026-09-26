<?php

declare(strict_types=1);

/**
 * Runs one bounded batch of agent work for a session, then returns control
 * to the caller. It never blocks forever: bin/worker.php invokes this once
 * per process and bin/dispatcher.php (via cron) re-invokes it on sessions
 * that are still "running" until the model calls finish_task or the user
 * force-finishes. That is what lets a session survive you closing the
 * browser tab or the PHP process being restarted.
 *
 * Sessions in "ask" mode pause with state = awaiting_approval whenever the
 * model wants to write a file or run a shell command, mirroring OpenCode's
 * permission prompts; the web UI resumes them once you approve or deny.
 */
final class Worker
{
    public static function runOnce(string $sessionId): void
    {
        $session = SessionRepo::find($sessionId);
        if ($session === null) {
            // Session may have been deleted from the dashboard while a
            // worker for it was queued; nothing to do.
            return;
        }

        if (in_array($session['state'], ['done', 'error', 'awaiting_approval'], true)) {
            return;
        }

        try {
            if ($session['state'] === 'pending') {
                self::log($sessionId, 'Preparing workspace...');
                self::prepareWorkspace($session);
                SessionRepo::update($sessionId, ['state' => 'running']);
                $session = SessionRepo::find($sessionId);
            }

            self::agentLoop($session);
        } catch (Throwable $e) {
            self::log($sessionId, 'ERROR: ' . $e->getMessage());
            SessionRepo::update($sessionId, ['state' => 'error', 'error' => $e->getMessage()]);
        }
    }

    private static function prepareWorkspace(array $session): void
    {
        self::assertDiskSpace();

        [$owner, $repo] = GitHubClient::parseOwnerRepo($session['repo_url']);
        $cloneUrl = GitHubClient::cloneUrlWithToken($owner, $repo);
        $workspace = $session['workspace_path'];

        if (is_dir($workspace)) {
            Tools::removeDir($workspace);
        }
        mkdir(dirname($workspace), 0755, true);

        $clone = Tools::runCommand(
            dirname($workspace),
            'git clone --depth 50 --branch ' . escapeshellarg($session['base_branch']) .
                ' ' . escapeshellarg($cloneUrl) . ' ' . escapeshellarg(basename($workspace))
        );
        self::log($session['id'], "git clone:\n$clone");

        $branch = 'agent/' . substr($session['id'], 0, 10);
        $checkout = Tools::runCommand($workspace, 'git checkout -b ' . escapeshellarg($branch));
        self::log($session['id'], "git checkout -b $branch:\n$checkout");

        Tools::runCommand($workspace, 'git config user.email "agent@localhost"');
        Tools::runCommand($workspace, 'git config user.name "PHP Agent"');

        SessionRepo::update($session['id'], ['work_branch' => $branch]);
    }

    private static function agentLoop(array $session): void
    {
        $sessionId = $session['id'];
        $maxSteps = (int) Config::get('max_steps_per_tick');
        $tools = Tools::schema($session['agent']);

        for ($step = 0; $step < $maxSteps; $step++) {
            $session = SessionRepo::find($sessionId);
            if ($session === null || in_array($session['state'], ['done', 'error', 'awaiting_approval'], true)) {
                return;
            }

            $toolCalls = SessionRepo::lastAssistantToolCalls($sessionId);
            $resuming = $toolCalls !== null && self::hasUnansweredCall($sessionId, $toolCalls);

            if (!$resuming) {
                $messages = self::buildApiMessages($session);
                $response = Zen::chat($session['model'], $messages, $tools);
                $assistant = $response['message'];
                $usage = $response['usage'];

                SessionRepo::addTokenUsage(
                    $sessionId,
                    (int) ($usage['prompt_tokens'] ?? 0),
                    (int) ($usage['completion_tokens'] ?? 0)
                );

                SessionRepo::addMessage(
                    $sessionId,
                    'assistant',
                    $assistant['content'] ?? null,
                    null,
                    null,
                    $assistant['tool_calls'] ?? null
                );

                $toolCalls = $assistant['tool_calls'] ?? [];
                if ($toolCalls === []) {
                    // Model produced a plain reply with no tool call. Nothing
                    // more to do until the user replies or force-finish kicks in.
                    return;
                }
            }

            foreach ($toolCalls as $call) {
                $callId = $call['id'] ?? '';
                if (SessionRepo::toolMessage($sessionId, $callId) !== null) {
                    continue; // Already answered in an earlier pass.
                }

                $outcome = self::executeToolCall($session, $call);
                if ($outcome === 'paused') {
                    return; // Waiting on a permission decision; stop for now.
                }
                if ($outcome === 'finished') {
                    return;
                }
            }
        }
    }

    private static function hasUnansweredCall(string $sessionId, array $toolCalls): bool
    {
        foreach ($toolCalls as $call) {
            if (SessionRepo::toolMessage($sessionId, $call['id'] ?? '') === null) {
                return true;
            }
        }

        return false;
    }

    private static function buildApiMessages(array $session): array
    {
        $systemPrompt = self::systemPrompt($session);
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach (SessionRepo::messages($session['id']) as $row) {
            if ($row['role'] === 'assistant') {
                $entry = ['role' => 'assistant', 'content' => $row['content']];
                if ($row['tool_calls_json']) {
                    $entry['tool_calls'] = json_decode((string) $row['tool_calls_json'], true);
                }
                $messages[] = $entry;
            } elseif ($row['role'] === 'tool') {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $row['tool_call_id'],
                    'content' => $row['content'],
                ];
            } elseif ($row['role'] === 'user') {
                $messages[] = ['role' => 'user', 'content' => $row['content']];
            }
            // 'log' rows are UI-only and are not sent to the model.
        }

        if ((int) $session['force_finish'] === 1) {
            $messages[] = [
                'role' => 'user',
                'content' => 'Please wrap up now: make sure your changes compile/work, then call finish_task with a summary and PR description. Do not start any new unrelated work.',
            ];
        }

        return $messages;
    }

    private static function systemPrompt(array $session): string
    {
        if ($session['agent'] === 'plan') {
            return <<<PROMPT
You are a read-only planning agent looking at a git checkout of {$session['repo_url']} (branch {$session['base_branch']}).
Task: {$session['task']}

You can only read files and list directories; you cannot edit anything or run commands. Use todo_write to keep the user
updated on your research steps. When you have an answer or a concrete plan, call finish_task with a summary and the full
plan/answer text in pr_body. Nothing you do is committed anywhere.
PROMPT;
        }

        $approvalNote = $session['mode'] === 'ask'
            ? "\nEvery write_file and run_command call requires the user's approval before it runs, so batch your reasoning and make each call count."
            : '';

        return <<<PROMPT
You are an autonomous coding agent working inside a git checkout of {$session['repo_url']} on branch {$session['work_branch']}.
Task: {$session['task']}

You have tools to read/write files, list directories, run shell commands (including git), and track a todo list inside the
checkout. Use todo_write to keep the user updated on multi-step plans. Work in small verifiable steps. Prefer running tests
or a build/lint command before finishing if the project has one. When the task is complete, commit your changes with git
yourself (run_command with git add/git commit), then call finish_task with a short summary (used as the PR title/commit
context) and a longer pr_body. Do not call finish_task until your changes are committed. If you are unsure the task is
fully done, keep working instead of finishing early.{$approvalNote}
PROMPT;
    }

    /** @return 'ok'|'paused'|'finished' */
    private static function executeToolCall(array $session, array $call): string
    {
        $sessionId = $session['id'];
        $workspace = $session['workspace_path'];
        $name = $call['function']['name'] ?? '';
        $rawArgs = $call['function']['arguments'] ?? '{}';
        $args = json_decode((string) $rawArgs, true);
        $args = is_array($args) ? $args : [];
        $callId = $call['id'] ?? '';

        if (Tools::requiresApproval($name, $session['mode'])) {
            $approval = SessionRepo::findApproval($sessionId, $callId);
            if ($approval === null) {
                SessionRepo::createApproval($sessionId, $callId, $name, $args);
                SessionRepo::update($sessionId, ['state' => 'awaiting_approval']);
                self::log($sessionId, "waiting for approval to run $name(" . json_encode($args) . ')');
                return 'paused';
            }
            if ($approval['status'] === 'denied') {
                SessionRepo::addMessage($sessionId, 'tool', 'User denied this action.', $callId, $name);
                return 'ok';
            }
            // status === 'approved': fall through and execute below.
        }

        self::log($sessionId, "tool call: $name(" . json_encode($args) . ')');

        if ($name === 'todo_write') {
            SessionRepo::replaceTodos($sessionId, is_array($args['todos'] ?? null) ? $args['todos'] : []);
            SessionRepo::addMessage($sessionId, 'tool', 'OK: todo list updated', $callId, $name);
            return 'ok';
        }

        try {
            $result = match ($name) {
                'read_file' => Tools::readFile($workspace, (string) ($args['path'] ?? '')),
                'write_file' => Tools::writeFile($workspace, (string) ($args['path'] ?? ''), (string) ($args['content'] ?? '')),
                'list_dir' => Tools::listDir($workspace, (string) ($args['path'] ?? '.')),
                'run_command' => Tools::runCommand($workspace, (string) ($args['command'] ?? '')),
                'finish_task' => null, // handled below
                default => "ERROR: unknown tool $name",
            };
        } catch (Throwable $e) {
            $result = 'ERROR: ' . $e->getMessage();
        }

        if ($name === 'finish_task') {
            SessionRepo::addMessage($sessionId, 'tool', 'Finishing task...', $callId, $name);
            self::finish($session, (string) ($args['summary'] ?? 'Automated change'), (string) ($args['pr_body'] ?? ''));
            return 'finished';
        }

        SessionRepo::addMessage($sessionId, 'tool', (string) $result, $callId, $name);

        return 'ok';
    }

    private static function finish(array $session, string $summary, string $prBody): void
    {
        $sessionId = $session['id'];
        $workspace = $session['workspace_path'];

        SessionRepo::update($sessionId, ['state' => 'finishing']);

        if ($session['agent'] === 'plan') {
            SessionRepo::update($sessionId, ['state' => 'done']);
            SessionRepo::addMessage($sessionId, 'log', "Plan finished: $summary");
            SessionRepo::addMessage($sessionId, 'assistant', $prBody !== '' ? $prBody : $summary);
            if (!Config::get('keep_workspace_after_done')) {
                Tools::removeDir($workspace);
            }
            return;
        }

        $status = Tools::runCommand($workspace, 'git status --porcelain');
        if (trim($status) !== '') {
            Tools::runCommand($workspace, 'git add -A');
            Tools::runCommand($workspace, 'git commit -m ' . escapeshellarg($summary));
        }

        $push = Tools::runCommand($workspace, 'git push -u origin ' . escapeshellarg($session['work_branch']));
        self::log($sessionId, "git push:\n$push");

        [$owner, $repo] = GitHubClient::parseOwnerRepo($session['repo_url']);
        $prUrl = GitHubClient::createPullRequest(
            $owner,
            $repo,
            $session['work_branch'],
            $session['base_branch'],
            $summary,
            $prBody !== '' ? $prBody : $summary
        );

        SessionRepo::update($sessionId, ['state' => 'done', 'pr_url' => $prUrl]);
        SessionRepo::addMessage($sessionId, 'log', "Pull request opened: $prUrl");

        if (!Config::get('keep_workspace_after_done')) {
            Tools::removeDir($workspace);
        }
    }

    private static function assertDiskSpace(): void
    {
        $free = disk_free_space(Config::dataDir());
        if ($free !== false && $free < 300 * 1024 * 1024) {
            throw new RuntimeException('Less than 300MB free disk space; refusing to clone. Clean up old workspaces first.');
        }
    }

    private static function log(string $sessionId, string $text): void
    {
        SessionRepo::addMessage($sessionId, 'log', $text);
        $logFile = Config::dataDir() . '/logs/' . $sessionId . '.log';
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] $text\n", FILE_APPEND);
    }

}
