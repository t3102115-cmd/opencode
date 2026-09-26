<?php $freeModels = [
    'big-pickle' => 'Big Pickle (free)',
    'space-bunny-free' => 'Space Bunny (free)',
    'mimo-v2.6-flash-free' => 'MiMo V2.6 Flash (free)',
    'mimo-v2.5-free' => 'MiMo V2.5 (free)',
    'ling-3.0-flash-fin-free' => 'Ling 3.0 Flash Fin (free)',
    'nemotron-3-ultra-free' => 'Nemotron 3 Ultra (free)',
    'nemotron-3.5-lightning-free' => 'Nemotron 3.5 Lightning (free)',
]; ?>
<div class="card">
  <h1>New task</h1>
  <?php if (!empty($error)): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" action="index.php?page=new">
    <label>GitHub repo
      <input type="text" name="repo_url" placeholder="owner/repo or https://github.com/owner/repo" required>
    </label>
    <label>Base branch
      <input type="text" name="base_branch" placeholder="main" value="main">
    </label>
    <label>Model
      <select name="model">
        <?php foreach ($freeModels as $id => $label): ?>
          <option value="<?= h($id) ?>" <?= $id === Config::get('default_model') ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Agent
      <select name="agent">
        <option value="build">build &mdash; edits files, runs commands, opens a PR</option>
        <option value="plan">plan &mdash; read-only research, no edits, no PR</option>
      </select>
    </label>
    <label>Permission mode
      <select name="mode">
        <option value="auto">auto &mdash; run everything without asking</option>
        <option value="ask">ask &mdash; approve every file edit / command first</option>
      </select>
    </label>
    <label>Title (optional)
      <input type="text" name="title" placeholder="Short label for this task">
    </label>
    <label>Task
      <textarea name="task" rows="4" required placeholder="Describe what you want done in this repo..."></textarea>
    </label>
    <button type="submit">Start</button>
  </form>
</div>

<div class="card">
  <h1>
    Sessions
    <a class="toggle-link" href="index.php?archived=<?= $showArchived ? '0' : '1' ?>">
      <?= $showArchived ? 'Hide archived' : 'Show archived' ?>
    </a>
  </h1>
  <?php if ($sessions === []): ?>
    <p class="hint">No sessions yet.</p>
  <?php endif; ?>
  <ul class="session-list">
    <?php foreach ($sessions as $s): ?>
      <li>
        <a href="index.php?page=session&id=<?= h($s['id']) ?>">
          <span class="badge state-<?= h($s['state']) ?>"><?= h($s['state']) ?></span>
          <span class="badge agent-<?= h($s['agent']) ?>"><?= h($s['agent']) ?></span>
          <strong><?= h($s['title']) ?></strong>
          <span class="hint"><?= h($s['repo_url']) ?> &middot; <?= (int) $s['tokens_prompt'] + (int) $s['tokens_completion'] ?> tokens</span>
        </a>
        <div class="row-actions">
          <?php if ((int) $s['archived'] === 1): ?>
            <button type="button" class="secondary small" data-action="unarchive" data-id="<?= h($s['id']) ?>">Unarchive</button>
          <?php else: ?>
            <button type="button" class="secondary small" data-action="archive" data-id="<?= h($s['id']) ?>">Archive</button>
          <?php endif; ?>
          <button type="button" class="secondary small danger" data-action="delete" data-id="<?= h($s['id']) ?>">Delete</button>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<script src="assets/dashboard.js"></script>
