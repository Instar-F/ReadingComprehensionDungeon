<?php
require_once __DIR__ . '/config.php';
if (!is_logged_in()) {
    header('Location: auth/login.php'); exit;
}
$user = current_user($pdo);



?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Meny</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="dungeon-bg">
  <header style="position:relative;">
    <div class="hero-top">
      <div class="hero-avatar" title="Din hjälte"><?php echo strtoupper(substr($user['name'] ?? $user['email'],0,1)); ?></div>
      <div class="hero-pill">XP <?php echo (int)$user['points']; ?> • Lv <?php echo (int)$user['level']; ?></div>
    </div>
  </header>

  <main class="dirt-bg">
    <div class="game-stage">
      <div class="arcade-shell">
        <h2 class="text-center mb-2 gametext">ÄVENTYRET VÄNTAR!</h2>
        <p class="text-center small">Gå genom uppgifter och tjäna XP!</p>

        <a href="exercises/entrance.php" class="enter-btn" role="button" aria-label="Enter - Starta övningar">
          <div class="big">ENTER</div>
          <div class="small">Starta spelandet & tjäna XP</div>
        </a>

        <div class="secondary-buttons">
          <a href="profile.php" class="secondary-btn" role="button">Profile</a>
          <a href="auth/signout.php" class="secondary-btn" role="button">Logga ut</a>
        </div>
      </div>
    </div>
  </main>

  <script>
    // keyboard activation for enter and secondary buttons
    document.querySelectorAll('.enter-btn, .secondary').forEach(function(el){
      el.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); el.click(); } });
    });
  </script>
</body>
</html>
