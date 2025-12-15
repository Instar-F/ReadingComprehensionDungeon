<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Lärportal - Prototyp</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="sky-bg">
  <div class="centercontainer container py-5">
    <div class="card mx-auto" style="min-width:25vw; max-height:750px;">
      <div class="card-body">
        <h1 class="h3" style="text-align: center;">Lärportal (Prototyp)</h1>
        <p style="text-align: center;">En förenklad prototyp med flervalsövning, poäng och meny.</p>

        <?php if (is_logged_in()): ?>
          <?php $user = current_user($pdo); ?>
          <p>Hej, <strong><?php echo htmlspecialchars($user['name']); ?></strong> — Poäng: <?php echo $user['points']; ?></p>
          <p>
            <a href="menu.php" class="btn btn-primary btn-sm">Meny</a>
            <a href="exercises/multiple_choice.php" class="btn btn-success btn-sm">Öva: Flervalsfrågor</a>
            <a href="auth/signout.php" class="btn btn-outline-secondary btn-sm">Logga ut</a>
          </p>
        <?php else: ?>
          <p style="  display: flex; align-items: center; justify-content: center; gap: 10px;">
            <a href="auth/signup.php" class="btn btn-primary">Registrera</a>
            <a href="auth/signin.php" class="btn btn-primary">Logga in</a>
          </p>
        <?php endif; ?>

        <hr>
        <p class="small text-muted">Admin: <a href="admin/index.php">hantera övningar</a></p>
      </div>
    </div>
  </div>
</body>
</html>
