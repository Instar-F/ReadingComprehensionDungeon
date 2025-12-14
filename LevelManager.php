<?php
/**
 * Level Manager
 * Handles all user leveling logic and XP management
 */

class LevelManager {
    private $pdo;
    private $userId;
    private $xpPerLevel = 1000; // XP required per level
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = (int)$userId;
    }
    
    /**
     * Calculate level from XP
     */
    public function calculateLevel($xp) {
        return floor($xp / $this->xpPerLevel) + 1;
    }
    
    /**
     * Calculate XP needed for next level
     */
    public function xpForNextLevel($currentXp) {
        $currentLevel = $this->calculateLevel($currentXp);
        $xpForNextLevel = $currentLevel * $this->xpPerLevel;
        return $xpForNextLevel - $currentXp;
    }
    
    /**
     * Calculate progress to next level (0-100%)
     */
    public function levelProgress($currentXp) {
        $currentLevel = $this->calculateLevel($currentXp);
        $xpInCurrentLevel = $currentXp - (($currentLevel - 1) * $this->xpPerLevel);
        return ($xpInCurrentLevel / $this->xpPerLevel) * 100;
    }
    
    /**
     * Award XP and update level
     * Returns array with level_up info
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
            
            // Update database
            $updateStmt = $this->pdo->prepare("
                UPDATE users 
                SET points = ?, level = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$newPoints, $newLevel, $this->userId]);
            
            // Check if leveled up
            $leveledUp = ($newLevel > $oldLevel);
            $levelsGained = $newLevel - $oldLevel;
            
            // Log the award
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
     * Get user level info
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
     * Set XP per level (for customization)
     */
    public function setXpPerLevel($xp) {
        $this->xpPerLevel = max(1, (int)$xp);
    }
    
    /**
     * Get XP per level
     */
    public function getXpPerLevel() {
        return $this->xpPerLevel;
    }
    
    /**
     * Recalculate user's level based on current XP
     * (useful if XP per level changes or for fixing data)
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
            
            // Update if different
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
