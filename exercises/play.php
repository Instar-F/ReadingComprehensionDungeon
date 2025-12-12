<?php
require_once __DIR__ . '/../config.php';

if (!is_logged_in()) {
    header('Location: ../auth/signin.php');
    exit;
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

$user = current_user($pdo);
$userId = (int)$user['id'];

$exerciseId = isset($_GET['exercise_id']) ? (int)$_GET['exercise_id'] : 0;
if (!$exerciseId) {
    echo "<h1>Övning hittades inte</h1>";
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if ($method === 'POST' && strpos($contentType, 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $action = $data['action'] ?? '';

    try {
        if ($action === 'start_attempt') {
            $stmt = $pdo->prepare("INSERT INTO attempt_sessions (user_id, exercise_id) VALUES (?, ?)");
            $stmt->execute([$userId, $exerciseId]);
            $attemptId = (int)$pdo->lastInsertId();
            echo json_encode(['success' => true, 'attempt_id' => $attemptId]);
            exit;
        }

        if ($action === 'submit_answer') {
            $attemptId = (int)($data['attempt_id'] ?? 0);
            $questionId = (int)($data['question_id'] ?? 0);
            $userAnswer = $data['answer'] ?? null;

            if (!$attemptId || !$questionId || $userAnswer === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing parameters']);
                exit;
            }

            $qst = $pdo->prepare("SELECT * FROM questions WHERE id=?");
            $qst->execute([$questionId]);
            $question = $qst->fetch(PDO::FETCH_ASSOC);

            if (!$question) {
                http_response_code(404);
                echo json_encode(['error' => 'Question not found']);
                exit;
            }

            $meta = $question['meta'] ? json_decode($question['meta'], true) : [];
            $pointsForQuestion = isset($meta['points']) ? (int)$meta['points'] : 10;

            $isCorrect = 0;
            $awardedPoints = 0;

            switch ($question['type']) {
                case 'mcq':
                    $choiceId = $userAnswer['choice_id'] ?? 0;
                    if ($choiceId) {
                        $cstm = $pdo->prepare("SELECT is_correct FROM question_choices WHERE id=? AND question_id=?");
                        $cstm->execute([$choiceId, $questionId]);
                        $choice = $cstm->fetch(PDO::FETCH_ASSOC);
                        $isCorrect = ($choice && (int)$choice['is_correct'] === 1) ? 1 : 0;
                        $awardedPoints = $isCorrect ? $pointsForQuestion : 0;
                    }
                    break;

                case 'truefalse':
                    $raw = strtolower(trim((string)($userAnswer['choice_id'] ?? '')));
                    $userVal = ($raw === 'true') ? 1 : 0;
                    $correctVal = (int)($question['correct_answer'] ?? 0);
                    $isCorrect = ($userVal === $correctVal) ? 1 : 0;
                    $awardedPoints = $isCorrect ? $pointsForQuestion : 0;
                    break;


                case 'fillblank':
                    break;

                case 'ordering':
                    break;

                case 'matching':
                    break;

                default:
                    $isCorrect = 0;
                    $awardedPoints = 0;
            }

            $exists = $pdo->prepare("SELECT id FROM attempt_answers WHERE attempt_id=? AND question_id=?");
            $exists->execute([$attemptId, $questionId]);
            $ea = $exists->fetch(PDO::FETCH_ASSOC);

            if ($ea) {
                $up = $pdo->prepare("UPDATE attempt_answers SET user_answer=?, correct=?, points_awarded=? WHERE id=?");
                $up->execute([json_encode($userAnswer, JSON_UNESCAPED_UNICODE), $isCorrect, $awardedPoints, $ea['id']]);
                $answerId = (int)$ea['id'];
            } else {
                $ins = $pdo->prepare("INSERT INTO attempt_answers (attempt_id, question_id, user_answer, correct, points_awarded) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$attemptId, $questionId, json_encode($userAnswer, JSON_UNESCAPED_UNICODE), $isCorrect, $awardedPoints]);
                $answerId = (int)$pdo->lastInsertId();
            }

            $answerStmt = $pdo->prepare("SELECT correct FROM attempt_answers WHERE attempt_id=? ORDER BY id ASC");
            $answerStmt->execute([$attemptId]);
            $answers = array_map(fn($a) => ((int)$a['correct'] === 1), $answerStmt->fetchAll(PDO::FETCH_ASSOC));

            echo json_encode([
                'success' => true,
                'correct' => $isCorrect,
                'points_awarded' => $awardedPoints,
                'answer_id' => $answerId,
                'answers' => $answers
            ]);
            exit;
        }

        if ($action === 'finish_attempt') {
            $attemptId = (int)($data['attempt_id'] ?? 0);
            $elapsedTime = (int)($data['elapsed_time'] ?? 0);

            if (!$attemptId) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing attempt_id']);
                exit;
            }

            $pdo->beginTransaction();
            $stm = $pdo->prepare("SELECT * FROM attempt_sessions WHERE id=? AND user_id=? FOR UPDATE");
            $stm->execute([$attemptId, $userId]);
            $attempt = $stm->fetch(PDO::FETCH_ASSOC);

            if (!$attempt) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Attempt not found']);
                exit;
            }

                    // Calculate total points earned in this attempt
        $sumQ = $pdo->prepare("SELECT COALESCE(SUM(points_awarded), 0) AS total FROM attempt_answers WHERE attempt_id=?");
        $sumQ->execute([$attemptId]);
        $totalPoints = (int)($sumQ->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // Get total possible points for the exercise
        $qstm = $pdo->prepare("SELECT meta FROM questions WHERE exercise_id=?");
        $qstm->execute([$exerciseId]);
        $allQuestions = $qstm->fetchAll(PDO::FETCH_ASSOC);

        $totalPossiblePoints = 0;
        foreach ($allQuestions as $q) {
            $meta = $q['meta'] ? json_decode($q['meta'], true) : [];
            $totalPossiblePoints += isset($meta['points']) ? (int)$meta['points'] : 10;
        }

        // Calculate percentage
        $percentage = ($totalPossiblePoints > 0) ? ($totalPoints / $totalPossiblePoints) * 100 : 0;

        // Determine reward based on percentage
        $reward = 'coal';
        if ($percentage >= 70) $reward = 'copper';
        if ($percentage >= 80) $reward = 'iron';
        if ($percentage >= 90) $reward = 'gold';
        if ($percentage >= 100) $reward = 'diamond';

     $metadata = json_decode($exercise['metadata'] ?? '{}', true);
     $emeraldSeconds = isset($metadata['time_limit']) ? (int)$metadata['time_limit'] : 60;
        // Time bonus for diamond performance
        if ($reward === 'diamond' && $elapsedTime > 0 && $elapsedTime <= $emeraldSeconds) {
            $reward = 'emerald';
        }

        // Calculate XP with bonus
        $baseXP = $totalPoints;
        $bonusXP = 0;

        switch ($reward) {
            case 'iron':
                $bonusXP = (int)($baseXP * 0.1);
                break;
            case 'silver':
                $bonusXP = (int)($baseXP * 0.25);
                break;
            case 'gold':
                $bonusXP = (int)($baseXP * 0.5);
                break;
            case 'diamond':
                $bonusXP = (int)($baseXP * 1.0);
                break;
            case 'emerald':
                $bonusXP = (int)($baseXP * 2.0);
                break;
        }

        $finalXP = $baseXP + $bonusXP;

        // --- NEW: Calculate incremental XP ---
        $bestStmt = $pdo->prepare("SELECT MAX(score) as max_score FROM attempt_sessions WHERE user_id=? AND exercise_id=?");
        $bestStmt->execute([$userId, $exerciseId]);
        $prevBest = (int)($bestStmt->fetch(PDO::FETCH_ASSOC)['max_score'] ?? 0);

        $incrementalXP = max(0, $finalXP - $prevBest);
        
        $earnedMax = ($incrementalXP === 0 && $finalXP === $prevBest);


        // Update attempt session
        $upd = $pdo->prepare("UPDATE attempt_sessions SET finished_at=NOW(), score=?, reward=?, elapsed_time=? WHERE id=?");
        $upd->execute([$finalXP, $reward, $elapsedTime, $attemptId]);

        // Update user points and calculate new level
        $oldPoints = (int)$user['points'];
        $newPoints = $oldPoints + $incrementalXP;

        // Level calculation: 100 XP per level
        $newLevel = floor($newPoints / 100) + 1;

        $updUser = $pdo->prepare("UPDATE users SET points=?, level=? WHERE id=?");
        $updUser->execute([$newPoints, $newLevel, $userId]);

        $leveledUp = ($newLevel > (int)$user['level']);

        $pdo->commit();

        // Fetch all answers for reporting
        $answerStmt = $pdo->prepare("SELECT correct FROM attempt_answers WHERE attempt_id=? ORDER BY id ASC");
        $answerStmt->execute([$attemptId]);
        $answers = array_map(fn($a) => ((int)$a['correct'] === 1), $answerStmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'success' => true,
            'score' => $finalXP,
            'xp_earned' => $incrementalXP,
            'base_xp' => $baseXP,
            'bonus_xp' => $bonusXP,
            'reward' => $reward,
            'percentage' => round($percentage, 1),
            'answers' => $answers,
            'new_level' => $newLevel,
            'leveled_up' => $leveledUp,
            'maxed_out' => $earnedMax
        ]);
        exit;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Server error', 'message' => $ex->getMessage()]);
        exit;
    }
}

// GET: render page
$stm = $pdo->prepare("SELECT * FROM exercises WHERE id=?");
$stm->execute([$exerciseId]);
$exercise = $stm->fetch(PDO::FETCH_ASSOC);

$passageStmt = $pdo->prepare("SELECT * FROM exercise_passages WHERE exercise_id=?");
$passageStmt->execute([$exerciseId]);
$passage = $passageStmt->fetch(PDO::FETCH_ASSOC);

$qstm = $pdo->prepare("SELECT * FROM questions WHERE exercise_id=? ORDER BY pos ASC, id ASC");
$qstm->execute([$exerciseId]);
$questions = $qstm->fetchAll(PDO::FETCH_ASSOC);

$questionIds = array_column($questions, 'id');
$choicesMap = [];

if (!empty($questionIds)) {
    $in = implode(',', array_fill(0, count($questionIds), '?'));
    $cstm = $pdo->prepare("SELECT * FROM question_choices WHERE question_id IN ($in) ORDER BY question_id, pos ASC, id ASC");
    $cstm->execute($questionIds);
    $allChoices = $cstm->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allChoices as $c) {
        $choicesMap[$c['question_id']][] = $c;
    }
}

$clientQuestions = [];
foreach ($questions as $q) {
    $meta = $q['meta'] ? json_decode($q['meta'], true) : [];

    // Build choices array for client:
    $clientChoices = [];
    if ($q['type'] === 'mcq') {
        $clientChoices = array_map(
            fn($c) => [
                'id' => (int)$c['id'],
                'content' => $c['content'],
                'pos' => (int)$c['pos']
            ],
            $choicesMap[$q['id']] ?? []
        );
    } elseif ($q['type'] === 'truefalse') {
        // Provide consistent true/false choices for frontend.
        // ids are strings 'true' / 'false' to distinguish from numeric mcq ids.
        $clientChoices = [
            ['id' => 'true', 'content' => 'Sant', 'pos' => 1],
            ['id' => 'false', 'content' => 'Falskt', 'pos' => 2]
        ];
    }

    $clientQuestions[] = [
        'id' => (int)$q['id'],
        'type' => $q['type'],
        'content' => $q['content'],
        'pos' => (int)$q['pos'],
        'meta' => $meta,
        'choices' => $clientChoices
    ];
}
$bestStmt = $pdo->prepare("
    SELECT reward, score, elapsed_time 
    FROM attempt_sessions 
    WHERE user_id=? AND exercise_id=? AND finished_at IS NOT NULL 
    ORDER BY score DESC, elapsed_time ASC 
    LIMIT 1
");
$bestStmt->execute([$userId, $exerciseId]);
$bestAttempt = $bestStmt->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($exercise['title']); ?> — Öva</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
* {
    box-sizing: border-box;
}

body {
    background: #0f1724;
    color: #e6eef8;
    min-height: 100vh;
}

.container {
    max-width: 900px;
}

.card {
    background: rgba(255, 255, 255, 0.05);
    border: none;
    color: white;
    border-radius: 12px;
}

.btn-light {
    transition: all 0.2s;
}

.btn-light:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 255, 255, 0.2);
}

.btn-light.active {
    background: #ffc107;
    color: #000;
    border-color: #ffc107;
}

textarea.form-control {
    background: #1e293b;
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.1);
    resize: vertical;
    min-height: 100px;
}

