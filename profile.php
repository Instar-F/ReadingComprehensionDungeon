<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/BadgeManager.php';

if (!is_logged_in()) {
    header('Location: auth/signin.php');
    exit;
}

$user = current_user($pdo);
$userId = (int)$user['id'];

// Fetch complete user data
$userStmt = $pdo->prepare("SELECT id, name, level, points FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userFull = $userStmt->fetch(PDO::FETCH_ASSOC);

$username = $userFull['name'] ?? 'User';
$userLevel = $userFull['level'] ?? 1;

// Fetch user stats
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT exercise_id) as exercises_completed,
        COALESCE(SUM(score), 0) as total_score,
        COUNT(*) as total_attempts,
        COALESCE(SUM(elapsed_time), 0) as total_time_spent,
        COUNT(CASE WHEN reward IN ('diamond', 'emerald') THEN 1 END) as diamonds_earned,
        COUNT(CASE WHEN reward = 'emerald' THEN 1 END) as emeralds_earned
    FROM attempt_sessions 
    WHERE user_id = ? AND finished_at IS NOT NULL
");
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get badge manager and stats
$badgeManager = new BadgeManager($pdo, $userId);
$badges = $badgeManager->getUserBadges();
$earnedBadges = array_filter($badges, fn($b) => $b['earned']);
$totalBadges = count($badges);
$earnedCount = count($earnedBadges);

