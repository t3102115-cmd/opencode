<?php

declare(strict_types=1);

final class SessionRepo
{
    public static function create(
        string $repoUrl,
        string $baseBranch,
        string $model,
        string $task,
        string $title,
        string $agent = 'build',
        string $mode = 'auto'
    ): string {
        $id = bin2hex(random_bytes(8));
        $now = time();
        $workspace = Config::dataDir() . '/workspaces/' . $id;

        $stmt = Database::get()->prepare(
            'INSERT INTO sessions (id, title, repo_url, base_branch, model, agent, mode, task, state, workspace_path, created_at, updated_at)
             VALUES (:id, :title, :repo_url, :base_branch, :model, :agent, :mode, :task, :state, :workspace_path, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id,
            'title' => $title,
            'repo_url' => $repoUrl,
            'base_branch' => $baseBranch,
            'model' => $model,
            'agent' => $agent,
            'mode' => $mode,
            'task' => $task,
            'state' => 'pending',
            'workspace_path' => $workspace,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::addMessage($id, 'user', $task);

        return $id;
    }

    public static function find(string $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM sessions';
        if (!$includeArchived) {
            $sql .= ' WHERE archived = 0';
        }
        $sql .= ' ORDER BY created_at DESC';

        return Database::get()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function update(string $id, array $fields): void
    {
        $fields['updated_at'] = time();
        $sets = implode(', ', array_map(static fn (string $key) => "$key = :$key", array_keys($fields)));
        $fields['id'] = $id;
        $stmt = Database::get()->prepare("UPDATE sessions SET $sets WHERE id = :id");
        $stmt->execute($fields);
    }

    public static function addTokenUsage(string $id, int $promptTokens, int $completionTokens): void
    {
        $stmt = Database::get()->prepare(
            'UPDATE sessions SET tokens_prompt = tokens_prompt + :p, tokens_completion = tokens_completion + :c, updated_at = :t WHERE id = :id'
        );
        $stmt->execute(['p' => $promptTokens, 'c' => $completionTokens, 't' => time(), 'id' => $id]);
    }

    public static function requestForceFinish(string $id): void
    {
        self::update($id, ['force_finish' => 1]);
    }

    public static function setArchived(string $id, bool $archived): void
    {
        self::update($id, ['archived' => $archived ? 1 : 0]);
    }

    public static function delete(string $id): void
    {
        $db = Database::get();
        foreach (['messages', 'todos', 'approvals'] as $table) {
            $stmt = $db->prepare("DELETE FROM $table WHERE session_id = :id");
            $stmt->execute(['id' => $id]);
        }
        $stmt = $db->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function addMessage(
        string $sessionId,
        string $role,
        ?string $content,
        ?string $toolCallId = null,
        ?string $toolName = null,
        ?array $toolCalls = null
    ): void {
        $stmt = Database::get()->prepare(
            'INSERT INTO messages (session_id, role, content, tool_call_id, tool_name, tool_calls_json, created_at)
             VALUES (:session_id, :role, :content, :tool_call_id, :tool_name, :tool_calls_json, :created_at)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'role' => $role,
            'content' => $content,
            'tool_call_id' => $toolCallId,
            'tool_name' => $toolName,
            'tool_calls_json' => $toolCalls === null ? null : json_encode($toolCalls),
            'created_at' => time(),
        ]);
    }

    public static function messages(string $sessionId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM messages WHERE session_id = :id ORDER BY id ASC');
        $stmt->execute(['id' => $sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function messagesSince(string $sessionId, int $afterId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM messages WHERE session_id = :id AND id > :after ORDER BY id ASC');
        $stmt->execute(['id' => $sessionId, 'after' => $afterId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function toolMessage(string $sessionId, string $toolCallId): ?array
    {
        $stmt = Database::get()->prepare(
            "SELECT * FROM messages WHERE session_id = :id AND role = 'tool' AND tool_call_id = :call"
        );
        $stmt->execute(['id' => $sessionId, 'call' => $toolCallId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function lastAssistantToolCalls(string $sessionId): ?array
    {
        $stmt = Database::get()->prepare(
            "SELECT * FROM messages WHERE session_id = :id AND role = 'assistant' AND tool_calls_json IS NOT NULL ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $calls = json_decode((string) $row['tool_calls_json'], true);

        return is_array($calls) ? $calls : null;
    }

    // --- Todos ---------------------------------------------------------

    public static function replaceTodos(string $sessionId, array $todos): void
    {
        $db = Database::get();
        $db->prepare('DELETE FROM todos WHERE session_id = :id')->execute(['id' => $sessionId]);
        $stmt = $db->prepare(
            'INSERT INTO todos (session_id, text, status, position) VALUES (:session_id, :text, :status, :position)'
        );
        foreach (array_values($todos) as $i => $todo) {
            $stmt->execute([
                'session_id' => $sessionId,
                'text' => (string) ($todo['text'] ?? ''),
                'status' => (string) ($todo['status'] ?? 'pending'),
                'position' => $i,
            ]);
        }
    }

    public static function todos(string $sessionId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM todos WHERE session_id = :id ORDER BY position ASC');
        $stmt->execute(['id' => $sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- Approvals ------------------------------------------------------

    public static function createApproval(string $sessionId, string $toolCallId, string $toolName, array $arguments): void
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO approvals (session_id, tool_call_id, tool_name, arguments_json, status, created_at)
             VALUES (:session_id, :tool_call_id, :tool_name, :arguments_json, :status, :created_at)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'tool_call_id' => $toolCallId,
            'tool_name' => $toolName,
            'arguments_json' => json_encode($arguments),
            'status' => 'pending',
            'created_at' => time(),
        ]);
    }

    public static function findApproval(string $sessionId, string $toolCallId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM approvals WHERE session_id = :id AND tool_call_id = :call ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['id' => $sessionId, 'call' => $toolCallId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function pendingApproval(string $sessionId): ?array
    {
        $stmt = Database::get()->prepare(
            "SELECT * FROM approvals WHERE session_id = :id AND status = 'pending' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function resolveApproval(int $approvalId, string $status): void
    {
        $stmt = Database::get()->prepare('UPDATE approvals SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $approvalId]);
    }
}