textarea.form-control:focus {
    background: #1e293b;
    color: #fff;
    border-color: #ffc107;
    box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.25);
}

/* Progress container */
.progress-wrapper {
    position: relative;
    margin-top: 1.5rem;
}

.progress-container {
    background: #333;
    height: 28px;
    border-radius: 14px;
    position: relative;
    overflow: visible;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.3);
}

.progress-bar-custom {
    background: linear-gradient(90deg, #ffc107 0%, #ffb300 100%);
    height: 100%;
    border-radius: 14px;
    transition: width 0.5s ease;
    box-shadow: 0 2px 8px rgba(255, 193, 7, 0.4);
}

/* Markers */
.markers {
    position: absolute;
    top: 6px;
    left: 0;
    width: 100%;
    height: 16px;
    pointer-events: none;
    z-index: 2;
}

.marker {
    position: absolute;
    width: 16px;
    height: 16px;
    transform: translateX(-50%);
}

/* Torch */
.torch {
    position: absolute;
    top: -12px;
    width: 48px;
    height: 48px;
    transition: left 0.5s ease;
    pointer-events: none;
    z-index: 3;
    filter: drop-shadow(0 2px 8px rgba(255, 193, 7, 0.6));
}

/* Chest */
.chest {
    position: absolute;
    right: -16px;
    top: -8px;
    width: 40px;
    height: 40px;
    z-index: 3;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.4));
}

