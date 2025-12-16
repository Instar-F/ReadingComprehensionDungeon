<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../BadgeManager.php';
require_once __DIR__ . '/../LevelManager.php';

if (!is_logged_in()) {
    header('Location: ../auth/signin.php');
    exit;
}

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

            
            $computeOrderingScore = function(array $userOrder, array $correctOrder, array $weights, array $options) {
                $totalItems = count($correctOrder);
                $result = [
                    'order_accuracy' => 0.0,
                    'inversions' => 0,
                    'max_inversions' => 0,
                    'offs_sum' => 0,
                    'offs_norm' => 0.0,
                    'present_ratio' => 0.0,
                    'final_ratio' => 0.0,
                    'exact_position_ids' => []
                ];
                if ($totalItems === 0) return $result;

                $setCorrect = array_flip($correctOrder);
                $normalized = [];
                $seen = [];
                foreach ($userOrder as $id) {
                    $id = (int)$id;
                    if (isset($setCorrect[$id]) && !isset($seen[$id])) {
                        $normalized[] = $id;
                        $seen[$id] = true;
                    }
                }
                $presentCount = count($normalized);
                $result['present_ratio'] = $totalItems > 0 ? ($presentCount / $totalItems) : 0.0;

                // If empty after normalization, return zeros
                if ($presentCount === 0) {
                    return $result;
                }

                // Perfect override: if user order exactly equals correct order and lengths match
                if ($presentCount === $totalItems) {
                    $isExact = true;
                    for ($i = 0; $i < $totalItems; $i++) {
                        if ((int)$userOrder[$i] !== (int)$correctOrder[$i]) { $isExact = false; break; }
                    }
                    if ($isExact) {
                        $result['order_accuracy'] = 1.0;
                        $result['inversions'] = 0;
                        $result['max_inversions'] = max(0, intdiv($totalItems * ($totalItems - 1), 2));
                        $result['offs_sum'] = 0;
                        $result['offs_norm'] = 0.0;
                        $result['final_ratio'] = 1.0;
                        $result['exact_position_ids'] = $correctOrder;
                        return $result;
                    }
                }

                
                // Inversion count on the present sequence relative to correct positions
                $presentPos = array_map(function($id) use ($setCorrect) { return $setCorrect[$id]; }, $normalized);
                $n = count($presentPos);
                $maxInv = max(0, intdiv($n * ($n - 1), 2));
                $inv = 0;
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if ($presentPos[$i] > $presentPos[$j]) $inv++;
                    }
                }

                // Offs sum: build a full sequence by appending any missing items to the end for displacement calc
                $normalizedFull = $normalized;
                foreach ($correctOrder as $id) {
                    if (!isset($seen[$id])) $normalizedFull[] = $id;
                }
                $sumDisp = 0;
                $maxDisp = 0;
                foreach ($normalizedFull as $i => $id) {
                    $correctPos = $setCorrect[$id];
                    $sumDisp += abs($i - $correctPos);
                    $maxDisp += max($i, $totalItems - 1 - $i);
                }
                $offsNorm = $maxDisp > 0 ? ($sumDisp / $maxDisp) : 0.0;

                // Exact position ids for feedback
                $exactIds = [];
                $limit = min($totalItems, count($normalizedFull));
                for ($i = 0; $i < $limit; $i++) {
                    if ($correctOrder[$i] === $normalizedFull[$i]) $exactIds[] = (int)$normalizedFull[$i];
                }

                // Build per-item offs list based on normalizedFull
                $perItems = [];
                $limit = min($totalItems, count($normalizedFull));
                $offsCount = 0;
                for ($i = 0; $i < $limit; $i++) {
                    $id = (int)$normalizedFull[$i];
                    $correctPos = (int)$setCorrect[$id];
                    $offs = abs($i - $correctPos);
                    if ($offs > 0) $offsCount++;
                    $perItems[] = [
                        'id' => $id,
                        'current_pos' => $i,
                        'correct_pos' => $correctPos,
                        'offs' => $offs
                    ];
                }


                // Options and defaults for blended scoring and softening
                $nearThreshold = (int)($options['near_threshold'] ?? 1);
                $farThreshold  = (int)($options['far_threshold'] ?? 3);
                $softFactor = (float)($options['near_soft_factor'] ?? 0.5); // reduce penalty when exactly one far-off item
                $weightInversion = (float)($options['weight_inversion'] ?? 0.5);
                $weightOffs = (float)($options['weight_offs'] ?? 0.5);
                $weightExactPositions = (float)($options['weight_exact_positions'] ?? 0.5); // new weight: exact positions count
                $accuracyAlpha = (float)($options['accuracy_alpha'] ?? 0.9);
                // Clamp sensible ranges
                $softFactor = max(0.0, min(1.0, $softFactor));
                $weightInversion = max(0.0, min(1.0, $weightInversion));
                $weightOffs = max(0.0, min(1.0, $weightOffs));
                $weightExactPositions = max(0.0, min(1.0, $weightExactPositions));

                // Normalize to sum 1 across the three components
                $sumW = $weightInversion + $weightOffs + $weightExactPositions;
                if ($sumW > 0) {
                    $weightInversion /= $sumW;
                    $weightOffs /= $sumW;
                    $weightExactPositions /= $sumW;
                } else {
                    $weightInversion = 1.0; $weightOffs = 0.0; $weightExactPositions = 0.0;
                }

                $accuracyAlpha = max(0.5, min(2.0, $accuracyAlpha));

                // Near-perfect softening: if exactly one far-off item and others near or exact
                $farCount = 0; $nearOrExactCount = 0;
                foreach ($perItems as $pi) {
                    if ($pi['offs'] >= $farThreshold) $farCount++;
                    elseif ($pi['offs'] <= $nearThreshold) $nearOrExactCount++;
                }
                $invEff = $inv;
                if ($farCount === 1 && ($nearOrExactCount >= ($totalItems - 1))) {
                    $invEff = (int)round($inv * $softFactor);
                }

                // Order accuracy from effective inversions
                $orderAcc = ($maxInv > 0) ? (1.0 - ($invEff / $maxInv)) : 1.0; // if n<2, treat as fully ordered

                // Exact positions ratio and offs percentage
                $exactPositionsCount = count($exactIds);
                $exactPositionsRatio = $totalItems > 0 ? ($exactPositionsCount / $totalItems) : 0.0;
                $offsPct = $totalItems > 0 ? ($offsCount / $totalItems) : 0.0;

                // Blended base using inversions, offs displacement and exact-position ratio
                $base = ($weightInversion * $orderAcc) + ($weightOffs * (1.0 - $offsNorm)) + ($weightExactPositions * $exactPositionsRatio);
                // Presence scaling
                $base *= $result['present_ratio'];
                // Gentle non-linear mapping
                $final = max(0.0, min(1.0, pow(max(0.0, min(1.0, $base)), $accuracyAlpha)));

                $result['order_accuracy'] = $orderAcc;
                $result['inversions'] = $invEff;
                $result['max_inversions'] = $maxInv;
                $result['offs_sum'] = $sumDisp;
                $result['offs_norm'] = $offsNorm;
                $result['exact_positions_count'] = $exactPositionsCount;
                $result['exact_positions_ratio'] = $exactPositionsRatio;
                $result['offs_count'] = $offsCount;
                $result['offs_pct'] = $offsPct;
                $result['final_ratio'] = $final;
                $result['exact_position_ids'] = $exactIds;

                $result['per_item'] = $perItems;

                return $result;
            };

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
                    // True/false now works like MCQ - check against question_choices
                    $choiceId = isset($userAnswer['choice_id']) ? (int)$userAnswer['choice_id'] : 0;
                    if ($choiceId) {
                        $chkStmt = $pdo->prepare("SELECT is_correct FROM question_choices WHERE id=? AND question_id=?");
                        $chkStmt->execute([$choiceId, $questionId]);
                        $row = $chkStmt->fetch(PDO::FETCH_ASSOC);
                        $isCorrect = $row ? (int)$row['is_correct'] : 0;
                    } else {
                        $isCorrect = 0;
                    }
                    $awardedPoints = $isCorrect ? $pointsForQuestion : 0;
                    break;

                case 'fillblank':
                    // Fillblank now works like MCQ - user selects a choice_id
                    $choiceId = isset($userAnswer['choice_id']) ? (int)$userAnswer['choice_id'] : 0;
                    if ($choiceId) {
                        $chkStmt = $pdo->prepare("SELECT is_correct FROM question_choices WHERE id=? AND question_id=?");
                        $chkStmt->execute([$choiceId, $questionId]);
                        $row = $chkStmt->fetch(PDO::FETCH_ASSOC);
                        $isCorrect = $row ? (int)$row['is_correct'] : 0;
                    } else {
                        $isCorrect = 0;
                    }
                    $awardedPoints = $isCorrect ? $pointsForQuestion : 0;
                    break;

                    
                case 'ordering':
                    $order = $userAnswer['order'] ?? [];

                    // Get correct order from ordering_items table
                    $orderStmt = $pdo->prepare("SELECT id FROM ordering_items WHERE question_id=? ORDER BY correct_pos ASC");
                    $orderStmt->execute([$questionId]);
                    $correctOrder = array_column($orderStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

                    $totalItems = count($correctOrder);

                    if ($totalItems > 0) {
                        // Read optional per-question weights/options from meta
                        $weights = [];
                        $options = [];
                        if (isset($meta['ordering_scoring_weights']) && is_array($meta['ordering_scoring_weights'])) {
                            $weights = $meta['ordering_scoring_weights'];
                        }
                        if (isset($meta['ordering_scoring_options']) && is_array($meta['ordering_scoring_options'])) {
                            $options = $meta['ordering_scoring_options'];
                        }

                        // Compute enhanced scoring breakdown
                        $breakdown = $computeOrderingScore($order, $correctOrder, $weights, $options);
                        $finalRatio = (float)$breakdown['final_ratio'];
                        $awardedPoints = (int)round($pointsForQuestion * $finalRatio);
                        $isCorrect = ($finalRatio >= 0.999 && $order === $correctOrder) ? 1 : 0;

                        // If exact order, override points to full and mark as correct
                        $isExactOrder = (count($order) === count($correctOrder)) && ($order === $correctOrder);
                        if ($isExactOrder) {
                            $awardedPoints = (int)$pointsForQuestion;
                            $isCorrect = 1;
                        }

                    } else {
                        $awardedPoints = 0;
                        $isCorrect = 0;
                        $breakdown = [
                            'exact_positions_ratio' => 0,
                            'relative_order_ratio' => 0,
                            'adjacent_pairs_ratio' => 0,
                            'displacement_norm' => 1,
                            'missing_ratio' => 1,
                            'final_ratio' => 0,
                            'exact_position_ids' => []
                        ];
                    }
                    break;



                case 'matching':
                    $pairs = $userAnswer['pairs'] ?? [];
                    $matchStmt = $pdo->prepare("SELECT left_index, right_index FROM matching_pairs WHERE question_id=?");
                    $matchStmt->execute([$questionId]);
                    $correctPairs = [];
                    foreach ($matchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $correctPairs[$row['left_index']] = $row['right_index'];
                    }

                    $ok = true;
                    foreach ($correctPairs as $l => $r) {
                        if (!isset($pairs[$l]) || (int)$pairs[$l] !== (int)$r) {
                            $ok = false;
                            break;
                        }
                    }
                    $isCorrect = $ok ? 1 : 0;
                    $awardedPoints = $isCorrect ? $pointsForQuestion : 0;
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
                'answers' => $answers,
                'breakdown' => isset($breakdown) ? $breakdown : null
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

            // Get exercise info for metadata
            $exStmt = $pdo->prepare("SELECT * FROM exercises WHERE id=?");
            $exStmt->execute([$exerciseId]);
            $exercise = $exStmt->fetch(PDO::FETCH_ASSOC);

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

            // Count how many questions were answered correctly
            $correctQ = $pdo->prepare("SELECT COUNT(*) AS correct_count FROM attempt_answers WHERE attempt_id=? AND correct=1");
            $correctQ->execute([$attemptId]);
            $correctCount = (int)$correctQ->fetch(PDO::FETCH_ASSOC)['correct_count'];

            // Count total number of questions in the exercise
            $totalQ = $pdo->prepare("SELECT COUNT(*) AS total_count FROM questions WHERE exercise_id=?");
            $totalQ->execute([$exerciseId]);
            $totalQuestions = (int)$totalQ->fetch(PDO::FETCH_ASSOC)['total_count'];

            // Calculate percentage based on correct vs total questions
            $percentage = ($totalQuestions > 0) ? ($correctCount / $totalQuestions) * 100 : 0;
            $EXPpercentage = ($totalPossiblePoints > 0) ? ($totalPoints / $totalPossiblePoints) * 100 : 0;
            // Use EXP percentage as the official percentage for rewards/display.
            $useExpPercentage = true; // EXP-based percentage is now the system default
            $usedPercentage = $EXPpercentage;

            // Determine reward based on percentage
            $reward = 'coal';
            if ($usedPercentage >= 70) $reward = 'copper';
            if ($usedPercentage >= 80) $reward = 'iron';
            if ($usedPercentage >= 90) $reward = 'gold';
            if ($usedPercentage >= 100) $reward = 'diamond';

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
                case 'copper':
                    $bonusXP = (int)($baseXP * 0.4);
                    break;
                case 'iron':
                    $bonusXP = (int)($baseXP * 0.6);
                    break;
                case 'gold':
                    $bonusXP = (int)($baseXP * 0.8);
                    break;
                case 'diamond':
                    $bonusXP = (int)($baseXP * 1.0);
                    break;
                case 'emerald':
                    $bonusXP = (int)($baseXP * 1.5);
                    break;
            }

            $finalXP = $baseXP + $bonusXP;

            // Calculate incremental XP
            $bestStmt = $pdo->prepare("SELECT MAX(score) as max_score FROM attempt_sessions WHERE user_id=? AND exercise_id=?");
            $bestStmt->execute([$userId, $exerciseId]);
            $prevBest = (int)($bestStmt->fetch(PDO::FETCH_ASSOC)['max_score'] ?? 0);

            $incrementalXP = max(0, $finalXP - $prevBest);
            $earnedMax = ($incrementalXP === 0 && $finalXP === $prevBest);

            // Update attempt session
            $upd = $pdo->prepare("UPDATE attempt_sessions SET finished_at=NOW(), score=?, reward=?, elapsed_time=? WHERE id=?");
            $upd->execute([$finalXP, $reward, $elapsedTime, $attemptId]);


            $pdo->commit();  // Commit transaction first

            // Award XP and handle leveling
            $levelManager = new LevelManager($pdo, $userId);
            $levelResult = $levelManager->awardXP($incrementalXP, 'exercise');

            $newPoints = $levelResult['new_points'];
            $newLevel = $levelResult['new_level'];
            $leveledUp = $levelResult['leveled_up'];
    // ✅ BADGE SYSTEM: Check badges AFTER transaction commits
    try {
        $badgeManager = new BadgeManager($pdo, $userId);
        $badgeManager->updateExerciseProgress($exerciseId, $finalXP, $reward);
        $newBadges = $badgeManager->checkAndAwardBadges();
        
        if (!empty($newBadges)) {
            error_log("Awarded " . count($newBadges) . " new badges to user $userId");
            foreach ($newBadges as $badge) {
                error_log("  - {$badge['title']}: +{$badge['points_reward']} XP");
            }
        }
        
        // Refresh user data to get updated points
        $user = current_user($pdo);
        $newPoints = (int)$user['points'];  // Update with badge XP
    } catch (Exception $e) {
        error_log("Badge system error: " . $e->getMessage());
    }
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
                'percentage' => round($usedPercentage, 1),
                'EXPpercentage' => round($EXPpercentage, 1),
                'percentage_type' => 'exp',
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
$orderingItemsMap = [];

