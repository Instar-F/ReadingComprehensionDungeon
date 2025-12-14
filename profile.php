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

// Question type stats - count exercises completed, not individual questions
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
<link rel="stylesheet" href="assets/css/style.css">
<style>
/* Remove dark overlay for profile page and make background repeat */
body.dungeon-bg::before {
    display: none;
}

body.dungeon-bg {
    background-image: url('assets/images/dungeon-bg.jpg');
    background-size: auto;
    background-repeat: repeat;
    background-position: top left;
}

.compact-profile {
    max-width: 1400px;
    margin: 0 auto;
    position: relative;
    z-index: 1;
}

.stat-mini {
    background: rgba(15, 23, 36, 0.85);
    backdrop-filter: blur(10px);
    border-radius: 8px;
    padding: 0.75rem;
    text-align: center;
    border: 1px solid rgba(255, 193, 7, 0.2);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.stat-mini-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: #ffc107;
}

.stat-mini-label {
    font-size: 0.75rem;
    color: rgba(230, 238, 248, 0.7);
    margin-top: 0.25rem;
}


.filter-chips {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.filter-chip {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    background: rgba(15, 23, 36, 0.70);
    backdrop-filter: blur(8px);
    color: rgba(255, 255, 255, 0.8);
    cursor: pointer;
    transition: all 0.3s;
    font-size: 0.875rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
}

.filter-chip:hover {
    border-color: rgba(255, 193, 7, 0.5);
}

.filter-chip.active {
    background: rgba(255, 193, 7, 0.2);
    border-color: #ffc107;
    color: #ffc107;
}

.storyline-card {
    background: rgba(15, 23, 36, 0.70);
    backdrop-filter: blur(8px);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    border: 1px solid rgba(255, 255, 255, 0.15);
    transition: all 0.3s;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.storyline-card:hover {
    border-color: rgba(255, 193, 7, 0.3);
}

.storyline-progress-bar {
    height: 6px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.storyline-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #667eea, #764ba2);
    transition: width 0.3s;
}

.question-type-mini {
    background: rgba(15, 23, 36, 0.70);
    backdrop-filter: blur(8px);
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    border: 1px solid rgba(255, 255, 255, 0.15);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.progress-circle-mini {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: conic-gradient(#ffc107 calc(var(--progress) * 1%), rgba(255,255,255,0.1) 0);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.progress-circle-mini::before {
    content: '';
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #0f1724;
    position: absolute;
}

.progress-text-mini {
    font-size: 0.75rem;
    font-weight: bold;
    z-index: 1;
}

.section-compact {
    background: rgba(15, 23, 36, 0.90);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid rgba(255, 193, 7, 0.2);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
}

.section-title {
    font-size: 1.25rem;
    font-weight: bold;
    color: #ffc107;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.badge-grid-compact {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
    gap: 0.5rem;
}

.reward-mini {
    width: 24px;
    height: 24px;
    display: inline-block;
}

.profile-header-compact {
    background: rgba(15, 23, 36, 0.90);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid rgba(255, 193, 7, 0.2);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
}

.hidden {
    display: none !important;
}

/* Clash of Clans Style Badge Cards */
.badge-list-coc {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.badge-card-coc {
    display: flex;
    align-items: center;
    background: rgba(15, 23, 36, 0.70);
    backdrop-filter: blur(8px);
    border-radius: 12px;
    padding: 1rem;
    border: 2px solid rgba(255, 255, 255, 0.1);
    transition: all 0.3s;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    position: relative;
    overflow: hidden;
}

.badge-card-coc::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: rgba(255, 255, 255, 0.2);
    transition: all 0.3s;
}

.badge-card-coc.earned::before {
    background: linear-gradient(180deg, #ffc107, #ff9800);
    box-shadow: 0 0 12px rgba(255, 193, 7, 0.5);
}

.badge-card-coc.earned {
    border-color: rgba(255, 193, 7, 0.3);
    background: rgba(255, 193, 7, 0.05);
}

.badge-card-coc.locked {
    opacity: 0.7;
}

.badge-card-coc:hover {
    transform: translateX(4px);
    border-color: rgba(255, 193, 7, 0.4);
}

/* Badge Icon */
.badge-icon-coc {
    width: 64px;
    height: 64px;
    min-width: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    background: rgba(255, 193, 7, 0.1);
    border-radius: 12px;
    border: 2px solid rgba(255, 193, 7, 0.2);
    margin-right: 1rem;
}

.badge-card-coc.earned .badge-icon-coc {
    background: rgba(255, 193, 7, 0.2);
    border-color: rgba(255, 193, 7, 0.4);
    box-shadow: 0 0 16px rgba(255, 193, 7, 0.3);
    animation: glow-pulse 2s infinite;
}

@keyframes glow-pulse {
    0%, 100% { box-shadow: 0 0 16px rgba(255, 193, 7, 0.3); }
    50% { box-shadow: 0 0 24px rgba(255, 193, 7, 0.5); }
}

/* Badge Info */
.badge-info-coc {
    flex: 1;
    min-width: 0;
}

.badge-title-coc {
    font-size: 1.1rem;
    font-weight: bold;
    color: #fff;
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.badge-check {
    color: #4CAF50;
    font-size: 1.2rem;
    animation: pop-in 0.3s ease-out;
}

@keyframes pop-in {
    0% { transform: scale(0); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}

.badge-description-coc {
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 0.5rem;
}

.badge-progress-bar-coc {
    height: 8px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
    overflow: hidden;
    margin-top: 0.5rem;
    margin-bottom: 0.25rem;
}

.badge-progress-fill-coc {
    height: 100%;
    background: linear-gradient(90deg, #ffc107, #ff9800);
    border-radius: 4px;
    transition: width 0.5s ease;
    box-shadow: 0 0 8px rgba(255, 193, 7, 0.5);
}

.badge-progress-text-coc {
    font-size: 0.75rem;
    color: rgba(255, 193, 7, 0.9);
    font-weight: 600;
}

/* Badge Reward */
.badge-reward-coc {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 80px;
    padding: 0.5rem;
    background: rgba(255, 193, 7, 0.1);
    border-radius: 8px;
    border: 2px solid rgba(255, 193, 7, 0.2);
}

.badge-card-coc.earned .badge-reward-coc {
    background: rgba(255, 193, 7, 0.2);
    border-color: rgba(255, 193, 7, 0.4);
}

.badge-points-coc {
    font-size: 1.5rem;
    font-weight: bold;
    color: #ffc107;
    line-height: 1;
}

.badge-points-label-coc {
    font-size: 0.75rem;
    color: rgba(255, 193, 7, 0.8);
    margin-top: 0.25rem;
}

/* Responsive */
@media (max-width: 768px) {
    .badge-card-coc {
        padding: 0.75rem;
    }
    
    .badge-icon-coc {
        width: 48px;
        height: 48px;
        min-width: 48px;
        font-size: 2rem;
    }
    
    .badge-title-coc {
        font-size: 1rem;
    }
    
    .badge-reward-coc {
        min-width: 60px;
    }
    
    .badge-points-coc {
        font-size: 1.25rem;
    }
}
</style>
</head>
<body class="dungeon-bg">
<div class="container compact-profile py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">📊 Min Profil</h1>
        <a href="dashboard.php" class="btn btn-outline-light">← Tillbaka</a>
    </div>

    <!-- Compact Header -->
    <div class="profile-header-compact">
        <div class="row align-items-center">
            <div class="col-md-2 text-center mb-3 mb-md-0">
                <div style="width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; color: white; margin: 0 auto;">
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
                            <div class="stat-mini-label">💎 Diamanter</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= $stats['emeralds_earned'] ?></div>
                            <div class="stat-mini-label">💚 Smaragder</div>
                        </div>
                    </div>
                    <div class="col-4 col-md-2">
                        <div class="stat-mini">
                            <div class="stat-mini-value"><?= gmdate("H:i", $stats['total_time_spent']) ?></div>
                            <div class="stat-mini-label">Tid</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Storylines and Question Types -->
        <div class="col-12">
            <div class="row">
                <!-- Storylines Progress -->
                <?php if (!empty($storylineProgress)): ?>
                <div class="col-lg-6">
                <div class="section-compact">
                    <div class="section-title">
                        <i class="fas fa-book"></i> Äventyrsspår
                    </div>
                <?php foreach ($storylineProgress as $sp): 
                    $progress = $sp['total_exercises'] > 0 ? ($sp['completed_exercises'] / $sp['total_exercises']) * 100 : 0;
                    $avgStars = round($sp['avg_reward_level']);
                ?>
                <div class="storyline-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div style="font-weight: bold; font-size: 1rem;">
                                <?= htmlspecialchars($sp['icon']) ?> <?= htmlspecialchars($sp['name']) ?>
                            </div>
                            <div style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.6); margin-top: 0.25rem;">
                                <?= $sp['completed_exercises'] ?> / <?= $sp['total_exercises'] ?> övningar
                            </div>
                            <div class="storyline-progress-bar">
                                <div class="storyline-progress-fill" style="width: <?= $progress ?>%"></div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 2px; margin-left: 1rem;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span style="color: <?= $i <= $avgStars ? '#ffc107' : 'rgba(255,255,255,0.2)' ?>; font-size: 0.875rem;">⭐</span>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                </div>
                </div>
                <?php endif; ?>

                <!-- Question Type Mastery -->
                <div class="col-lg-6">
                <div class="section-compact">
                <div class="section-title">
                    <i class="fas fa-list"></i> Frågetyp-mästerskap
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
                                        <?= $qs['exercises_completed'] ?> / <?= $qs['total_exercises'] ?> övningar • ~<?= $performance ?>% prestanda
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
            </div>
            </div>
            </div>
        </div>

        <!-- Right Column - Make it full width for CoC style -->
        <div class="col-12">
            <!-- Badges Section -->
            <div class="section-compact">
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

                <!-- CoC Style Badge List -->
                <div class="badge-list-coc">
                    <?php 
                    // Get user progress for badges
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Badge filtering
document.querySelectorAll('.filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        // Update active state
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