/* Question content styling */
#questionContent {
    font-size: 1.1rem;
    line-height: 1.6;
    padding: 1rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    border-left: 4px solid #ffc107;
}

#passageContent {
    white-space: pre-wrap;
    line-height: 1.8;
    padding: 1.5rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    max-height: 60vh;
    overflow-y: auto;
}

/* Answer buttons */
.answer-btn {
    text-align: left;
    padding: 1rem;
    margin-bottom: 0.75rem;
    transition: all 0.2s;
    border: 2px solid transparent;
}

.answer-btn:hover {
    transform: translateX(4px);
    border-color: rgba(255, 193, 7, 0.3);
}

.answer-btn.active {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
}

/* Score area */
#scoreArea {
    text-align: center;
}

#rewardImg {
    width: 80px;
    height: 80px;
    margin: 1rem auto;
    display: block;
    filter: drop-shadow(0 4px 8px rgba(255, 193, 7, 0.4));
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .container {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    h1 {
        font-size: 1.5rem;
    }
    
    .torch {
        width: 36px;
        height: 36px;
        top: -8px;
    }
    
    .chest {
        width: 32px;
        height: 32px;
        right: -12px;
        top: -6px;
    }
    
    .progress-container {
        height: 24px;
    }
    
    .marker {
        width: 14px;
        height: 14px;
    }
    
    #rewardImg {
        width: 64px;
        height: 64px;
    }
}

