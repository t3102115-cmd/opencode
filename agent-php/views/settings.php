<div class="card narrow">
  <h1>Settings</h1>
  <?php if (!empty($saved)): ?><p class="notice">Saved.</p><?php endif; ?>
  <form method="post">
    <label>OpenCode Zen API key
      <input type="password" name="zen_api_key" value="<?= h(Config::get('zen_api_key')) ?>" placeholder="sk-...">
    </label>
    <p class="hint">Get one at <a href="https://opencode.ai" target="_blank" rel="noopener">opencode.ai</a> (free models still need an account/API key, they just cost $0 per token).</p>

    <label>GitHub personal access token
      <input type="password" name="github_token" value="<?= h(Config::get('github_token')) ?>" placeholder="ghp_...">
    </label>
    <p class="hint">Needs <code>repo</code> scope (classic) or Contents + Pull requests read/write (fine-grained), for the repos you want the agent to work on.</p>

    <label>Default model id
      <input type="text" name="default_model" value="<?= h(Config::get('default_model')) ?>">
    </label>

    <label>Agent turns per dispatcher tick
      <input type="text" name="max_steps_per_tick" value="<?= h((string) Config::get('max_steps_per_tick')) ?>">
    </label>
    <p class="hint">How many model/tool round-trips a single worker process does before exiting and letting the next cron tick continue. Lower is safer on a small VPS; higher finishes sessions faster.</p>

    <label class="checkbox">
      <input type="checkbox" name="keep_workspace_after_done" <?= Config::get('keep_workspace_after_done') ? 'checked' : '' ?>>
      Keep repo checkout on disk after a session finishes (uses more of your 5GB)
    </label>

    <label>Change admin password (leave blank to keep current)
      <input type="password" name="new_password" minlength="8">
    </label>

    <button type="submit">Save</button>
  </form>
</div>
