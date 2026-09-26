<div class="card narrow">
  <h1>Log in</h1>
  <?php if (!empty($error)): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post">
    <label>Password
      <input type="password" name="password" autofocus required>
    </label>
    <button type="submit">Log in</button>
  </form>
</div>