@media (max-width: 576px) {
    .torch {
        width: 28px;
        height: 28px;
        top: -6px;
    }
    
    .chest {
        width: 28px;
        height: 28px;
        right: -10px;
        top: -4px;
    }
    
    .progress-container {
        height: 20px;
    }
    
    .marker {
        width: 12px;
        height: 12px;
    }
    
    .markers {
        top: 4px;
    }
    
    #questionContent {
        font-size: 1rem;
        padding: 0.75rem;
    }
    
    .answer-btn {
        padding: 0.75rem;
    }
}

/* Loading state */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>
</head>
<body>
    <div class="timer-display" id="timerDisplay" style="display:none !important;">00:00</div>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><?php echo htmlspecialchars($exercise['title']); ?></h1>
    <a href="../exercises/entrance.php" class="btn btn-outline-light">← Tillbaka</a>
  </div>

  <div id="stage" class="card p-4">

    <!-- Passage Area -->
    <div id="passageArea">
      <h5 class="mb-3">Läs texten</h5>
      <div id="passageContent"><?php echo htmlspecialchars($passage['content'] ?? ''); ?></div>
      <button id="startBtn" class="btn btn-warning mt-3 w-100">Gå vidare till frågor →</button>
                          <div class="requirements-box mt-2 order-*">
                        <div class="mb-2"><strong>Din bästa prestation:</strong></div>
                        <?php if ($bestAttempt): ?>
                            <div>Belöning: <?php echo htmlspecialchars($bestAttempt['reward']); ?></div>
                            <div>Poäng: <?php echo (int)$bestAttempt['score']; ?></div>
                            <div>Tid: <?php echo htmlspecialchars($bestAttempt['elapsed_time']); ?>s</div>
                        <?php else: ?>
                            <div>Inga tidigare försök</div>
                        <?php endif; ?>
                    </div>
    </div>

    <!-- Question Area -->
    <div id="questionArea" style="display: none;">
      <div id="questionCounter" class="mb-3 text-muted small"></div>
      <div id="questionContent" class="mb-4"></div>
      <div id="answerArea" class="mb-4"></div>
      <button id="confirmBtn" class="btn btn-primary w-100">Bekräfta svar →</button>

      <div class="progress-wrapper">
        <div class="progress-container">
          <div id="progressBar" class="progress-bar-custom"></div>
          <div id="markers" class="markers"></div>
          <img id="progressMarker" class="torch" src="../assets/img/torch.png" alt="Progress">
          <img class="chest" src="../assets/img/chest.png" alt="Goal">
        </div>
      </div>
    </div>

    <!-- Score Area -->
    <div id="scoreArea" style="display: none;">
                        <div id="scoreResult" class="text-center"></div>
                        <img id="rewardImg" src="" alt="" style="display:none;">
                        <div id="levelBanner" class="level-up-banner" style="display:none;"></div>
      
      <div class="progress-wrapper">
        <div class="progress-container">
          <div id="scoreProgressBar" class="progress-bar-custom"></div>
          <div id="scoreMarkers" class="markers"></div>
          <img class="chest" src="../assets/img/chest.png" alt="Goal">
        </div>
      </div>
      
      <a href="../exercises/entrance.php" class="btn btn-primary mt-4 w-100">Tillbaka till uppdrag</a>
    </div>

  </div>
