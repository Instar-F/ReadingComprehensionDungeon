<?php
/**
 * Manages the user leveling system for Reading Comprehension Dungeon.
 * Handles XP (experience points) tracking, level calculations, and level-up logic.
 * 
 * Level System Design:
 * - Users earn XP from completing exercises and earning badges
 * - Every 1000 XP equals one level (configurable)
 * - Level is calculated as: floor(total_xp / xp_per_level) + 1
 * - Example: 0 XP = Level 1, 1000 XP = Level 2, 2000 XP = Level 3
 * 
 * This class provides:
 * - XP awarding with automatic level calculation
 * - Progress tracking (how close to next level)
 * - Level recalculation (useful if XP requirements change)
 */

class LevelManager {
    private $pdo;
    private $userId;
    
    /** @var int XP required for each level (default 1000) */
    private $xpPerLevel = 1000;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = (int)$userId;
    }
    
    /**
     * Calculate level from total XP
     * 
     * Formula: Level = floor(XP / XP_PER_LEVEL) + 1
     * 
     * Examples with default 1000 XP per level:
     * - 0 XP = Level 1
     * - 999 XP = Level 1
     * - 1000 XP = Level 2
     * - 2500 XP = Level 3
     * 
     * @param int $xp Total XP accumulated
     * @return int Calculated level
     */
    public function calculateLevel($xp) {
        return floor($xp / $this->xpPerLevel) + 1;
    }
    
    /**
     * Calculate how much XP is needed to reach the next level
     * 
     * Example: If user has 1,300 XP (Level 2), they need 700 more XP
     * to reach Level 3 (which requires 2,000 total XP).
     * 
     * @param int $currentXp Current total XP
     * @return int XP needed for next level
     */
    public function xpForNextLevel($currentXp) {
        $currentLevel = $this->calculateLevel($currentXp);
        $xpForNextLevel = $currentLevel * $this->xpPerLevel;
        return $xpForNextLevel - $currentXp;
    }
    
    /**
     * Calculate progress towards next level as a percentage
     * 
     * Returns a value between 0 and 100 showing how far through
     * the current level the user is.
     * 
     * Example: With 1,300 XP at Level 2:
     * - Level 2 starts at 1,000 XP
     * - Level 3 starts at 2,000 XP
     * - Progress: (1,300 - 1,000) / 1,000 * 100 = 30%
     * 
     * @param int $currentXp Current total XP
     * @return float Progress percentage (0-100)
     */
    public function levelProgress($currentXp) {
        $currentLevel = $this->calculateLevel($currentXp);
        $xpInCurrentLevel = $currentXp - (($currentLevel - 1) * $this->xpPerLevel);
        return ($xpInCurrentLevel / $this->xpPerLevel) * 100;
    }
    
    /**
     * Award XP to user and update their level
     * 
     * This is the main method for awarding experience points. It:
     * 1. Fetches current XP and level from database
     * 2. Adds the new XP
     * 3. Recalculates level based on new total
     * 4. Updates database
     * 5. Returns detailed information about the change
     * 
     * The method automatically detects level-ups and returns this information
     * so the caller can display congratulatory messages or unlock new content.
     * 
     * @param int $xp Amount of XP to award
     * @param string $source Source of XP (e.g., 'exercise', 'badge') for logging
     * @return array Result with keys:
     *   - success: bool - Whether operation succeeded
     *   - old_points: int - XP before award
     *   - new_points: int - XP after award
     *   - xp_awarded: int - Amount awarded
     *   - old_level: int - Level before award
     *   - new_level: int - Level after award
     *   - leveled_up: bool - Whether user leveled up
     *   - levels_gained: int - Number of levels gained (usually 0 or 1)
     *   - xp_for_next_level: int - XP needed for next level
     *   - level_progress: float - Progress to next level (0-100%)
     */
    public function awardXP($xp, $source = 'exercise') {
        try {
            // Get current user data
            $stmt = $this->pdo->prepare("SELECT points, level FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            $oldPoints = (int)$user['points'];
            $oldLevel = (int)$user['level'];
            
            // Calculate new points and level
            $newPoints = $oldPoints + $xp;
            $newLevel = $this->calculateLevel($newPoints);
            
            // Update database with new XP and level
            $updateStmt = $this->pdo->prepare("
                UPDATE users 
                SET points = ?, level = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$newPoints, $newLevel, $this->userId]);
            
            // Determine if user leveled up and by how many levels
            $leveledUp = ($newLevel > $oldLevel);
            $levelsGained = $newLevel - $oldLevel;
            
            // Log the XP award for debugging and analytics
            error_log("Awarded $xp XP to user {$this->userId} from $source. " .
                     "Points: $oldPoints → $newPoints. Level: $oldLevel → $newLevel" .
                     ($leveledUp ? " (LEVEL UP!)" : ""));
            
            return [
                'success' => true,
                'old_points' => $oldPoints,
                'new_points' => $newPoints,
                'xp_awarded' => $xp,
                'old_level' => $oldLevel,
                'new_level' => $newLevel,
                'leveled_up' => $leveledUp,
                'levels_gained' => $levelsGained,
                'xp_for_next_level' => $this->xpForNextLevel($newPoints),
                'level_progress' => $this->levelProgress($newPoints)
            ];
            
        } catch (Exception $e) {
            error_log("LevelManager error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get user's current level information
     * 
     * Fetches user's current XP and level from database and calculates
     * progress metrics.
     * 
     * @return array Result with keys:
     *   - success: bool
     *   - points: int - Total XP
     *   - level: int - Current level
     *   - xp_for_next_level: int - XP needed to level up
     *   - level_progress: float - Progress percentage (0-100)
     */
    public function getUserLevelInfo() {
        try {
            $stmt = $this->pdo->prepare("SELECT points, level FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            $points = (int)$user['points'];
            $level = (int)$user['level'];
            
            return [
                'success' => true,
                'points' => $points,
                'level' => $level,
                'xp_for_next_level' => $this->xpForNextLevel($points),
                'level_progress' => $this->levelProgress($points)
            ];
            
        } catch (Exception $e) {
            error_log("LevelManager error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Set XP required per level
     * 
     * Allows customization of leveling speed. Lower values = faster leveling.
     * Minimum value is 1 to prevent division by zero.
     * 
     * Note: Changing this will affect level calculations going forward.
     * Consider calling recalculateLevel() on all users if changing this
     * for an existing system.
     * 
     * @param int $xp XP required per level (minimum 1)
     */
    public function setXpPerLevel($xp) {
        $this->xpPerLevel = max(1, (int)$xp);
    }
    
    /**
     * Get current XP per level setting
     * 
     * @return int XP required per level
     */
    public function getXpPerLevel() {
        return $this->xpPerLevel;
    }
    
    /**
     * Recalculate user's level based on current XP
     * 
     * This is useful in two scenarios:
     * 1. The XP-per-level constant has changed and you need to update existing users
     * 2. Data corruption occurred and levels are incorrect
     * 
     * This method fetches the user's current XP, calculates what their level
     * SHOULD be, and updates the database if different.
     * 
     * @return array Result with keys:
     *   - success: bool
     *   - level: int - Corrected level
     *   - points: int - Total XP
     */
    public function recalculateLevel() {
        try {
            $stmt = $this->pdo->prepare("SELECT points FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            $points = (int)$user['points'];
            $correctLevel = $this->calculateLevel($points);
            
            // Update level in database (even if it's the same, this is a lightweight operation)
            $updateStmt = $this->pdo->prepare("UPDATE users SET level = ? WHERE id = ?");
            $updateStmt->execute([$correctLevel, $this->userId]);
            
            return [
                'success' => true,
                'level' => $correctLevel,
                'points' => $points
            ];
            
        } catch (Exception $e) {
            error_log("LevelManager error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}