// Question type stats
$questionTypeStats = $pdo->prepare("
    SELECT 
        q.type,
        COUNT(DISTINCT CASE 
            WHEN uep.completed = 1 THEN e.id 
        END) as exercises_completed,
        COUNT(DISTINCT e.id) as total_exercises,
        COALESCE(AVG(CASE 
            WHEN uep.best_reward = 'emerald' THEN 100
            WHEN uep.best_reward = 'diamond' THEN 90
            WHEN uep.best_reward = 'gold' THEN 75
            WHEN uep.best_reward = 'iron' THEN 60
            WHEN uep.best_reward = 'copper' THEN 40
            WHEN uep.best_reward = 'coal' THEN 20
            ELSE NULL
        END), 0) as avg_performance
    FROM questions q
    JOIN exercises e ON q.exercise_id = e.id
    LEFT JOIN user_exercise_progress uep ON e.id = uep.exercise_id AND uep.user_id = ?
    GROUP BY q.type
    HAVING total_exercises > 0
    ORDER BY exercises_completed DESC
");
$questionTypeStats->execute([$userId]);
$questionStats = $questionTypeStats->fetchAll(PDO::FETCH_ASSOC);

// Storyline progress
$storylineProgressStmt = $pdo->prepare("
    SELECT 
        s.id,
        s.name,
        s.icon,
        s.description,
        COUNT(DISTINCT e.id) as total_exercises,
        COUNT(DISTINCT uep.exercise_id) as completed_exercises,
        COALESCE(AVG(CASE 
            WHEN uep.best_reward = 'emerald' THEN 6
            WHEN uep.best_reward = 'diamond' THEN 5
            WHEN uep.best_reward = 'gold' THEN 4
            WHEN uep.best_reward = 'iron' THEN 3
            WHEN uep.best_reward = 'copper' THEN 2
            WHEN uep.best_reward = 'coal' THEN 1
            ELSE 0
        END), 0) as avg_reward_level
    FROM storylines s
    LEFT JOIN exercises e ON s.id = e.storyline_id
    LEFT JOIN user_exercise_progress uep ON e.id = uep.exercise_id AND uep.user_id = ?
    GROUP BY s.id
    ORDER BY s.display_order, s.id
");
$storylineProgressStmt->execute([$userId]);
$storylineProgress = $storylineProgressStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryNames = [
    'progress' => 'Framsteg',
    'completion' => 'Slutförande',
    'mastery' => 'Mästerskap',
    'time' => 'Tid',
    'secret' => 'Hemliga'
];

$questionTypeNames = [
    'mcq' => 'Flerval',
    'truefalse' => 'Sant/Falskt',
    'ordering' => 'Ordning',
    'fillblank' => 'Fyll i',
    'matching' => 'Matchning'
];
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Min Profil — RC Dungeon</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dirt-bg">
<header style="position:relative;">
    <div class="hero-top">
      <div class="hero-avatar" title="Din hjälte"><?php echo strtoupper(substr($user['name'] ?? $user['email'],0,1)); ?></div>
      <div class="hero-pill">XP <?php echo (int)$user['points']; ?> • Lv <?php echo (int)$user['level']; ?></div>
    </div>
</header>

<main class="dirt-bg">
<div class="container compact-profile py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-1 gametext">DIN PROFIL</h2>
        <a href="menu.php" class="btn btn-outline-light">← Tillbaka</a>
    </div>

    <!-- Compact Header -->
    <div class="profile-header-compact">
        <div class="row align-items-center">
            <div class="col-md-2 text-center mb-3 mb-md-0">
                <div class="profile-avatar">
                    <?= strtoupper(substr($username, 0, 2)) ?>
                </div>
                <div style="margin-top: 0.5rem;">
                    <div style="font-weight: bold; font-size: 1.1rem;"><?= htmlspecialchars($username) ?></div>
                    <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.875rem;">Nivå <?= $userLevel ?></div>
                </div>
            </div>
            <div class="col-md-10">
                <div class="row g-2">
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= $userFull['points'] ?></div>
                            <div class="stat-mini-label">Poäng</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= $stats['exercises_completed'] ?></div>
                            <div class="stat-mini-label">Övningar</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= $earnedCount ?>/<?= $totalBadges ?></div>
                            <div class="stat-mini-label">Utmärkelser</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= $stats['diamonds_earned'] ?></div>
                            <div class="stat-mini-label">Diamanter</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= $stats['emeralds_earned'] ?></div>
                            <div class="stat-mini-label">Smaragder</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= gmdate('H:i', $stats['total_time_spent']) ?></div>
                            <div class="stat-mini-label">⏱️ Tid</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4 profile-equal">
        <!-- Left Column -->
        <div class="col-12 col-lg-6 ProfileColumn">
            <!-- Storyline Progress -->
            <?php if (!empty($storylineProgress)): ?>
            <div class="section-compact mb-4">
                <div class="section-title">
                    <i class="fas fa-book-open"></i> Storyline Framsteg
                </div>
                <div class="storyline-list">
                <?php foreach ($storylineProgress as $sl): 
                    $completion = $sl['total_exercises'] > 0 ? ($sl['completed_exercises'] / $sl['total_exercises']) * 100 : 0;
                ?>
                <div class="storyline-progress-item">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <div style="font-weight: bold; font-size: 0.95rem;">
                                <?= htmlspecialchars($sl['icon'] ?? '📖') ?> <?= htmlspecialchars($sl['name']) ?>
                            </div>
                            <div style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.6);">
                                <?= $sl['completed_exercises'] ?> / <?= $sl['total_exercises'] ?> övningar slutförda
                            </div>
                        </div>
                        <div class="progress-circle-mini" style="--progress: <?= $completion ?>;">
                            <span class="progress-text-mini"><?= round($completion) ?>%</span>
                        </div>
                    </div>
                    <div class="progress" style="height: 8px; background: rgba(255, 255, 255, 0.1);">
                        <div class="progress-bar bg-warning" style="width: <?= $completion ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Question Type Performance -->
            <?php if (!empty($questionStats)): ?>
            <div class="section-compact">
                <div class="section-title">
                    <i class="fas fa-chart-bar"></i> Prestanda per Frågetyp
                </div>
                <div class="row g-2">
                    <?php foreach ($questionStats as $qs): 
                        $completion = $qs['total_exercises'] > 0 ? ($qs['exercises_completed'] / $qs['total_exercises']) * 100 : 0;
                        $performance = round($qs['avg_performance']);
                    ?>
                    <div class="col-md-6">
                        <div class="question-type-mini">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1">
                                    <div style="font-weight: bold; font-size: 0.875rem;">
                                        <?= htmlspecialchars($questionTypeNames[$qs['type']] ?? $qs['type']) ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.6);">
                                        <?= $qs['exercises_completed'] ?> / <?= $qs['total_exercises'] ?> övningar  <br> ~<?= $performance ?>% prestanda
                                    </div>
                                </div>
                                <div class="progress-circle-mini" style="--progress: <?= $completion ?>;">
                                    <span class="progress-text-mini"><?= round($completion) ?>%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column -->
        <div class="col-12 col-lg-6 ProfileColumn">
            <!-- Badges Section -->
            <div class="section-compact badge-section">
                <div class="section-title">
                    <i class="fas fa-trophy"></i> Utmärkelser
                    <span style="margin-left: auto; font-size: 0.875rem; color: rgba(255, 193, 7, 0.8);">
                        <?= $earnedCount ?> / <?= $totalBadges ?> upplåsta
                    </span>
                </div>
                
                <!-- Filter Chips -->
                <div class="filter-chips">
                    <div class="filter-chip active" data-filter="all">Alla</div>
                    <div class="filter-chip" data-filter="earned">Erhållna</div>
                    <?php foreach (array_keys($categoryNames) as $cat): ?>
                        <div class="filter-chip" data-filter="<?= $cat ?>"><?= $categoryNames[$cat] ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Badge List -->
                <div class="badge-list-coc">
                    <?php 
                    foreach ($badges as $badge): 
                        $progress = $badgeManager->getBadgeProgress($badge);
                        $required = (int)$badge['requirement_value'];
                        $progressPercent = $required > 0 ? min(100, ($progress / $required) * 100) : 0;
                        
                        // Format requirement text
                        $reqText = '';
                        switch ($badge['requirement_type']) {
                            case 'exercises_completed':
                                $reqText = "Slutför $required övningar";
                                break;
                            case 'diamonds_earned':
                                $reqText = "Tjäna $required diamanter/smaragder";
                                break;
                            case 'emeralds_earned':
                                $reqText = "Tjäna $required smaragder";
                                break;
                            case 'time_spent':
                                $hours = floor($required / 3600);
                                $minutes = floor(($required % 3600) / 60);
                                $reqText = "Spendera " . ($hours > 0 ? "$hours tim " : "") . ($minutes > 0 ? "$minutes min" : "");
                                break;
                            case 'coal_earned':
                                $reqText = "Tjäna $required kol-belöningar";
                                break;
                            case 'copper_earned':
                                $reqText = "Tjäna $required koppar-belöningar";
                                break;
                            case 'iron_earned':
                                $reqText = "Tjäna $required järn+ belöningar";
                                break;
                            case 'gold_earned':
                                $reqText = "Tjäna $required guld+ belöningar";
                                break;
                            case 'type_master':
                                $reqText = "Mästra alla övningar av denna typ med guld+";
                                break;
                            case 'difficulty_complete':
                                $reqText = "Slutför alla övningar på denna svårighetsgrad";
                                break;
                            default:
                                $reqText = $badge['description'];
                        }
                    ?>
                        <div class="badge-card-coc <?= $badge['earned'] ? 'earned' : 'locked' ?>" 
                             data-category="<?= $badge['category'] ?>"
                             data-earned="<?= $badge['earned'] ? '1' : '0' ?>">
                            
                            <!-- Left: Icon -->
                            <div class="badge-icon-coc">
                                <?= $badge['earned'] ? '🏆' : '🔒' ?>
                            </div>
                            
                            <!-- Center: Info -->
                            <div class="badge-info-coc">
                                <div class="badge-title-coc">
                                    <?= htmlspecialchars($badge['title']) ?>
                                    <?php if ($badge['earned']): ?>
                                        <span class="badge-check">✓</span>
                                    <?php endif; ?>
                                </div>
                                <div class="badge-description-coc">
                                    <?= htmlspecialchars($reqText) ?>
                                </div>
                                <?php if (!$badge['earned'] && $progressPercent > 0): ?>
                                    <div class="badge-progress-bar-coc">
                                        <div class="badge-progress-fill-coc" style="width: <?= $progressPercent ?>%"></div>
                                    </div>
                                    <div class="badge-progress-text-coc">
                                        <?= number_format($progress) ?> / <?= number_format($required) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Right: Reward -->
                            <div class="badge-reward-coc">
                                <div class="badge-points-coc">
                                    +<?= $badge['points_reward'] ?>
                                </div>
                                <div class="badge-points-label-coc">XP</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Badge filtering
document.querySelectorAll('.filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        
        const filter = chip.dataset.filter;
        const badges = document.querySelectorAll('.badge-card-coc');
        
        badges.forEach(badge => {
            const category = badge.dataset.category;
            const earned = badge.dataset.earned === '1';
            
            let show = false;
            
            if (filter === 'all') {
                show = true;
            } else if (filter === 'earned') {
                show = earned;
            } else {
                show = category === filter;
            }
            
            if (show) {
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        });
    });
});
</script>
</body>
</html>