</div>

<script>
const userId = <?php echo (int)$userId; ?>;
const exerciseId = <?php echo (int)$exerciseId; ?>;
const questions = <?php echo json_encode($clientQuestions, JSON_UNESCAPED_UNICODE); ?>;

let currentQuestionIndex = 0;
let correctness = [];

// DOM elements
const passageArea = document.getElementById('passageArea');
const startBtn = document.getElementById('startBtn');
const questionArea = document.getElementById('questionArea');
const questionCounter = document.getElementById('questionCounter');
const questionContent = document.getElementById('questionContent');
const answerArea = document.getElementById('answerArea');
const confirmBtn = document.getElementById('confirmBtn');
            const timerDisplay = document.getElementById('timerDisplay');
const scoreArea = document.getElementById('scoreArea');
            const scoreResult = document.getElementById('scoreResult');
const scoreText = document.getElementById('scoreText');
const rewardText = document.getElementById('rewardText');
const rewardImg = document.getElementById('rewardImg');

const progressBar = document.getElementById('progressBar');
const progressMarker = document.getElementById('progressMarker');
const markersDiv = document.getElementById('markers');

const scoreProgressBar = document.getElementById('scoreProgressBar');
const scoreMarkersDiv = document.getElementById('scoreMarkers');


            let attemptId = 0;
            let current = 0;
            let answersCache = {}; // questionId -> answer payload
            let startedAt = null;
            let timerInterval = null;

            function formatTime(sec) {
                const m = Math.floor(sec / 60).toString().padStart(2, '0');
                const s = (sec % 60).toString().padStart(2, '0');
                return `${m}:${s}`;
            }

            function startTimer() {
                startedAt = Date.now();
                //timerDisplay.style.display = 'block';
                timerDisplay.style.display = 'none';
                timerInterval = setInterval(() => {
                    const diff = Math.floor((Date.now() - startedAt) / 1000);
                    timerDisplay.textContent = formatTime(diff);
                }, 1000);
            }

            function stopTimer() {
                clearInterval(timerInterval);
                timerInterval = null;
            }




