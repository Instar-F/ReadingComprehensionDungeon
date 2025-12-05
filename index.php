<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Lärportal - Prototyp</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/laro-style.css">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="card">
      <div class="card-body">
        <h1 class="h3">Lärportal (Prototyp)</h1>
        <p>En förenklad prototyp med flervalsövning, poäng och dashboard.</p>

        <?php if (is_logged_in()): ?>
          <?php $user = current_user($pdo); ?>
          <p>Hej, <strong><?php echo htmlspecialchars($user['name']); ?></strong> — Poäng: <?php echo $user['points']; ?></p>
          <p>
            <a href="dashboard.php" class="btn btn-primary btn-sm">Dashboard</a>
            <a href="exercises/multiple_choice.php" class="btn btn-success btn-sm">Öva: Flervalsfrågor</a>
            <a href="auth/signout.php" class="btn btn-outline-secondary btn-sm">Logga ut</a>
          </p>
        <?php else: ?>
          <p>
            <a href="auth/signup.php" class="btn btn-primary">Registrera</a>
            <a href="auth/signin.php" class="btn btn-outline-primary">Logga in</a>
          </p>
        <?php endif; ?>

        <hr>
        <p class="small text-muted">Admin: <a href="admin/index.php">hantera övningar</a></p>
      </div>
    </div>
  </div>
</body>
</html>
