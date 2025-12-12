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
    ORDER BY FIELD(e.difficulty,'easy','medium','hard'), e.created_at DESC
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
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .exercise-card {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1rem;
      transition: all 0.3s;
      backdrop-filter: blur(10px);
    }
    
    .exercise-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 24px rgba(255, 193, 7, 0.2);
      border-color: rgba(255, 193, 7, 0.3);
    }
    
    .exercise-card.locked {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
    .exercise-card.locked:hover {
      transform: none;
      box-shadow: none;
    }
    
    .reward-showcase {
      display: flex;
      gap: 0.5rem;
      margin-top: 0.75rem;
      flex-wrap: wrap;
    }
    
    .reward-icon {
      width: 32px;
      height: 32px;
      opacity: 0.3;
      transition: all 0.2s;
      filter: grayscale(100%);
    }
    
    .reward-icon.earned {
      opacity: 1;
      filter: grayscale(0%);
      animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }
    
    .difficulty-badge {
      display: inline-block;
      padding: 0.25rem 0.75rem;
      border-radius: 12px;
      font-size: 0.875rem;
      font-weight: 600;
    }
    
    .difficulty-easy {
      background: rgba(76, 175, 80, 0.2);
      color: #4CAF50;
      border: 1px solid rgba(76, 175, 80, 0.4);
    }
    
    .difficulty-medium {
      background: rgba(255, 152, 0, 0.2);
      color: #FF9800;
      border: 1px solid rgba(255, 152, 0, 0.4);
    }
    
    .difficulty-hard {
      background: rgba(244, 67, 54, 0.2);
      color: #F44336;
      border: 1px solid rgba(244, 67, 54, 0.4);
    }
    
    .stats-row {
      display: flex;
      gap: 1rem;
      margin-top: 0.75rem;
      font-size: 0.875rem;
      color: rgba(255, 255, 255, 0.7);
    }
    
    .stat-item {
      display: flex;
      align-items: center;
      gap: 0.25rem;
    }
  </style>
</head>
<body class="dungeon-bg">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h3 mb-1">Välj ditt uppdrag</h1>
        <p class="text-muted mb-0">Nivå <?php echo (int)$user['level']; ?> • <?php echo (int)$user['points']; ?> XP</p>
      </div>
      <a href="../dashboard.php" class="btn btn-outline-light">← Tillbaka</a>
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
                <h5 class="mb-1"><?php echo htmlspecialchars($r['title']); ?></h5>
                <span class="difficulty-badge difficulty-<?php echo htmlspecialchars($r['difficulty']); ?>">
                  <?php echo ucfirst($r['difficulty']); ?>
                </span>
              </div>
              
              <?php if ($locked): ?>
                <div class="text-end">
                  <div class="badge bg-danger mb-1">🔒 Låst</div>
                  <div class="small text-muted">Kräver nivå <?php echo (int)$r['min_level']; ?></div>
                </div>
              <?php else: ?>
                <a href="play.php?exercise_id=<?php echo (int)$r['id']; ?>" class="btn btn-warning">
                  <?php echo $hasCompleted ? 'Gör igen' : 'Starta'; ?>
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
                <p class="text-muted small mt-2 mb-0">
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
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>