function updateProgress(showTorch = true, bar = progressBar, markerDiv = markersDiv, torchElement = progressMarker) {
    const percent = (correctness.length / questions.length) * 100;
    bar.style.width = percent + '%';
    
    if (showTorch && torchElement) {
        const container = bar.parentElement;
        const containerWidth = container.offsetWidth;
        const torchWidth = torchElement.offsetWidth;
        const position = Math.max(0, Math.min(
            containerWidth - torchWidth,
            (containerWidth * percent / 100) - (torchWidth / 2)
        ));
        torchElement.style.left = position + 'px';
    }
    
    markerDiv.innerHTML = '';
    correctness.forEach((isCorrect, index) => {
        const marker = document.createElement('img');
        marker.src = isCorrect ? '../assets/img/check.png' : '../assets/img/cross.png';
        marker.className = 'marker';
        marker.alt = isCorrect ? 'Correct' : 'Incorrect';
        const position = (index / questions.length) * 100;
        marker.style.left = position + '%';
        markerDiv.appendChild(marker);
    });
}

async function apiCall(action, data = {}) {
    const response = await fetch(
        `${location.pathname}?exercise_id=${exerciseId}`,
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...data })
        }
    );
    
    if (!response.ok) {
        const error = await response.json();
        throw new Error(error.error || 'Request failed');
    }
    
    return response.json();
}

async function startAttempt() {
    try {
        startBtn.disabled = true;
        const result = await apiCall('start_attempt');
        
        if (!result.success) {
            alert('Kunde inte starta övningen');
            return;
        }
        
        attemptId = result.attempt_id;
        passageArea.style.display = 'none';
        questionArea.style.display = 'block';
        renderQuestion();
        startTimer();
    } catch (error) {
        console.error('Error starting attempt:', error);
        alert('Ett fel uppstod. Försök igen.');
        startBtn.disabled = false;
    }
}

function renderQuestion() {
    const question = questions[currentQuestionIndex];
    questionCounter.textContent = `Fråga ${currentQuestionIndex + 1} av ${questions.length}`;
    questionContent.textContent = question.content;
    answerArea.innerHTML = '';

    if (question.type === 'mcq') {
        question.choices.forEach(choice => {
            const button = document.createElement('button');
            button.className = 'btn btn-light answer-btn w-100';
            button.textContent = choice.content;
            // mcq ids are numeric
            button.dataset.choiceId = choice.id;
            button.addEventListener('click', () => selectChoice(button));
            answerArea.appendChild(button);
        });
    } else if (question.type === 'truefalse') {
        // choices from server are id='true'/'false' with content Sant/Falskt
        question.choices.forEach(choice => {
            const button = document.createElement('button');
            button.className = 'btn btn-light answer-btn w-100';
            button.textContent = choice.content;
            // keep choice id as string 'true' or 'false'
            button.dataset.choiceId = String(choice.id);
            button.addEventListener('click', () => selectChoice(button));
            answerArea.appendChild(button);
        });
    } else {
        const textarea = document.createElement('textarea');
        textarea.className = 'form-control';
        textarea.placeholder = 'Skriv ditt svar här...';
        answerArea.appendChild(textarea);
    }

    updateProgress(true);
}

function selectChoice(button) {
    answerArea.querySelectorAll('button').forEach(btn =>
        btn.classList.remove('active')
    );
    button.classList.add('active');
}

