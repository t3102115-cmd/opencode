<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/../src/bootstrap.php';

function triggerDispatcher(): void
{
    $dispatcher = escapeshellarg(dirname(__DIR__) . '/bin/dispatcher.php');
    $php = escapeshellarg(PHP_BINARY);
    exec("$php $dispatcher > /dev/null 2>&1 &");
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../views/layout.php';
}

$page = $_GET['page'] ?? 'dashboard';

// --- Unauthenticated routes -------------------------------------------------

if ($page === 'login') {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (Auth::attemptLogin((string) ($_POST['password'] ?? ''))) {
            header('Location: index.php');
            exit;
        }
        $error = 'Wrong password.';
    }
    render('login', ['error' => $error]);
    exit;
}

if ($page === 'setup' && (string) Config::get('admin_password_hash') === '') {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            Config::save(['admin_password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            header('Location: index.php');
            exit;
        }
    }
    render('setup', ['error' => $error]);
    exit;
}

if ((string) Config::get('admin_password_hash') === '') {
    header('Location: index.php?page=setup');
    exit;
}

Auth::requireLogin();

if ($page === 'logout') {
    Auth::logout();
    header('Location: index.php?page=login');
    exit;
}

// --- JSON API (polling + actions) ------------------------------------------

if ($page === 'api') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    $id = $_GET['id'] ?? ($_POST['id'] ?? '');

    if ($action === 'poll') {
        $session = SessionRepo::find((string) $id);
        if ($session === null) {
            http_response_code(404);
            echo json_encode(['error' => 'not found']);
            exit;
        }
        $after = (int) ($_GET['after'] ?? 0);
        echo json_encode([
            'session' => $session,
            'messages' => SessionRepo::messagesSince((string) $id, $after),
            'todos' => SessionRepo::todos((string) $id),
            'pending_approval' => $session['state'] === 'awaiting_approval' ? SessionRepo::pendingApproval((string) $id) : null,
        ]);
        exit;
    }

    if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $session = SessionRepo::find((string) $id);
        $text = trim((string) ($_POST['text'] ?? ''));
        if ($session !== null && $text !== '' && !in_array($session['state'], ['done', 'error'], true)) {
            SessionRepo::addMessage((string) $id, 'user', $text);
            triggerDispatcher();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'force_finish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        SessionRepo::requestForceFinish((string) $id);
        triggerDispatcher();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'retry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $session = SessionRepo::find((string) $id);
        if ($session !== null && $session['state'] === 'error') {
            SessionRepo::update((string) $id, ['state' => 'running', 'error' => null]);
            triggerDispatcher();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if (in_array($action, ['approve', 'deny'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $approvalId = (int) ($_POST['approval_id'] ?? 0);
        $session = SessionRepo::find((string) $id);
        if ($session !== null && $session['state'] === 'awaiting_approval') {
            SessionRepo::resolveApproval($approvalId, $action === 'approve' ? 'approved' : 'denied');
            SessionRepo::update((string) $id, ['state' => 'running']);
            triggerDispatcher();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        SessionRepo::setArchived((string) $id, true);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'unarchive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        SessionRepo::setArchived((string) $id, false);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $session = SessionRepo::find((string) $id);
        if ($session !== null) {
            Tools::removeDir($session['workspace_path']);
            SessionRepo::delete((string) $id);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'browse') {
        $session = SessionRepo::find((string) $id);
        $path = (string) ($_GET['path'] ?? '.');
        if ($session === null || !is_dir($session['workspace_path'])) {
            echo json_encode(['error' => 'workspace not available']);
            exit;
        }
        try {
            echo json_encode(['listing' => Tools::listDir($session['workspace_path'], $path)]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'readfile') {
        $session = SessionRepo::find((string) $id);
        $path = (string) ($_GET['path'] ?? '');
        if ($session === null || !is_dir($session['workspace_path'])) {
            echo json_encode(['error' => 'workspace not available']);
            exit;
        }
        try {
            echo json_encode(['content' => Tools::readFile($session['workspace_path'], $path)]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'diff') {
        $session = SessionRepo::find((string) $id);
        if ($session === null || !is_dir($session['workspace_path'])) {
            echo json_encode(['diff' => '(workspace not available yet)']);
            exit;
        }
        echo json_encode(['diff' => Tools::runCommand($session['workspace_path'], 'git diff HEAD')]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}

// --- HTML pages --------------------------------------------------------------

if ($page === 'new' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $repoUrl = trim((string) ($_POST['repo_url'] ?? ''));
    $baseBranch = trim((string) ($_POST['base_branch'] ?? '')) ?: 'main';
    $model = trim((string) ($_POST['model'] ?? '')) ?: (string) Config::get('default_model');
    $agent = in_array($_POST['agent'] ?? '', ['build', 'plan'], true) ? $_POST['agent'] : 'build';
    $mode = in_array($_POST['mode'] ?? '', ['auto', 'ask'], true) ? $_POST['mode'] : 'auto';
    $task = trim((string) ($_POST['task'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? '')) ?: substr($task, 0, 60);

    if ($repoUrl === '' || $task === '') {
        render('dashboard', ['sessions' => SessionRepo::all(), 'error' => 'Repository and task are required.', 'showArchived' => false]);
        exit;
    }

    $id = SessionRepo::create($repoUrl, $baseBranch, $model, $task, $title, $agent, $mode);
    triggerDispatcher();
    header('Location: index.php?page=session&id=' . urlencode($id));
    exit;
}

if ($page === 'session') {
    $id = (string) ($_GET['id'] ?? '');
    $session = SessionRepo::find($id);
    if ($session === null) {
        header('Location: index.php');
        exit;
    }
    render('session', [
        'session' => $session,
        'messages' => SessionRepo::messages($id),
        'todos' => SessionRepo::todos($id),
        'pendingApproval' => $session['state'] === 'awaiting_approval' ? SessionRepo::pendingApproval($id) : null,
    ]);
    exit;
}

if ($page === 'settings') {
    $saved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $update = [
            'zen_api_key' => trim((string) ($_POST['zen_api_key'] ?? '')),
            'github_token' => trim((string) ($_POST['github_token'] ?? '')),
            'default_model' => trim((string) ($_POST['default_model'] ?? '')),
            'max_steps_per_tick' => max(1, (int) ($_POST['max_steps_per_tick'] ?? 8)),
            'keep_workspace_after_done' => isset($_POST['keep_workspace_after_done']),
        ];
        $newPassword = (string) ($_POST['new_password'] ?? '');
        if ($newPassword !== '') {
            $update['admin_password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        Config::save($update);
        $saved = true;
    }
    render('settings', ['saved' => $saved]);
    exit;
}

$showArchived = ($_GET['archived'] ?? '') === '1';
render('dashboard', ['sessions' => SessionRepo::all($showArchived), 'error' => null, 'showArchived' => $showArchived]);
