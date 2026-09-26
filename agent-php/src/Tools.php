<?php

declare(strict_types=1);

/**
 * Tool implementations available to the model. Every path argument is
 * resolved relative to the session's workspace and constrained to stay
 * inside it; run_command executes with that directory as its cwd.
 */
final class Tools
{
    /**
     * The "plan" agent (mirroring OpenCode's plan/build agent split) only gets
     * read-only tools plus todo tracking and finish_task, so it can research
     * and propose without being able to touch the checkout.
     */
    public static function schema(string $agent = 'build'): array
    {
        $readOnly = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_file',
                    'description' => 'Read a UTF-8 text file from the repository checkout.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Path relative to the repo root.'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_dir',
                    'description' => 'List files and directories at a path in the repository checkout.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Path relative to the repo root. Use "." for the root.'],
                        ],
                        'required' => ['path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'todo_write',
                    'description' => 'Replace the task todo list shown to the user. Call this whenever your plan changes, marking items pending, in_progress, or done. Send the full list every time.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'todos' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'text' => ['type' => 'string'],
                                        'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'done']],
                                    ],
                                    'required' => ['text', 'status'],
                                ],
                            ],
                        ],
                        'required' => ['todos'],
                    ],
                ],
            ],
        ];

        $writeTools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'write_file',
                    'description' => 'Create or overwrite a text file in the repository checkout. Parent directories are created automatically.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Path relative to the repo root.'],
                            'content' => ['type' => 'string', 'description' => 'Full new contents of the file.'],
                        ],
                        'required' => ['path', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'run_command',
                    'description' => 'Run a shell command inside the repository checkout (build, test, lint, git, etc). Runs with a 120 second timeout.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'command' => ['type' => 'string', 'description' => 'Shell command to execute.'],
                        ],
                        'required' => ['command'],
                    ],
                ],
            ],
        ];

        $finish = [
            'type' => 'function',
            'function' => [
                'name' => 'finish_task',
                'description' => $agent === 'plan'
                    ? 'Call this once you have finished researching and have a plan or answer to present. This ends the session without committing anything.'
                    : 'Call this once the task is complete and changes are committed. This ends the session and opens a pull request.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Short summary, used as the commit message / PR title (build agent) or heading (plan agent).'],
                        'pr_body' => ['type' => 'string', 'description' => 'Longer description: PR body (build agent) or the full plan/answer text (plan agent).'],
                    ],
                    'required' => ['summary', 'pr_body'],
                ],
            ],
        ];

        return $agent === 'plan'
            ? array_merge($readOnly, [$finish])
            : array_merge($readOnly, $writeTools, [$finish]);
    }

    public static function requiresApproval(string $toolName, string $mode): bool
    {
        return $mode === 'ask' && in_array($toolName, ['write_file', 'run_command'], true);
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        self::runCommand(dirname($dir), 'rm -rf ' . escapeshellarg(basename($dir)));
    }

    public static function resolvePath(string $workspace, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $base = realpath($workspace);
        if ($base === false) {
            throw new RuntimeException('Workspace does not exist.');
        }

        $joined = $relative === '' || $relative === '.' ? $base : $base . '/' . ltrim($relative, '/');
        $real = realpath($joined);

        if ($real !== false) {
            if ($real !== $base && !str_starts_with($real, $base . '/')) {
                throw new RuntimeException('Path escapes the workspace.');
            }
            return $real;
        }

        // Path may not exist yet (e.g. a new file to write); validate the
        // directory portion instead so the escape check still holds.
        $dir = dirname($joined);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $realDir = realpath($dir);
        if ($realDir === false || ($realDir !== $base && !str_starts_with($realDir, $base . '/'))) {
            throw new RuntimeException('Path escapes the workspace.');
        }

        return $joined;
    }

    public static function readFile(string $workspace, string $path): string
    {
        $full = self::resolvePath($workspace, $path);
        if (!is_file($full)) {
            return "ERROR: no such file: $path";
        }
        if (filesize($full) > 2_000_000) {
            return 'ERROR: file too large to read (>2MB)';
        }

        return (string) file_get_contents($full);
    }

    public static function writeFile(string $workspace, string $path, string $content): string
    {
        $full = self::resolvePath($workspace, $path);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full, $content);

        return 'OK: wrote ' . strlen($content) . ' bytes to ' . $path;
    }

    public static function listDir(string $workspace, string $path): string
    {
        $full = self::resolvePath($workspace, $path);
        if (!is_dir($full)) {
            return "ERROR: no such directory: $path";
        }

        $entries = array_values(array_diff(scandir($full) ?: [], ['.', '..', '.git']));
        $lines = array_map(
            static fn (string $entry) => (is_dir($full . '/' . $entry) ? 'dir  ' : 'file ') . $entry,
            $entries
        );

        return implode("\n", $lines) !== '' ? implode("\n", $lines) : '(empty)';
    }

    public static function runCommand(string $workspace, string $command, int $timeoutSeconds = 120): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['timeout', (string) $timeoutSeconds, 'bash', '-lc', $command], $descriptors, $pipes, $workspace);

        if (!is_resource($process)) {
            return 'ERROR: failed to start command';
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $output = trim($stdout . ($stderr !== '' ? "\n[stderr]\n" . $stderr : ''));
        if (strlen($output) > 20_000) {
            $output = substr($output, 0, 20_000) . "\n...(truncated)";
        }

        return "exit code: $exitCode\n" . $output;
    }
}