if (!empty($questionIds)) {
    $in = implode(',', array_fill(0, count($questionIds), '?'));
    $cstm = $pdo->prepare("SELECT * FROM question_choices WHERE question_id IN ($in) ORDER BY question_id, pos ASC, id ASC");
    $cstm->execute($questionIds);
    $allChoices = $cstm->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allChoices as $c) {
        $choicesMap[$c['question_id']][] = $c;
    }
    
    // Fetch ordering items
    $oStmt = $pdo->prepare("SELECT * FROM ordering_items WHERE question_id IN ($in) ORDER BY question_id, correct_pos ASC");
    $oStmt->execute($questionIds);
    $allOrderingItems = $oStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allOrderingItems as $item) {
        $orderingItemsMap[$item['question_id']][] = $item;
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
        // True/false now uses choices from database like MCQ
        $clientChoices = array_map(
            fn($c) => [
                'id' => (int)$c['id'],
                'content' => $c['content'],
                'pos' => (int)$c['pos']
            ],
            $choicesMap[$q['id']] ?? []
        );
    } elseif ($q['type'] === 'fillblank') {
        // Fill-in-the-blank uses choices just like MCQ
        $clientChoices = array_map(
            fn($c) => [
                'id' => (int)$c['id'],
                'content' => $c['content'],
                'pos' => (int)$c['pos']
            ],
            $choicesMap[$q['id']] ?? []
        );
    } elseif ($q['type'] === 'ordering') {
        // Pass ordering items to frontend
        $items = $orderingItemsMap[$q['id']] ?? [];
        $meta['items'] = array_map(
            fn($item) => [
                'id' => (int)$item['id'],
                'content' => $item['content']
            ],
            $items
        );
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

// Detect if this exercise is purely ordering (every question is type 'ordering')
$isSortingExercise = false;
if (!empty($clientQuestions)) {
    $isSortingExercise = true;
    foreach ($clientQuestions as $cq) {
        if (($cq['type'] ?? '') !== 'ordering') { $isSortingExercise = false; break; }
    }
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
  <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/achievement-notifications.css">
</head>
<body class="dirt-bg">
<main class="dirt-bg">

<div class="timer-display" id="timerDisplay" style="display:none !important;">00:00</div>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="gametext mb-0"><?php echo htmlspecialchars($exercise['title']); ?></h1>
    <a href="../exercises/entrance.php" class="btn btn-outline-light">← Tillbaka</a>
  </div>
    <div id="stage" class="card p-4">

    <div id="passageArea">
        <h5 class="mb-3 gametext-small">Läs texten</h5>
        <div id="passageContent"><?php echo htmlspecialchars($passage['content'] ?? ''); ?></div>
        <button id="startBtn" class="btn game-btn mt-3 w-100 gametext-small">Gå vidare till frågor</button>
            <div class="mt-2 order-*">
             <div class="mb-2 gametext-small"><strong>Din bästa prestation:</strong></div>
                <?php if ($bestAttempt): ?>
                <div>Belöning: <?php echo htmlspecialchars($bestAttempt['reward']); ?></div>
                    <div>Poäng: <?php echo (int)$bestAttempt['score']; ?></div>
                    <div>Tid: <?php echo htmlspecialchars($bestAttempt['elapsed_time']); ?>s</div>
                <?php else: ?>
                    <div gametext-small>Inga tidigare försök</div>
                <?php endif; ?>
            </div>
            <div class="mt-2 text-center ">
                    <strong class="gametext-small">Belöningskrav:</strong>
                <div class="mt-3">
                    <div class="req-item">
                            <img src="../assets/img/copper.png" class="req-icon"> 70% rätt
                    </div>
                    <div class="req-item">
                        <img src="../assets/img/iron.png" class="req-icon"> 80% rätt
                    </div>
                    <div class="req-item">
                        <img src="../assets/img/gold.png" class="req-icon"> 90% rätt
                    </div>
                    <div class="req-item">
                        <img src="../assets/img/diamond.png" class="req-icon"> 100% rätt
                    </div>
                    
                    <?php
                    $metadata = json_decode($exercise['metadata'] ?? '{}', true);
                    $emeraldSeconds = isset($metadata['time_limit']) ? (int)$metadata['time_limit'] : 60;
                    ?>
                </div>
                    <div class="req-item-emerald">
                        <img src="../assets/img/emerald.png" class="req-icon">
                        100% rätt + under <?= $emeraldSeconds ?> sekunder
                    </div>
            </div>
        </div>

    <!-- Question Area -->
    <div id="questionArea" style="display:none;">
      <div id="questionCounter" class="mb-3 small"></div>
      <div id="questionContent" class="mb-4"></div>
      <div id="answerArea" class="mb-4"></div>

      <button id="confirmBtn" class="btn game-btn w-100 mb-4 gametext-small">Bekräfta svar</button>

      <!-- Progress Bar -->
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
    <div id="scoreArea" style="display:none;">
      <div id="scoreResult" class="text-center"></div>
      <img id="rewardImg" src="" alt="" style="display:none;">
      <div id="levelBanner" class="level-up-banner" style="display:none;"></div>

      <!-- End Progress Bar -->
      <div class="progress-wrapper mt-3">
        <div class="progress-container">
          <div id="scoreProgressBar" class="progress-bar-custom"></div>
          <div id="scoreMarkers" class="markers"></div>
          <img class="chest" src="../assets/img/chest.png" alt="Goal">
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="d-flex flex-column align-items-center gap-2 mt-4">
        <button id="retryBtn" class="btn game-btn-again w-100 gametext-small">Försök igen</button>
        <a href="../exercises/entrance.php" class="btn game-btn w-100 gametext-small">Tillbaka till uppdrag</a>
      </div>
    </div>

  </div>
</div>

<script>
const userId = <?php echo (int)$userId; ?>;
const exerciseId = <?php echo (int)$exerciseId; ?>;
const questions = <?php echo json_encode($clientQuestions, JSON_UNESCAPED_UNICODE); ?>;
const isSortingExercise = <?php echo ($isSortingExercise ? 'true' : 'false'); ?>; // server-detected "pure ordering" flag
let orderingResults = {}; // store per-question breakdowns and awarded points

// If this is a purely ordering exercise, hide progress bars (not meaningful for pure order tasks)
if (isSortingExercise) {
    document.querySelectorAll('.progress-wrapper').forEach(el => el.style.display = 'none');
}

// Shuffle questions to randomize order
function shuffleArray(array) {
    for (let i = array.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [array[i], array[j]] = [array[j], array[i]];
    }
    return array;
}
shuffleArray(questions);

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
const rewardImg = document.getElementById('rewardImg');
const progressBar = document.getElementById('progressBar');
const progressMarker = document.getElementById('progressMarker');
const markersDiv = document.getElementById('markers');
const scoreProgressBar = document.getElementById('scoreProgressBar');
const scoreMarkersDiv = document.getElementById('scoreMarkers');
const retryBtn = document.getElementById('retryBtn');
const levelBanner = document.getElementById('levelBanner');

let attemptId = 0;
let startedAt = null;
let timerInterval = null;

let waitingToAdvance = false; // for ordering: stay on results until next press

function formatTime(sec) {
    const m = Math.floor(sec / 60).toString().padStart(2, '0');
    const s = (sec % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

function startTimer() {
    startedAt = Date.now();
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


function showOrderingFeedback(breakdown, question) {
    const list = document.getElementById('orderingList');
    if (!list || !breakdown) return;

    // Compute correct order from meta items (items are provided in correct_pos order)
    const correctOrderIds = (question.meta.items || []).map(it => parseInt(it.id, 10));
    const children = Array.from(list.children);

    // Mark items that are currently in their exact correct slots

    const showHints = (question.meta && (question.meta.show_item_hints !== false));
    const perItem = Array.isArray(breakdown.per_item) ? breakdown.per_item : [];

    // Reset classes and badges
    children.forEach(el => {
        el.classList.remove('correct-pos','near-pos','far-pos');
        const oldBadge = el.querySelector('.ordering-hint-badge');
        if (oldBadge) oldBadge.remove();
    });

    // Apply statuses
    children.forEach((el, idx) => {
        const id = parseInt(el.dataset.id, 10);
        const detail = perItem.find(p => p.id === id);
        const offs = detail ? (detail.offs || 0) : (correctOrderIds[idx] === id ? 0 : 999);
        const correctPos = detail ? detail.correct_pos : null;
        if (correctOrderIds[idx] === id) {
            el.classList.add('correct-pos');
        } else if (offs === 1) {
            el.classList.add('near-pos');
        } else if (offs >= 2) {
            el.classList.add('far-pos');
        }
        if (showHints && Number.isInteger(offs) && correctPos !== null) {
            const badge = document.createElement('span');
            badge.className = 'ordering-hint-badge';
            badge.textContent = `offs:${offs} → ${correctPos+1}`; // show 1-based target
            el.appendChild(badge);
        }
    });

    children.forEach((el, idx) => {
        const id = parseInt(el.dataset.id, 10);
        if (correctOrderIds[idx] === id) {
            el.classList.add('correct-pos');
        } else {
            el.classList.remove('correct-pos');
        }
    });

    // Show a small breakdown panel
    let panel = document.getElementById('orderingFeedback');
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'orderingFeedback';
        panel.className = 'mt-3';
        answerArea.appendChild(panel);
    }
    const pct = v => Math.round((v || 0) * 100);
    const exactCount = breakdown.exact_positions_count ?? (Array.isArray(breakdown.exact_position_ids) ? (breakdown.exact_position_ids.length) : 0);
    panel.innerHTML = `
        <div class="small">
            <strong>Poängberäkning:</strong>
            <div>Ordningens noggrannhet: ${pct(breakdown.order_accuracy)}%</div>
            <div>Exakta positioner: ${pct(breakdown.exact_positions_ratio)}% (${exactCount}/${(question.meta.items||[]).length})</div>
            <div>Felplacerade element: ${pct(breakdown.offs_pct)}%</div>
            <div>Inversioner: ${breakdown.inversions} / ${breakdown.max_inversions}</div>
            <div>Offs (normaliserat): ${pct(breakdown.offs_norm)}%</div>
        </div>
    `;
}

// Render a friendly, detailed "Poängberäkning" on the results screen for pure ordering exercises
function renderPoangberakning(json) {
    const containerId = 'poangCalculation';
    let container = document.getElementById(containerId);
    if (!container) {
        container = document.createElement('div');
        container.id = containerId;
        container.className = 'point-breakdown mt-4 p-3 bg-dark bg-opacity-10 rounded';
        scoreArea.appendChild(container);
    }

    let html = `<h4>Poängberäkning</h4>`;
    html += `<div class="small">Här ser du hur poängen räknades fram för varje fråga och hur slutpoängen beräknades.</div>`;
    html += `<div class="mt-2">`;

    let totalAwarded = 0;
    let totalPossible = 0;

    questions.forEach((q, idx) => {
        if (q.type !== 'ordering') return;
        const qNum = idx + 1;
        const r = orderingResults[q.id];
        const pointsPossible = (q.meta && q.meta.points) ? q.meta.points : 10;
        const awarded = r ? (r.points_awarded || 0) : 0;
        totalAwarded += awarded;
        totalPossible += pointsPossible;

        if (r && r.breakdown) {
            const b = r.breakdown;
            const finalRatio = (b.final_ratio || 0);
            html += `<div class="mb-3">`;
            html += `<strong>Fråga ${qNum}:</strong> ${q.content ? q.content : ''}<br>`;
            html += `Ordningens noggrannhet: ${Math.round((b.order_accuracy||0)*100)}% &nbsp; | &nbsp; Exakta positioner: ${Math.round((b.exact_positions_ratio||0)*100)}% (${b.exact_positions_count||0}/${(q.meta.items||[]).length})<br>`;
            html += `Felplacerade element: ${Math.round((b.offs_pct||0)*100)}% &nbsp; | &nbsp; Inversioner: ${b.inversions || 0} / ${b.max_inversions || 0} &nbsp; | &nbsp; Offs (normaliserat): ${Math.round((b.offs_norm||0)*100)}%<br>`;
            html += `<strong>Slutpoäng (ratio):</strong> ${Math.round(finalRatio*100)}% &nbsp; → <strong>Poäng:</strong> ${awarded} / ${pointsPossible}<br>`;
            html += `<div class="small">Beräkning: Poäng = round(${pointsPossible} × ${finalRatio.toFixed(3)}) = ${awarded}</div>`;
            html += `</div>`;
        } else {
            html += `<div class="mb-2"><strong>Fråga ${qNum}:</strong> Ingen data – Poäng: ${awarded} / ${pointsPossible}</div>`;
        }
    });

    const percent = totalPossible > 0 ? ((totalAwarded / totalPossible) * 100) : 0;
    html += `<hr>`;
    html += `<div><strong>Totalt:</strong> ${totalAwarded} / ${totalPossible} → ${percent.toFixed(1)}%</div>`;
    html += `<div class="small mt-1"><strong>Beräkning:</strong> (${totalAwarded} / ${totalPossible}) × 100 = ${percent.toFixed(1)}%</div>`;

    html += `</div>`;

    container.innerHTML = html;
}

function renderQuestion() {
    const question = questions[currentQuestionIndex];
    questionCounter.textContent = `Fråga ${currentQuestionIndex + 1} av ${questions.length}`;
    
    // Ensure questionContent visible; always use the same spacing class
    questionContent.style.display = 'block';
    questionContent.className = 'mb-4';
    if (question.type !== 'fillblank') {
        questionContent.textContent = question.content;
    } else {
        // will be populated in the fillblank branch
        questionContent.innerHTML = '';
        questionContent.classList.add('fillblank-text');
    }
    
    answerArea.innerHTML = '';

    if (question.type === 'mcq') {
        // Shuffle MCQ questions
        const choicesToShow = [...question.choices].sort(() => Math.random() - 0.5);
        const container = document.createElement('div');
        container.className = 'list-group';
        choicesToShow.forEach(ch => {
            const btn = document.createElement('button');
            btn.className = 'btn answer-btn btn-outline-light w-100 text-start';
            btn.dataset.choiceId = ch.id;
            btn.innerHTML = `<div>${ch.content}</div>`;
            btn.addEventListener('click', () => {
                Array.from(container.children).forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
            });
            container.appendChild(btn);
        });
        answerArea.appendChild(container);
    } else if (question.type === 'truefalse') {
        // Don't shuffle true/false - keep consistent order
        const choicesToShow = question.choices;
        const container = document.createElement('div');
        container.className = 'list-group';
        choicesToShow.forEach(ch => {
            const btn = document.createElement('button');
            btn.className = 'btn answer-btn btn-outline-light w-100 text-start';
            btn.dataset.choiceId = ch.id;
            btn.innerHTML = `<div>${ch.content}</div>`;
            btn.addEventListener('click', () => {
                Array.from(container.children).forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
            });
            container.appendChild(btn);
        });
        answerArea.appendChild(container);
    } else if (question.type === 'fillblank') {
        // Fill-in-the-blank: render blank into #questionContent and show choices with same layout as MCQ/TrueFalse
        const content = question.content || '';
        const blankMarker = '____';
        let htmlContent = '';
        if (content.includes(blankMarker)) {
            const parts = content.split(blankMarker);
            for (let i = 0; i < parts.length; i++) {
                htmlContent += `<span class="text-part">${parts[i]}</span>`;
                if (i < parts.length - 1) htmlContent += '<span class="blank-marker" id="blankMarker">____</span>';
            }
        } else {
            htmlContent = `<span class="text-part">${content}</span>`;
        }
        htmlContent += `<div class="small mt-2 fillblank-instruction">Välj rätt ord som passar i luckan:</div>`;
        questionContent.innerHTML = htmlContent;

        const container = document.createElement('div');
        container.className = 'list-group';
        const choicesToShow = [...question.choices].sort(() => Math.random() - 0.5);
        choicesToShow.forEach(ch => {
            const btn = document.createElement('button');
            btn.className = 'btn answer-btn btn-outline-light w-100 text-start';
            btn.dataset.choiceId = ch.id;
            btn.dataset.choiceText = ch.content;
            btn.innerHTML = `<div>${ch.content}</div>`;
            btn.addEventListener('click', () => { selectChoice(btn); const bm = document.getElementById('blankMarker'); if (bm) { bm.textContent = ch.content; bm.classList.add('filled'); } });
            container.appendChild(btn);
        });
        answerArea.appendChild(container);
    } else if (question.type === 'ordering') {
        const list = document.createElement('div');
        list.id = 'orderingList';
        const items = [...(question.meta.items || [])];
        
        // Shuffle items for random start
        items.sort(() => Math.random() - 0.5);
        
        items.forEach(it => {
            const el = document.createElement('div');
            el.className = 'ordering-item';
            el.draggable = true;
            el.dataset.id = it.id;
            
            // Create drag handle for mobile
            const dragHandle = document.createElement('div');
            dragHandle.className = 'drag-handle';
            dragHandle.innerHTML = '&#8801;'; // ≡ symbol
            
            // Create content wrapper
            const contentDiv = document.createElement('div');
            contentDiv.className = 'ordering-item-content';
            contentDiv.textContent = it.content;
            
            el.appendChild(dragHandle);
            el.appendChild(contentDiv);
            
            // Desktop drag events
            el.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', it.id);
                el.classList.add('dragging');
            });
            el.addEventListener('dragend', () => el.classList.remove('dragging'));
            
            // Mobile touch events
            let touchStartY = 0;
            let currentY = 0;
            let isDragging = false;
            let placeholder = null;
            
            dragHandle.addEventListener('touchstart', e => {
                isDragging = true;
                touchStartY = e.touches[0].clientY;
                el.classList.add('dragging');
                
                // Create placeholder
                placeholder = document.createElement('div');
                placeholder.className = 'ordering-placeholder';
                placeholder.style.height = el.offsetHeight + 'px';
                el.parentNode.insertBefore(placeholder, el);
                
                el.style.position = 'relative';
                el.style.zIndex = '1000';
                e.preventDefault();
            }, { passive: false });
            
            dragHandle.addEventListener('touchmove', e => {
                if (!isDragging) return;
                
                currentY = e.touches[0].clientY;
                const deltaY = currentY - touchStartY;
                el.style.transform = `translateY(${deltaY}px)`;
                
                // Find element to insert before
                const afterElement = getTouchAfterElement(list, currentY, el);
                if (afterElement && afterElement !== placeholder) {
                    list.insertBefore(placeholder, afterElement);
                } else if (!afterElement && list.lastChild !== placeholder) {
                    list.appendChild(placeholder);
                }
                
                e.preventDefault();
            }, { passive: false });
            
            dragHandle.addEventListener('touchend', e => {
                if (!isDragging) return;
                
                isDragging = false;
                el.classList.remove('dragging');
                el.style.position = '';
                el.style.transform = '';
                el.style.zIndex = '';
                
                if (placeholder && placeholder.parentNode) {
                    placeholder.parentNode.insertBefore(el, placeholder);
                    placeholder.remove();
                }
                
                e.preventDefault();
            }, { passive: false });
            
            list.appendChild(el);
        });
        
        list.addEventListener('dragover', e => {
            e.preventDefault();
            const after = getDragAfterElement(list, e.clientY);
            const dragging = list.querySelector('.dragging');
            if (!dragging) return;
            if (after == null) list.appendChild(dragging);
            else list.insertBefore(dragging, after);
        });
        answerArea.appendChild(list);

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.ordering-item:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
        
        function getTouchAfterElement(container, y, currentElement) {
            const draggableElements = [...container.querySelectorAll('.ordering-item, .ordering-placeholder')]
                .filter(el => el !== currentElement);
            
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
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
    if (waitingToAdvance) {
        // Advance to next question now
        waitingToAdvance = false;
        confirmBtn.textContent = 'Bekräfta svar →';
        currentQuestionIndex++;
        if (currentQuestionIndex < questions.length) {
            renderQuestion();
            confirmBtn.disabled = false;
        } else {
            await finishAttempt();
        }
        return;
    }

    let answerObj = null;

    if (question.type === 'mcq') {
        const selected = answerArea.querySelector('button.active');
        if (!selected) { alert('Välj ett svar först'); return; }
        answerObj = { choice_id: parseInt(selected.dataset.choiceId, 10) };
    } else if (question.type === 'truefalse') {
        const selected = answerArea.querySelector('button.active');
        if (!selected) { alert('Välj ett svar först'); return; }
        answerObj = { choice_id: parseInt(selected.dataset.choiceId, 10) };
    } else if (question.type === 'fillblank') {
        const selected = answerArea.querySelector('button.active');
        if (!selected) { alert('Välj ett svar först'); return; }
        answerObj = { choice_id: parseInt(selected.dataset.choiceId, 10) };
    } else if (question.type === 'ordering') {
        const list = document.getElementById('orderingList');
        if (!list) { alert('Ordna elementen först'); return; }
        const order = Array.from(list.children).map(el => parseInt(el.dataset.id, 10));
        answerObj = { order };
    } else {
        const textarea = answerArea.querySelector('textarea');
        answerObj = { text: (textarea.value || '').trim() };
        if (!answerObj.text) { alert('Skriv ett svar först'); return; }
    }

    try {
        confirmBtn.disabled = true;
        const result = await apiCall('submit_answer', {
            attempt_id: attemptId,
            question_id: question.id,
            answer: answerObj
        });

        correctness = result.answers || correctness;

        if (question.type === 'ordering' && result.breakdown) {
            try {
                // Store breakdown and awarded points for final summary
                orderingResults[question.id] = {
                    breakdown: result.breakdown,
                    points_awarded: (result.points_awarded || 0),
                    points_possible: (question.meta && question.meta.points) ? question.meta.points : 10
                };

                showOrderingFeedback(result.breakdown, question);
                // Keep results displayed until next press
                waitingToAdvance = true;
                confirmBtn.textContent = 'Resultat → Nästa';
                confirmBtn.disabled = false;
                return;
            } catch (e) {
                console.warn('Ordering feedback error', e);
            }
        }

        // Non-ordering or no breakdown: proceed as before
        currentQuestionIndex++;
        if (currentQuestionIndex < questions.length) {
            renderQuestion();
            confirmBtn.disabled = false;
        } else {
            await finishAttempt();
        }
    } catch (error) {
        console.error('Error submitting answer:', error);
        alert('Ett fel uppstod. Försök igen.');
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
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'finish_attempt',
                attempt_id: attemptId,
                elapsed_time: elapsed
            })
        });
        
        const json = await res.json();
        
        if (json.success) {
            correctness = json.answers || correctness;
            
            questionArea.style.display = 'none';
            scoreArea.style.display = 'block';

            const finalScoreProgressBar = document.getElementById('scoreProgressBar');
            const finalScoreMarkersDiv = document.getElementById('scoreMarkers');
            
            if (finalScoreProgressBar && finalScoreMarkersDiv) {
                updateProgress(false, finalScoreProgressBar, finalScoreMarkersDiv, null);
                
                setTimeout(() => {
                    // Use server-provided percentage rather than forcing 100%
                    finalScoreProgressBar.style.width = (json.percentage || 0) + '%';
                }, 100);
            }

            if (json.xp_earned > 0) {
                scoreResult.innerHTML = `
                    <div class="gametext-small">
                        <h3>Du fick ${json.xp_earned} XP</h3>
                        <div>Bas: ${json.base_xp} | Bonus: ${json.bonus_xp}</div>
                        <div>Belöning: ${json.reward} – ${json.percentage}% ${json.percentage_type === 'exp' ? 'EXP' : 'Rätt'}</div>
                    </div>
                `;
                rewardImg.src = '../assets/img/' + json.reward + '.png';
                rewardImg.style.display = 'block';
            } else {
                if (json.maxed_out) {
                    scoreResult.innerHTML = `
                    <div class="gametext-small">
                        <h3>Du tjänade 0 XP!</h3>
                        <h4>Du har redan tjänat allt du kan från denna uppgift.</h4>
                        <div>Bas: ${json.base_xp} | Bonus: ${json.bonus_xp}</div>
                        <div>Belöning: ${json.reward} – ${json.percentage}% ${json.percentage_type === 'exp' ? 'EXP' : 'Rätt'}</div>
                    </div>
                    `;
                } else {
                    scoreResult.innerHTML = `
                    <div class="gametext-small">
                        <h3>Tyvärr, du fick 0 XP denna gång</h3>
                        <h4>Försök igen för att tjäna XP.</h4>
                        <div>Belöning: ${json.reward} – ${json.percentage}% ${json.percentage_type === 'exp' ? 'EXP' : 'Rätt'}</div>
                    </div>
                    `;
                }
                rewardImg.style.display = 'none';
            }

            if (json.leveled_up) {
                levelBanner.style.display = 'block';
                levelBanner.textContent = `Grattis! Du gick upp till nivå ${json.new_level}`;
            } else {
                levelBanner.style.display = 'none';
            }

            // If this is a pure ordering exercise, render a detailed "Poängberäkning" panel
            if (isSortingExercise) {
                renderPoangberakning(json);
            }

            // Show final progress bar in score area
            const scoreProgressBar = document.getElementById('scoreProgressBar');
            const scoreMarkersDiv = document.getElementById('scoreMarkers');
            if (scoreProgressBar && scoreMarkersDiv) {
                updateProgress(false, scoreProgressBar, scoreMarkersDiv, null);
                // If visible, set width to server percentage
                scoreProgressBar.style.width = (json.percentage || 0) + '%';
            }
            // Trigger badge check after short delay
            setTimeout(() => {
                if (window.triggerAchievementCheck) {
                    window.triggerAchievementCheck();
                } else if (window.achievementSystem) {
                    window.achievementSystem.checkNewBadges();
                }
            }, 500);
            
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
retryBtn.addEventListener('click', () => {
    window.location.reload();
});

window.addEventListener('resize', () => {
    if (progressMarker.style.display !== 'none') {
        updateProgress(true, progressBar, markersDiv, progressMarker);
    }
});
</script>

<!-- Achievement Notification System -->
<script src="../assets/js/achievement-notifications.js"></script>
</main>
</body>
</html>