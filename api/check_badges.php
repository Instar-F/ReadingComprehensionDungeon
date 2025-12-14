<?php
/**
 * Badge Check API Endpoint
 * Returns unshown badge notifications with tier progression info
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../BadgeManager.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!is_logged_in()) {
    echo json_encode([
        'success' => false,
        'error' => 'Not authenticated'
    ]);
    exit;
}

try {
    $user = current_user($pdo);
    $userId = (int)$user['id'];
    
    $badgeManager = new BadgeManager($pdo, $userId);
    
    // Get unshown notifications
    $notifications = $badgeManager->getUnshownNotifications();
    
    if (empty($notifications)) {
        echo json_encode([
            'success' => true,
            'new_badges' => []
        ]);
        exit;
    }
    
    // Mark these notifications as shown
    $notificationIds = array_column($notifications, 'notification_id');
    $badgeManager->markNotificationsShown($notificationIds);
    
    // Format badges with tier information
    $formattedBadges = [];
    
    foreach ($notifications as $badge) {
        // Extract base key from key_name (e.g., "getting_started_2" -> "getting_started")
        $baseKey = preg_replace('/_\d+$/', '', $badge['key_name']);
        
        // Get all tiers for this badge family by matching key_name pattern
        $tierStmt = $pdo->prepare("
            SELECT id, key_name, title, tier, requirement_value, points_reward
            FROM badges 
            WHERE (key_name LIKE ? OR key_name = ?)
            ORDER BY tier ASC
        ");
        $tierStmt->execute([
            $baseKey . '_%',  // Match pattern like "getting_started_1", "getting_started_2"
            $baseKey          // Also match exact if it's a single-tier badge
        ]);
        $allTiers = $tierStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $currentTier = (int)($badge['tier'] ?? 1);
        $totalTiers = count($allTiers);
        
        // Find next tier if exists
        $nextTierTitle = null;
        foreach ($allTiers as $tier) {
            if ((int)$tier['tier'] === $currentTier + 1) {
                $nextTierTitle = $tier['title'];
                break;
            }
        }
        
        $formattedBadges[] = [
            'id' => $badge['badge_id'],
            'title' => $badge['title'],
            'description' => $badge['description'],
            'icon' => $badge['icon'],
            'points_reward' => (int)$badge['points_reward'],
            'category' => $badge['category'],
            'is_rare' => in_array($badge['category'], ['mastery', 'secret']),
            'current_tier' => $currentTier,
            'total_tiers' => max(1, $totalTiers), // At least 1 tier
            'next_tier_title' => $nextTierTitle
        ];
    }
    
    echo json_encode([
        'success' => true,
        'new_badges' => $formattedBadges
    ]);
    
} catch (Exception $e) {
    error_log("Badge check API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Return error details in development
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}