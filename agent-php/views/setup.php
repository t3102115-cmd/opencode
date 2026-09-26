<div class="card narrow">
  <h1>First-time setup</h1>
  <p>Choose an admin password for this instance.</p>
  <?php if (!empty($error)): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post">
    <label>Admin password
      <input type="password" name="password" minlength="8" autofocus required>
    </label>
    <button type="submit">Save &amp; continue</button>
  </form>
  <p class="hint">You'll add your OpenCode Zen API key and GitHub token on the Settings page after logging in.</p>
</div>
