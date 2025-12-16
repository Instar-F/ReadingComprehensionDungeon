<?php
/**
 * BadgeManager.php
 * 
 * Manages the achievement/badge system for the Reading Comprehension Dungeon.
 * Handles badge awarding logic, progress tracking, and notification management.
 * 
 * Key responsibilities:
 * - Track user progress on exercises (attempts, scores, rewards)
 * - Check badge requirements and award badges when criteria are met
 * - Handle tiered badge progression (only award highest tier achieved)
 * - Manage badge notifications for display to users
 * - Calculate user progress towards badge goals
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
     * 
     * This method maintains the user_exercise_progress table which tracks:
     * - How many times each exercise has been attempted
     * - The best reward achieved (coal, copper, iron, gold, diamond, emerald)
     * - The highest score achieved
     * - The total cumulative score across all attempts
     * 
     * Uses MySQL's ON DUPLICATE KEY UPDATE to either insert new progress
     * or update existing records. The FIELD() function is used to compare
     * reward tiers and keep only the best one.
     * 
     * @param int $exerciseId The exercise that was completed
     * @param int $score The score achieved in this attempt
     * @param string $reward The reward tier earned (coal, copper, iron, gold, diamond, emerald)
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
            
            // Parameters: userId, exerciseId, reward, score, score (for INSERT),
            //             reward, reward, score, score (for UPDATE)
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
     * 
     * This is the main badge checking logic. It:
     * 1. Fetches all badges the user doesn't have yet
     * 2. Groups badges by their base name (badges can have multiple tiers like "first_steps_1", "first_steps_2")
     * 3. For each badge group, checks which tiers the user qualifies for
     * 4. Awards ONLY the highest tier qualified (prevents earning all tiers at once)
     * 
     * Example: If a user completes 10 exercises and there are "first_steps_1" (requires 5),
     * "first_steps_2" (requires 10), and "first_steps_3" (requires 20), they will only
     * receive "first_steps_2" even though they qualify for both _1 and _2.
     * 
     * @return array Array of newly awarded badges with metadata for notifications
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
            // Example: "first_steps_1", "first_steps_2" both become "first_steps"
            $badgeGroups = [];
            foreach ($badges as $badge) {
                $baseKey = $this->getBaseBadgeKey($badge['key_name']);
                if (!isset($badgeGroups[$baseKey])) {
                    $badgeGroups[$baseKey] = [];
                }
                $badgeGroups[$baseKey][] = $badge;
            }
            
            // For each badge group, find the highest tier the user qualifies for
            foreach ($badgeGroups as $baseKey => $group) {
                $highestQualified = null;
                $allQualifiedTiers = [];
                
                // Check all tiers in this group
                foreach ($group as $badge) {
                    if ($this->checkBadgeRequirement($badge)) {
                        $allQualifiedTiers[] = $badge;
                        // Keep track of highest tier (higher tier number = higher requirement)
                        if ($highestQualified === null || 
                            ($badge['tier'] ?? 0) > ($highestQualified['tier'] ?? 0)) {
                            $highestQualified = $badge;
                        }
                    }
                }
                
                // Award only the highest tier, but track all qualified tiers for display purposes
                if ($highestQualified !== null) {
                    $this->awardBadge($highestQualified['id'], $highestQualified['points_reward']);
                    
                    // Add metadata for notification display (shows user they unlocked tier X of Y)
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
     * Extract base badge key without tier suffix
     * 
     * Badges are named with tiers like "first_steps_1", "first_steps_2", etc.
     * This strips the trailing number to get the base key "first_steps"
     * for grouping related badges together.
     * 
     * @param string $keyName Full badge key like "first_steps_3"
     * @return string Base key like "first_steps"
     */
    private function getBaseBadgeKey($keyName) {
        return preg_replace('/_\d+$/', '', $keyName);
    }
    
    /**
     * Check if user meets the requirement for a specific badge
     * 
     * Different badges have different requirement types:
     * - exercises_completed: Count of finished exercises
     * - diamonds_earned: Count of exercises where best reward is diamond or emerald
     * - emeralds_earned: Count of exercises where best reward is emerald
     * - time_spent: Total time spent on completed exercises (in seconds)
     * - *_earned: Count of specific reward tiers earned
     * - first_try_emerald: Got emerald on first attempt of an exercise
     * - perfect_streak_X: Answered X consecutive questions correctly
     * - question_master_*: Completed all exercises with specific question type with gold+
     * - story_easy/hard: Completed all exercises of that difficulty
     * 
     * @param array $badge Badge record with requirement_type and requirement_value
     * @return bool True if user meets the requirement
     */
    private function checkBadgeRequirement($badge) {
        $type = $badge['requirement_type'];
        $required = (int)$badge['requirement_value'];
        
        try {
            switch ($type) {
                case 'exercises_completed':
                    // Count unique exercises where attempt was finished
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT exercise_id) 
                        FROM attempt_sessions 
                        WHERE user_id = ? AND finished_at IS NOT NULL
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'diamonds_earned':
                    // Count exercises where best reward is diamond or emerald
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT exercise_id) 
                        FROM user_exercise_progress 
                        WHERE user_id = ? AND best_reward IN ('diamond', 'emerald')
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'emeralds_earned':
                    // Count exercises where best reward is emerald (highest tier)
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(DISTINCT exercise_id) 
                        FROM user_exercise_progress 
                        WHERE user_id = ? AND best_reward = 'emerald'
                    ");
                    $stmt->execute([$this->userId]);
                    return $stmt->fetchColumn() >= $required;
                    
                case 'time_spent':
                    // Sum elapsed time from all finished attempts
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
                    // Iron or better (includes gold, diamond, emerald)
                    return $this->checkRewardEarned(['iron', 'gold', 'diamond', 'emerald'], $required);
                    
                case 'gold_earned':
                    // Gold or better (includes diamond, emerald)
                    return $this->checkRewardEarned(['gold', 'diamond', 'emerald'], $required);
                    
                case 'first_try_emerald':
                    // Check if user got emerald on first attempt of any exercise
                    // The subquery ensures there are no earlier attempts for that exercise
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
                    
                case 'perfect_streak_3':
                case 'perfect_streak_5':
                case 'perfect_streak_10':
                case 'perfect_streak_20':
                    // Extract streak length from badge key (e.g., "perfect_streak_10" -> 10)
                    $streakRequired = (int)substr($badge['key_name'], strrpos($badge['key_name'], '_') + 1);
                    return $this->checkPerfectStreak($streakRequired);
                    
                case 'question_master_multiple_choice':
                case 'question_master_sequence':
                case 'question_master_true_false':
                    // Extract question type from badge key
                    $questionType = str_replace('question_master_', '', $badge['key_name']);
                    return $this->checkQuestionTypeMastery($questionType);
                    
                case 'story_easy':
                case 'story_hard':
                    // Check if user completed all exercises of this difficulty
                    return $this->checkDifficultyComplete($badge['key_name']);
                    
                default:
                    return false;
            }
        } catch (Exception $e) {
            error_log("Failed to check badge requirement: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user earned a specific reward tier on enough exercises
     * 
     * @param array $rewards Array of reward names to check (e.g., ['gold', 'diamond', 'emerald'])
     * @param int $requiredCount Minimum number of exercises with these rewards
     * @return bool True if requirement is met
     */
    private function checkRewardEarned($rewards, $requiredCount) {
        $placeholders = implode(',', array_fill(0, count($rewards), '?'));
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT exercise_id) 
            FROM user_exercise_progress 
            WHERE user_id = ? AND best_reward IN ($placeholders)
        ");
        $params = array_merge([$this->userId], $rewards);
        $stmt->execute($params);
        return $stmt->fetchColumn() >= $requiredCount;
    }
    
    /**
     * Check if user has achieved a perfect answer streak
     * 
     * A perfect streak means answering N consecutive questions correctly
     * across all exercises. This uses MySQL variables to track the running
     * streak and find the maximum achieved.
     * 
     * The query:
     * 1. Orders all answers by attempt and question order
     * 2. Uses @streak variable that increases on correct, resets on wrong
     * 3. Tracks the maximum streak achieved
     * 
     * @param int $requiredStreak Length of streak needed (e.g., 10)
     * @return bool True if user has achieved this streak
     */
    private function checkPerfectStreak($requiredStreak) {
        $stmt = $this->pdo->prepare("
            SELECT MAX(running_streak) as max_streak
            FROM (
                SELECT 
                    @streak := IF(correct = 1, @streak + 1, 0) as running_streak
                FROM user_answers ua
                JOIN attempt_sessions ats ON ua.attempt_id = ats.id
                CROSS JOIN (SELECT @streak := 0) init
                WHERE ats.user_id = ?
                ORDER BY ats.id, ua.question_order
            ) streak_calc
        ");
        $stmt->execute([$this->userId]);
        $maxStreak = $stmt->fetchColumn();
        return $maxStreak >= $requiredStreak;
    }
    
    /**
     * Check if user has mastered a specific question type
     * 
     * Mastery means completing ALL exercises containing that question type
     * with a gold or better reward.
     * 
     * @param string $questionType Type of question (e.g., 'multiple_choice', 'sequence', 'true_false')
     * @return bool True if all exercises with this question type are completed with gold+
     */
    private function checkQuestionTypeMastery($questionType) {
        // First, count total exercises with this question type
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
        
        // Count how many of those exercises are completed with gold or better
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
     * Check if user has completed all exercises of a specific difficulty
     * 
     * @param string $keyName Badge key name like "story_easy" or "story_hard"
     * @return bool True if all exercises of that difficulty are completed
     */
    private function checkDifficultyComplete($keyName) {
        $difficulty = $keyName == 'story_easy' ? 'easy' : 'hard';
        
        // Count total exercises of this difficulty
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM exercises WHERE difficulty = ?");
        $stmt->execute([$difficulty]);
        $total = $stmt->fetchColumn();
        
        if ($total == 0) return false;
        
        // Count how many the user has completed
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
     * 
     * This method:
     * 1. Adds the badge to user_badges table (INSERT IGNORE prevents duplicates)
     * 2. Creates a notification for the user to see
     * 3. Awards XP points through LevelManager
     * 
     * The badge is only awarded if it doesn't already exist (checked via rowCount).
     * 
     * @param int $badgeId The ID of the badge to award
     * @param int $points XP points to award for this badge
     */
    private function awardBadge($badgeId, $points) {
        try {
            // Add to user_badges (INSERT IGNORE prevents duplicate key errors)
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO user_badges (user_id, badge_id, earned_at) 
                VALUES (?, ?, NOW())
            ");
            $result = $stmt->execute([$this->userId, $badgeId]);
            
            // Check if badge was actually inserted (rowCount > 0 means new badge)
            $wasAwarded = $stmt->rowCount() > 0;
            
            if (!$wasAwarded) {
                error_log("Badge $badgeId already existed for user {$this->userId}, skipping XP award");
                return;
            }
            
            error_log("Awarding badge $badgeId to user {$this->userId} with $points XP");
            
            // Create notification for user to see on next page load
            $stmt = $this->pdo->prepare("
                INSERT INTO badge_notifications (user_id, badge_id, shown) 
                VALUES (?, ?, 0)
                ON DUPLICATE KEY UPDATE shown = 0
            ");
            $stmt->execute([$this->userId, $badgeId]);
            
            // Award XP using LevelManager for consistent leveling logic
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
     * Get all unshown badge notifications for the user
     * 
     * These are badges that have been awarded but not yet displayed to the user.
     * The frontend will show these as popup notifications and then mark them as shown.
     * 
     * @return array Array of notification records with badge details
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
     * Mark notifications as shown (user has seen them)
     * 
     * Called after the frontend displays badge notifications to the user.
     * 
     * @param array $notificationIds Array of notification IDs to mark as shown
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
     * Get all badges with user's earned status
     * 
     * Returns all non-secret badges, plus any secret badges the user has earned.
     * Secret badges are hidden until earned to maintain surprise.
     * 
     * Results are ordered to show earned badges first, then by category and tier.
     * 
     * @return array Array of badge records with 'earned' and 'earned_at' fields
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
     * Get current progress towards a badge goal
     * 
     * For badges with trackable progress (like "complete 10 exercises"),
     * this returns the user's current count/value.
     * 
     * @param array $badge Badge record with requirement_type
     * @return int Current progress value
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