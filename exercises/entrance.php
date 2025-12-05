<?php
require_once __DIR__ . '/../config.php';
if (!is_logged_in()) { header('Location: ../auth/signin.php'); exit; }

$user = current_user($pdo);

// Fetch exercises and passage (if exists)
$sql = "
    SELECT e.id, e.title, e.difficulty, e.min_level, e.created_at,
           ep.content AS passage
    FROM exercises e
    LEFT JOIN exercise_passages ep ON ep.exercise_id = e.id
    ORDER BY FIELD(e.difficulty,'easy','medium','hard'), e.created_at DESC
";
$rows = $pdo->query($sql)->fetchAll();

function difficulty_rank($d) {
    switch($d) { 
        case 'easy': return 1; 
        case 'medium': return 2; 
        case 'hard': return 3; 
        default: return 2; 
    }
}
?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Entré - Uppdrag</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/laro-style.css">
</head>
<body class="dungeon-bg">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4">Entré — Välj uppdrag</h1>
      <div>
        <a href="../dashboard.php" class="btn btn-sm btn-outline-light">Tillbaka</a>
      </div>
    </div>

    <div class="row">
      <?php if (empty($rows)): ?>
        <div class="col-12"><div class="alert alert-info">Inga uppdrag hittades.</div></div>
      <?php else: ?>
        <?php foreach ($rows as $r): 
            $locked = $user['level'] < (int)$r['min_level'];
        ?>
        <div class="col-md-6 mb-3">
          <div class="card">
            <div class="card-body">
              <div class="d-flex justify-content-between">
                <div>
                  <h5 class="card-title mb-1"><?php echo htmlspecialchars($r['title']); ?></h5>
                  <div class="small text-muted">
                    Svårighet: <?php echo htmlspecialchars(ucfirst($r['difficulty'])); ?>
                  </div>
                </div>
                <div class="text-end">
                  <?php if ($locked): ?>
                    <div class="badge bg-danger">Låst</div>
                    <div class="small text-muted">Kräver nivå <?php echo (int)$r['min_level']; ?></div>
                  <?php else: ?>
                    <a href="run.php?exid=<?php echo (int)$r['id']; ?>" class="btn btn-primary">Starta</a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
