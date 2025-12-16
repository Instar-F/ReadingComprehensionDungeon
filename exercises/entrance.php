<?php
require_once __DIR__ . '/../config.php';
if (!is_logged_in()) { header('Location: ../auth/signin.php'); exit; }

$user = current_user($pdo);

// Fetch exercises with user's best performance
$sql = "
    SELECT e.id, e.title, e.difficulty, e.min_level, e.created_at,
           ep.content AS passage,
           (SELECT COUNT(*) FROM questions WHERE exercise_id = e.id) as question_count,
           (SELECT reward FROM attempt_sessions 
            WHERE user_id = ? AND exercise_id = e.id AND finished_at IS NOT NULL 
            ORDER BY score DESC, elapsed_time ASC LIMIT 1) as best_reward,
           (SELECT score FROM attempt_sessions 
            WHERE user_id = ? AND exercise_id = e.id AND finished_at IS NOT NULL 
            ORDER BY score DESC LIMIT 1) as best_score,
           (SELECT elapsed_time FROM attempt_sessions 
            WHERE user_id = ? AND exercise_id = e.id AND finished_at IS NOT NULL 
            ORDER BY elapsed_time ASC LIMIT 1) as best_time
    FROM exercises e
    LEFT JOIN exercise_passages ep ON ep.exercise_id = e.id
    ORDER BY e.min_level ASC, FIELD(e.difficulty,'easy','medium','hard'), e.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user['id'], $user['id'], $user['id']]);
$rows = $stmt->fetchAll();

function formatTime($seconds) {
    if (!$seconds) return '--';
    $mins = floor($seconds / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d', $mins, $secs);
}
?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Entré - Uppdrag</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="dirt-bg">
  <main class="dirt-bg">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h2 class="mb-1 gametext">Välj ditt uppdrag</h2>
        <p class="mb-0 gametext">Nivå <?php echo (int)$user['level']; ?> • <?php echo (int)$user['points']; ?> XP</p>
      </div>
      <a href="../menu.php" class="btn btn-outline-light">← Tillbaka</a>
    </div>

    <div class="row">
      <?php if (empty($rows)): ?>
        <div class="col-12">
          <div class="alert alert-info">Inga uppdrag hittades.</div>
        </div>
      <?php else: ?>
        <?php foreach ($rows as $r): 
            $locked = $user['level'] < (int)$r['min_level'];
            $hasCompleted = !empty($r['best_reward']);
            
            $rewards = ['coal', 'copper', 'iron', 'gold', 'diamond', 'emerald'];
            $earnedIndex = $hasCompleted ? array_search($r['best_reward'], $rewards) : -1;
        ?>
        <div class="col-md-6 mb-3">
          <div class="exercise-card <?php echo $locked ? 'locked' : ''; ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <h5 class="mb-2 gametext-small"><?php echo htmlspecialchars($r['title']); ?></h5>
                <span style="font-size: 0.75rem  ;" class="gametext-smaller difficulty-badge difficulty-<?php echo htmlspecialchars($r['difficulty']); ?>">
                  <?php echo ucfirst($r['difficulty']); ?>
                </span>
              </div>
              
              <?php if ($locked): ?>
                <div class="text-end">
                  <div class="badge bg-danger mb-2 gametext-smaller" style="padding: 0.25rem 0.5rem;">Låst</div>
                  <div class="small">Kräver nivå <?php echo (int)$r['min_level']; ?></div>
                </div>
              <?php else: ?>
                <a href="play.php?exercise_id=<?php echo (int)$r['id']; ?>" class="play-btn gametext-smaller">
                  <?php echo $hasCompleted ? 'Spela' : 'Starta'; ?>
                </a>
              <?php endif; ?>
            </div>
            
            <?php if ($hasCompleted): ?>
              <div class="stats-row">
                <div class="stat-item">
                  <span>🏆</span>
                  <span><?php echo (int)$r['best_score']; ?> XP</span>
                </div>
                <div class="stat-item">
                  <span>⏱️</span>
                  <span><?php echo formatTime($r['best_time']); ?></span>
                </div>
                <div class="stat-item">
                  <span>📝</span>
                  <span><?php echo (int)$r['question_count']; ?> frågor</span>
                </div>
              </div>
              
              <div class="reward-showcase">
                <?php foreach ($rewards as $idx => $reward): ?>
                  <img 
                    src="../assets/img/<?php echo $reward; ?>.png" 
                    alt="<?php echo $reward; ?>"
                    class="reward-icon <?php echo ($idx <= $earnedIndex) ? 'earned' : ''; ?>"
                    title="<?php echo ucfirst($reward); ?>"
                  >
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <?php if (!$locked): ?>
                <p class="small mt-2 mb-0">
                  <?php echo (int)$r['question_count']; ?> frågor • 
                  <?php if ($r['difficulty'] === 'easy'): ?>
                    Perfekt för nybörjare
                  <?php elseif ($r['difficulty'] === 'medium'): ?>
                    Måttlig utmaning
                  <?php else: ?>
                    Avancerad nivå
                  <?php endif; ?>
                </p>
              <?php endif; ?>
              
              <!-- ALWAYS SHOW REWARDS -->
              <div class="reward-showcase">
                <?php foreach ($rewards as $idx => $reward): ?>
                  <img 
                    src="../assets/img/<?php echo $reward; ?>.png" 
                    alt="<?php echo $reward; ?>"
                    class="reward-icon <?php echo ($idx <= $earnedIndex) ? 'earned' : 'empty'; ?>"
                    title="<?php echo ucfirst($reward); ?>"
                  >
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  </main>
</body>
</html>