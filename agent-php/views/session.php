<div class="card">
  <h1><?= h($session['title']) ?> <span class="badge state-<?= h($session['state']) ?>"><?= h($session['state']) ?></span></h1>
  <p class="hint">
    <?= h($session['repo_url']) ?> &middot; branch <?= h($session['work_branch'] ?? '(not created yet)') ?> &middot;
    model <?= h($session['model']) ?> &middot; agent <?= h($session['agent']) ?> &middot; mode <?= h($session['mode']) ?>
  </p>
  <p class="hint" id="usage">tokens: <?= (int) $session['tokens_prompt'] ?> in / <?= (int) $session['tokens_completion'] ?> out</p>
  <?php if (!empty($session['pr_url'])): ?>
    <p class="pr-link">Pull request: <a href="<?= h($session['pr_url']) ?>" target="_blank" rel="noopener"><?= h($session['pr_url']) ?></a></p>
  <?php endif; ?>
  <?php if (!empty($session['error'])): ?>
    <p class="error">Error: <?= h($session['error']) ?></p>
    <button type="button" id="retry-btn">Retry</button>
  <?php endif; ?>
  <div class="row-actions">
    <button type="button" class="secondary small" id="diff-btn">View diff</button>
    <button type="button" class="secondary small" id="files-btn">Browse files</button>
    <?php if ((int) $session['archived'] === 1): ?>
      <button type="button" class="secondary small" data-action="unarchive" data-id="<?= h($session['id']) ?>">Unarchive</button>
    <?php else: ?>
      <button type="button" class="secondary small" data-action="archive" data-id="<?= h($session['id']) ?>">Archive</button>
    <?php endif; ?>
    <button type="button" class="secondary small danger" data-action="delete" data-id="<?= h($session['id']) ?>">Delete</button>
  </div>
</div>

<div id="approval-card" class="card" hidden>
  <h1>Permission requested</h1>
  <p>The agent wants to run <code id="approval-tool"></code> with:</p>
  <pre id="approval-args" class="code-block"></pre>
  <div class="row-actions">
    <button type="button" id="approve-btn">Approve</button>
    <button type="button" class="secondary" id="deny-btn">Deny</button>
  </div>
</div>

<div id="todo-card" class="card" hidden>
  <h1>Todo list</h1>
  <ul id="todo-list" class="todo-list"></ul>
</div>

<div class="card">
  <div id="transcript" class="transcript"></div>

  <?php if (!in_array($session['state'], ['done', 'error'], true)): ?>
    <form id="send-form" class="send-row">
      <input type="text" id="send-text" placeholder="Send a follow-up instruction..." autocomplete="off">
      <button type="submit">Send</button>
      <button type="button" id="finish-btn" class="secondary">Force finish &amp; open PR</button>
    </form>
  <?php endif; ?>
</div>

<div id="modal" class="modal" hidden>
  <div class="modal-content">
    <button type="button" id="modal-close" class="modal-close">&times;</button>
    <div id="modal-body"></div>
  </div>
</div>

<script>
window.AGENT_SESSION_ID = <?= json_encode($session['id']) ?>;
window.AGENT_TODOS = <?= json_encode($todos) ?>;
window.AGENT_PENDING_APPROVAL = <?= json_encode($pendingApproval) ?>;
</script>
<script src="assets/app.js"></script>