async function confirmAnswer() {
    const question = questions[currentQuestionIndex];
    let answerObj = null;

    if (question.type === 'mcq') {
        const selected = answerArea.querySelector('button.active');
        if (!selected) {
            alert('Välj ett svar först');
            return;
        }
        answerObj = { choice_id: parseInt(selected.dataset.choiceId, 10) };
    } else if (question.type === 'truefalse') {
        const selected = answerArea.querySelector('button.active');
        if (!selected) {
            alert('Välj ett svar först');
            return;
        }
        // keep string 'true' or 'false' — backend normalizes
        answerObj = { choice_id: selected.dataset.choiceId };
    } else {
        const textarea = answerArea.querySelector('textarea');
        answerObj = { text: textarea.value.trim() };
        if (!answerObj.text) {
            alert('Skriv ett svar först');
            return;
        }
    }

    try {
        confirmBtn.disabled = true;
        const result = await apiCall('submit_answer', {
            attempt_id: attemptId,
            question_id: question.id,
            answer: answerObj
        });

        correctness = result.answers || correctness;
        currentQuestionIndex++;

        if (currentQuestionIndex < questions.length) {
            renderQuestion();
        } else {
            await finishAttempt();
        }
    } catch (error) {
        console.error('Error submitting answer:', error);
        alert('Ett fel uppstod. Försök igen.');
    } finally {
        confirmBtn.disabled = false;
    }
}

    async function finishAttempt() {
    if (!attemptId) return alert('Inget aktivt försök.');
    stopTimer();
    const elapsed = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
    
    try {
        const res = await fetch(`?exercise_id=${exerciseId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'finish_attempt',
                attempt_id: attemptId,
                elapsed_time: elapsed
            })
        });
        
        const json = await res.json();
        
        if (json.success) {
            // Update correctness array
            correctness = json.answers || correctness;
            
            // Hide question area, show score area
            document.getElementById('questionArea').style.display = 'none';
            scoreArea.style.display = 'block';


            // Show final progress bar in score area
            const finalScoreProgressBar = document.getElementById('scoreProgressBar');
            const finalScoreMarkersDiv = document.getElementById('scoreMarkers');
            
            if (finalScoreProgressBar && finalScoreMarkersDiv) {
                // Show progress at 90% first
                updateProgress(false, finalScoreProgressBar, finalScoreMarkersDiv, null);
                
                // Then animate final sprint to 100%
                setTimeout(() => {
                    finalScoreProgressBar.style.width = '100%';
                }, 100);
            }
            // Determine XP message
            if (json.xp_earned > 0) {
                // Normal case: XP earned
                scoreResult.innerHTML = `
                    <h3>Du fick ${json.xp_earned} XP</h3>
                    <div>Bas: ${json.base_xp} | Bonus: ${json.bonus_xp}</div>
                    <div>Belöning: ${json.reward} – ${json.percentage}%</div>
                `;
                rewardImg.src = '../assets/img/' + json.reward + '.png';
                rewardImg.style.display = 'block';
            } else {
                // XP earned = 0
                if (json.maxed_out) {
                    // Already reached max XP for this exercise
                    scoreResult.innerHTML = `
                        <h3>Du tjänade 0 XP!</h3>
                        <h4>Du har redan tjänat allt du kan från denna uppgift.</h4>
                        <div>Bas: ${json.base_xp} | Bonus: ${json.bonus_xp}</div>
                        <div>Belöning: ${json.reward} – ${json.percentage}%</div>
                    `;
                } else {
                    // Actually failed this attempt
                    scoreResult.innerHTML = `
                        <h3>Tyvärr, du fick 0 XP denna gång</h3>
                        <h4>Försök igen för att tjäna XP.</h4>
                        <div>Belöning: ${json.reward} – ${json.percentage}%</div>
                    `;
                }
                rewardImg.style.display = 'none';
            }

            // Level up banner
            if (json.leveled_up) {
                if (typeof soundManager !== 'undefined') {
                    soundManager.play('levelup');
                }
                levelBanner.style.display = 'block';
                levelBanner.textContent = `Grattis! Du gick upp till nivå ${json.new_level}`;
            } else {
                levelBanner.style.display = 'none';
            }

            // Show final progress bar in score area
            const scoreProgressBar = document.getElementById('scoreProgressBar');
            const scoreMarkersDiv = document.getElementById('scoreMarkers');
            if (scoreProgressBar && scoreMarkersDiv) {
                updateProgress(false, scoreProgressBar, scoreMarkersDiv, null);
            }
            
        } else {
            alert('Misslyckades avsluta försök: ' + (json.error || 'okänt'));
        }
    } catch (e) {
        console.error(e);
        alert('Serverfel vid avslut');
    }
}


startBtn.addEventListener('click', startAttempt);
confirmBtn.addEventListener('click', confirmAnswer);

window.addEventListener('resize', () => {
    if (progressMarker.style.display !== 'none') {
        updateProgress(true, progressBar, markersDiv, progressMarker);
    }
});
</script>
</body>
</html>
