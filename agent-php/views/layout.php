<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Agent</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">PHP Agent</a>
  <?php if (Auth::isLoggedIn()): ?>
    <nav>
      <a href="index.php">Sessions</a>
      <a href="index.php?page=settings">Settings</a>
      <a href="index.php?page=logout">Logout</a>
    </nav>
  <?php endif; ?>
</header>
<main class="container">
<?php require __DIR__ . '/' . $view . '.php'; ?>
</main>
</body>
</html>
