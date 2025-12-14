<?php
/**
 * BadgeManager - Complete badge handling system
 * No database triggers needed - everything in PHP!
 * 
 * Usage:
 *   $badgeManager = new BadgeManager($pdo, $userId);
 *   $badgeManager->updateExerciseProgress($exerciseId, $score, $reward);
 *   $newBadges = $badgeManager->checkAndAwardBadges();
 */

require_once __DIR__ . '/LevelManager.php';


class BadgeManager {
    private $pdo;
    private $userId;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = (int)$userId;
    }
    
    /**
     * Update exercise progress after completing an attempt
     * Call this from play.php after finishing an exercise
     */
    public function updateExerciseProgress($exerciseId, $score, $reward) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_exercise_progress 
                    (user_id, exercise_id, attempts_count, best_reward, best_score, total_score, completed, first_completed_at, last_attempted_at)
                VALUES 
                    (?, ?, 1, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempts_count = attempts_count + 1,
                    best_reward = CASE 
                        WHEN FIELD(?, 'coal', 'copper', 'iron', 'gold', 'diamond', 'emerald') > 
                             FIELD(COALESCE(best_reward, 'coal'), 'coal', 'copper', 'iron', 'gold', 'diamond', 'emerald')
                        THEN ?
                        ELSE best_reward
                    END,
                    best_score = GREATEST(COALESCE(best_score, 0), ?),
                    total_score = total_score + ?,
                    completed = 1,
                    first_completed_at = COALESCE(first_completed_at, NOW()),
                    last_attempted_at = NOW()
            ");
            
            $stmt->execute([
                $this->userId, $exerciseId, $reward, $score, $score,
                $reward, $reward, $score, $score
            ]);
        } catch (Exception $e) {
            error_log("Failed to update exercise progress: " . $e->getMessage());
        }
    }
    
    /**
     * Check and award all eligible badges for the user
     * Groups tiered badges and only awards the highest tier
     * Returns array of newly awarded badges
     */
    public function checkAndAwardBadges() {
        $newBadges = [];
        
        try {
            // Get all badges user doesn't have yet
            $stmt = $this->pdo->prepare("
                SELECT b.* FROM badges b
                WHERE b.id NOT IN (SELECT badge_id FROM user_badges WHERE user_id = ?)
                ORDER BY b.key_name, b.tier
            ");
            $stmt->execute([$this->userId]);
            $badges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group badges by base key_name (without tier suffix)
            $badgeGroups = [];
            foreach ($badges as $badge) {
                $baseKey = $this->getBaseBadgeKey($badge['key_name']);
                if (!isset($badgeGroups[$baseKey])) {
                    $badgeGroups[$baseKey] = [];
                }
                $badgeGroups[$baseKey][] = $badge;
            }
            
            // For each group, find the highest tier the user qualifies for
            foreach ($badgeGroups as $baseKey => $group) {
                $highestQualified = null;
                $allQualifiedTiers = [];
                
                // Check all tiers in this group
                foreach ($group as $badge) {
                    if ($this->checkBadgeRequirement($badge)) {
                        $allQualifiedTiers[] = $badge;
                        // Keep track of highest tier (assuming higher tier = higher requirement_value)
                        if ($highestQualified === null || 
                            ($badge['tier'] ?? 0) > ($highestQualified['tier'] ?? 0)) {
                            $highestQualified = $badge;
                        }
                    }
                }
                
                // Award only the highest tier, but track all tiers for display
                if ($highestQualified !== null) {
                    $this->awardBadge($highestQualified['id'], $highestQualified['points_reward']);
                    
                    // Add tier information for notification display
                    $highestQualified['earned_tiers'] = count($allQualifiedTiers);
                    $highestQualified['total_tiers'] = count($group);
                    $highestQualified['is_rare'] = in_array($highestQualified['category'], ['mastery', 'secret']);
                    
                    $newBadges[] = $highestQualified;
                }
            }
        } catch (Exception $e) {
            error_log("Failed to check badges: " . $e->getMessage());
        }
        
        return $newBadges;
    }
    
    /**
     * Get base badge key without tier number
     * e.g., "first_steps_3" -> "first_steps"
     */
    private function getBaseBadgeKey($keyName) {
        // Remove trailing numbers and underscores
        return preg_replace('/_\d+$/', '', $keyName);
    }
    
    /**
     * Check if user meets badge requirement
     */
    private function checkBadgeRequirement($badge) {
        $type = $badge['requirement_type'];
        $required = (int)$badge['requirement_value'];
        
        try {
            switch ($type) {
                case 'exercises_completed':
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT exercise_id) 
                        FROM attempt_sessions 
                        WHERE user_id = ? AND finished_at IS NOT NULL
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'diamonds_earned':
                    // Count unique exercises where best reward is diamond or emerald
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT exercise_id) 
                        FROM user_exercise_progress 
                        WHERE user_id = ? AND best_reward IN ('diamond', 'emerald')
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'emeralds_earned':
                    // Count unique exercises where best reward is emerald
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT exercise_id) 
                        FROM user_exercise_progress 
                        WHERE user_id = ? AND best_reward = 'emerald'
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'time_spent':
                    $stmt = $this->pdo->prepare("
                        SELECT COALESCE(SUM(elapsed_time), 0) 
                        FROM attempt_sessions 
                        WHERE user_id = ? AND finished_at IS NOT NULL
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'coal_earned':
                    return $this->checkRewardEarned(['coal'], $required);
                    
                case 'copper_earned':
                    return $this->checkRewardEarned(['copper'], $required);
                    
                case 'iron_earned':
                    return $this->checkRewardEarned(['iron', 'gold', 'diamond', 'emerald'], $required);
                    
                case 'gold_earned':
                    return $this->checkRewardEarned(['gold', 'diamond', 'emerald'], $required);
                    
                case 'first_try_emerald':
                    // Check if user got emerald on first attempt of any exercise
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT e.id) 
                        FROM exercises e
                        JOIN attempt_sessions ats ON e.id = ats.exercise_id
                        WHERE ats.user_id = ? 
                        AND ats.reward = 'emerald'
                        AND ats.finished_at IS NOT NULL
                        AND NOT EXISTS (
                            SELECT 1 FROM attempt_sessions ats2 
                            WHERE ats2.exercise_id = e.id 
                            AND ats2.user_id = ats.user_id
                            AND ats2.finished_at < ats.finished_at
                        )
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'perfect_streak':
                    // Check for X correct answers in a row
                    $stmt = $this->pdo->prepare("
                        SELECT MAX(streak) as max_streak FROM (
                            SELECT 
                                COUNT(*) as streak,
                                @streak := IF(correct = 1, @streak + 1, 0) as running_streak
                            FROM attempt_answers aa
                            JOIN attempt_sessions ats ON aa.attempt_id = ats.id
                            WHERE ats.user_id = ?
                            AND ats.finished_at IS NOT NULL
                            ORDER BY aa.created_at
                        ) streaks
                    ");
                    $stmt->execute([$this->userId]);
                    return ($stmt->fetchColumn() ?? 0) >= $required;
                    
                case 'night_completion':
                    // Check for completion between 00:00-05:00
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) 
                        FROM attempt_sessions 
                        WHERE user_id = ? 
                        AND finished_at IS NOT NULL
                        AND HOUR(finished_at) >= 0 
                        AND HOUR(finished_at) < 5
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'speed_completion':
                    // Check for completion under 30 seconds with perfect score
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) 
                        FROM attempt_sessions 
                        WHERE user_id = ? 
                        AND finished_at IS NOT NULL
                        AND elapsed_time <= 30
                        AND reward IN ('emerald', 'diamond')
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'type_master':
                    return $this->checkTypeMaster($badge['key_name']);
                    
                case 'difficulty_complete':
                    return $this->checkDifficultyComplete($badge['key_name']);
                    
                default:
                    return false;
            }
        } catch (Exception $e) {
            error_log("Error checking badge requirement: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user earned specific reward type (counts unique exercises, not attempts)
     */
    private function checkRewardEarned($rewards, $required) {
        $placeholders = implode(',', array_fill(0, count($rewards), '?'));
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT exercise_id) 
            FROM user_exercise_progress 
            WHERE user_id = ? AND best_reward IN ($placeholders)
        ");
        $stmt->execute(array_merge([$this->userId], $rewards));
        return $stmt->fetchColumn() >= $required;
    }
    
    /**
     * Check type master badges (must complete ALL exercises of type with gold+)
     */
    private function checkTypeMaster($keyName) {
        $typeMap = [
            'mcq_master' => 'mcq',
            'truefalse_master' => 'truefalse',
            'ordering_master' => 'ordering',
            'fillblank_master' => 'fillblank',
            'matching_master' => 'matching'
        ];
        
        $questionType = $typeMap[$keyName] ?? null;
        if (!$questionType) return false;
        
        // Count total exercises with this question type
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT e.id) 
            FROM exercises e
            WHERE EXISTS (
                SELECT 1 FROM questions q 
                WHERE q.exercise_id = e.id AND q.type = ?
            )
        ");
        $stmt->execute([$questionType]);
        $total = $stmt->fetchColumn();
        
        if ($total == 0) return false;
        
        // Count completed with gold or better
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT e.id)
            FROM exercises e
            JOIN user_exercise_progress uep ON uep.exercise_id = e.id
            WHERE uep.user_id = ? 
            AND uep.best_reward IN ('gold', 'diamond', 'emerald')
            AND EXISTS (
                SELECT 1 FROM questions q 
                WHERE q.exercise_id = e.id AND q.type = ?
            )
        ");
        $stmt->execute([$this->userId, $questionType]);
        $completed = $stmt->fetchColumn();
        
        return $completed >= $total;
    }
    
    /**
     * Check difficulty complete badges (must complete ALL exercises of difficulty)
     */
    private function checkDifficultyComplete($keyName) {
        $difficulty = $keyName == 'story_easy' ? 'easy' : 'hard';
        
        // Count total
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM exercises WHERE difficulty = ?");
        $stmt->execute([$difficulty]);
        $total = $stmt->fetchColumn();
        
        if ($total == 0) return false;
        
        // Count completed
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT e.id)
            FROM exercises e
            JOIN user_exercise_progress uep ON uep.exercise_id = e.id
            WHERE e.difficulty = ? AND uep.user_id = ? AND uep.completed = 1
        ");
        $stmt->execute([$difficulty, $this->userId]);
        $completed = $stmt->fetchColumn();
        
        return $completed >= $total;
    }
    
    /**
     * Award a badge to the user
     */
    private function awardBadge($badgeId, $points) {
        try {
            // Add to user_badges
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO user_badges (user_id, badge_id, earned_at) 
                VALUES (?, ?, NOW())
            ");
            $result = $stmt->execute([$this->userId, $badgeId]);
            
            // Check if badge was actually inserted (rowCount > 0 means new badge)
            $wasAwarded = $stmt->rowCount() > 0;
            
            if (!$wasAwarded) {
                error_log("Badge $badgeId already existed for user {$this->userId}, skipping XP award");
                return; // Badge already existed, don't award points or create notification
            }
            
            error_log("Awarding badge $badgeId to user {$this->userId} with $points XP");
            
            // Create notification
            $stmt = $this->pdo->prepare("
                INSERT INTO badge_notifications (user_id, badge_id, shown) 
                VALUES (?, ?, 0)
                ON DUPLICATE KEY UPDATE shown = 0
            ");
            $stmt->execute([$this->userId, $badgeId]);
            
            // Award XP using LevelManager for consistent leveling
            $levelManager = new LevelManager($this->pdo, $this->userId);
            $levelResult = $levelManager->awardXP($points, 'badge');
            
            if ($levelResult['success']) {
                error_log("Badge XP awarded: {$levelResult['old_points']} → {$levelResult['new_points']} " .
                         "(Level {$levelResult['old_level']} → {$levelResult['new_level']})" .
                         ($levelResult['leveled_up'] ? " LEVEL UP!" : ""));
            }
            
        } catch (Exception $e) {
            error_log("Failed to award badge: " . $e->getMessage());
        }
    }
    
    /**
     * Get unshown badge notifications
     */
    public function getUnshownNotifications() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    bn.id as notification_id,
                    b.id as badge_id,
                    b.key_name,
                    b.title,
                    b.description,
                    b.icon,
                    b.points_reward,
                    b.category,
                    b.tier
                FROM badge_notifications bn
                JOIN badges b ON bn.badge_id = b.id
                WHERE bn.user_id = ? AND bn.shown = 0
                ORDER BY bn.created_at ASC
            ");
            $stmt->execute([$this->userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get notifications: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark notifications as shown
     */
    public function markNotificationsShown($notificationIds) {
        if (empty($notificationIds)) return;
        
        try {
            $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
            $stmt = $this->pdo->prepare("UPDATE badge_notifications SET shown = 1 WHERE id IN ($placeholders)");
            $stmt->execute($notificationIds);
        } catch (Exception $e) {
            error_log("Failed to mark notifications: " . $e->getMessage());
        }
    }
    
    /**
     * Get all user badges with progress
     */
    public function getUserBadges() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    b.*,
                    ub.earned_at,
                    CASE WHEN ub.id IS NOT NULL THEN 1 ELSE 0 END as earned
                FROM badges b
                LEFT JOIN user_badges ub ON b.id = ub.badge_id AND ub.user_id = ?
                WHERE b.is_secret = 0 OR ub.id IS NOT NULL
                ORDER BY 
                    CASE WHEN ub.id IS NOT NULL THEN 0 ELSE 1 END,
                    b.category, b.tier, b.id
            ");
            $stmt->execute([$this->userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get user badges: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get current progress for a badge
     */
    public function getBadgeProgress($badge) {
        $type = $badge['requirement_type'];
        
        try {
            switch ($type) {
                case 'exercises_completed':
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT exercise_id) 
                        FROM attempt_sessions 
                        WHERE user_id = ? AND finished_at IS NOT NULL
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn();
                    
                case 'time_spent':
                    $stmt = $this->pdo->prepare("
                        SELECT COALESCE(SUM(elapsed_time), 0) 
                        FROM attempt_sessions 
                        WHERE user_id = ? AND finished_at IS NOT NULL
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn();
                    
                default:
                    return 0;
            }
        } catch (Exception $e) {
            return 0;
        }
    }
}