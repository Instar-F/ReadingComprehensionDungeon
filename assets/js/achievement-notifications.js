/**
 * Minecraft-Style Achievement Notification System
 * Supports multiple simultaneous notifications with stacking
 */

class AchievementNotificationSystem {
    constructor() {
        this.activeNotifications = [];
        this.soundEnabled = true;
        this.autoCheckInterval = null;
        this.apiEndpoint = '../api/check_badges.php';
        this.maxVisibleNotifications = 5;
        this.notificationDuration = 3000; // 3 seconds (was 4)
        this.stackDelay = 50; // 50ms delay between notifications (was 150ms)
        
        // Preload sounds
        this.sounds = {
            normal: new Audio('../assets/sound/minecraft-achievement.mp3'),
            rare: new Audio('../assets/sound/minecraft-rare-achievement.mp3')
        };
        
        // Configure sounds
        Object.values(this.sounds).forEach(sound => {
            sound.volume = 0.5;
            sound.preload = 'auto';
        });
        
        // Don't start auto-checking - only check on demand
        // Auto-check disabled to save resources
    }
    
    /**
     * Show multiple achievements with stacking animation
     */
    showMultiple(badges) {
        if (!badges || badges.length === 0) return;
        
        // Show them one by one with slight delay for stacking effect
        badges.forEach((badge, index) => {
            setTimeout(() => {
                this.show(badge);
            }, index * this.stackDelay);
        });
    }
    
    /**
     * Show a single achievement notification
     */
    show(badge) {
        // Remove oldest if we have too many
        if (this.activeNotifications.length >= this.maxVisibleNotifications) {
            const oldest = this.activeNotifications[0];
            this.hide(oldest.element, true);
        }
        
        const notification = this.createNotificationElement(badge);
        document.body.appendChild(notification);
        
        const notificationData = {
            element: notification,
            badge: badge,
            timestamp: Date.now()
        };
        
        this.activeNotifications.push(notificationData);
        
        // Play sound
        this.playSound(badge.is_rare);
        
        // Show with animation
        requestAnimationFrame(() => {
            notification.classList.add('show');
        });
        
        // Auto-hide after duration
        setTimeout(() => {
            this.hide(notification);
        }, this.notificationDuration);
        
        return notificationData;
    }
    
    /**
     * Play achievement sound
     */
    playSound(isRare = false) {
        if (!this.soundEnabled) return;
        
        try {
            const sound = isRare ? this.sounds.rare : this.sounds.normal;
            
            // Clone the audio to allow multiple simultaneous plays
            const audioClone = sound.cloneNode();
            audioClone.volume = sound.volume;
            audioClone.play().catch(err => {
                console.warn('Could not play achievement sound:', err);
            });
        } catch (e) {
            console.warn('Error playing sound:', e);
        }
    }
    
    /**
     * Create notification DOM element
     */
    createNotificationElement(badge) {
        const notification = document.createElement('div');
        notification.className = 'achievement-notification';
        
        if (badge.is_rare) {
            notification.classList.add('rare');
        }
        
        // Build stars HTML for tiers
        let tierInfoHtml = '';
        if (badge.total_tiers && badge.total_tiers > 1) {
            // Show completed stars (⭐) and empty stars (★)
            let starsHtml = '<div class="achievement-stars">';
            for (let i = 1; i <= badge.total_tiers; i++) {
                if (i <= badge.current_tier) {
                    starsHtml += '<span class="achievement-star">⭐</span>'; // Filled star for completed
                } else {
                    starsHtml += '<span class="achievement-star empty">★</span>'; // Empty star for incomplete
                }
            }
            starsHtml += '</div>';
            
            tierInfoHtml = `
                <div class="achievement-tier-info">
                    ${starsHtml}
                    <div class="achievement-points">+${badge.points_reward} XP</div>
                </div>
            `;
            
            // Show next tier if not complete
            if (badge.next_tier_title && badge.current_tier < badge.total_tiers) {
                tierInfoHtml += `
                    <div class="achievement-next-tier">
                        Next: ${this.escapeHtml(badge.next_tier_title)}
                    </div>
                `;
            }
        } else {
            // Single tier badge
            tierInfoHtml = `
                <div class="achievement-tier-info">
                    <div class="achievement-points">+${badge.points_reward} XP</div>
                </div>
            `;
        }
        
        const headerText = badge.is_rare ? 'Challenge Complete!' : 'Achievement Unlocked!';
        const headerClass = badge.is_rare ? 'rare' : '';
        
        notification.innerHTML = `
            <div class="achievement-icon">
                🏆
            </div>
            <div class="achievement-content">
                <div class="achievement-header ${headerClass}">${headerText}</div>
                <div class="achievement-title">${this.escapeHtml(badge.title)}</div>
                ${tierInfoHtml}
            </div>
        `;
        
        // Click to dismiss
        notification.addEventListener('click', () => {
            this.hide(notification);
        });
        
        return notification;
    }
    
    /**
     * Hide notification
     */
    hide(notification, skipAnimation = false) {
        // Find and remove from active list
        const index = this.activeNotifications.findIndex(n => n.element === notification);
        if (index !== -1) {
            this.activeNotifications.splice(index, 1);
        }
        
        notification.classList.remove('show');
        notification.classList.add('hide');
        
        const removeDelay = skipAnimation ? 0 : 300;
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
            
            // Reposition remaining notifications
            this.repositionNotifications();
        }, removeDelay);
    }
    
    /**
     * Reposition active notifications after one is removed
     */
    repositionNotifications() {
        this.activeNotifications.forEach((notificationData, index) => {
            const topPosition = 20 + (index * 65); // 65px spacing for compact notifications
            notificationData.element.style.top = topPosition + 'px';
        });
    }
    
    /**
     * Check for new badges from server
     */
    async checkNewBadges() {
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to check badges');
            }
            
            const data = await response.json();
            
            if (data.success && data.new_badges && data.new_badges.length > 0) {
                // Show all new badges with stacking effect
                this.showMultiple(data.new_badges);
            }
        } catch (error) {
            console.error('Error checking badges:', error);
        }
    }
    
    /**
     * Start auto-checking for badges
     */
    startAutoCheck(interval = 30000) {
        // Only check every 30 seconds as backup
        // Main checking should be done via triggerCheck() after exercises
        
        if (this.autoCheckInterval) {
            clearInterval(this.autoCheckInterval);
        }
        
        this.autoCheckInterval = setInterval(() => {
            this.checkNewBadges();
        }, interval);
    }
    
    /**
     * Manually trigger badge check (call after completing exercise)
     * This is the primary way badges should be checked
     */
    triggerCheck() {
        return this.checkNewBadges();
    }
    
    /**
     * Trigger check immediately on page load and after user action
     */
    triggerImmediateCheck() {
        // Check immediately without delay
        setTimeout(() => {
            this.checkNewBadges();
        }, 100); // Tiny delay to ensure backend processing is done
    }
    
    /**
     * Clear all notifications
     */
    clearAll() {
        this.activeNotifications.forEach(notificationData => {
            this.hide(notificationData.element, true);
        });
        this.activeNotifications = [];
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Toggle sound on/off
     */
    toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        return this.soundEnabled;
    }
    
    /**
     * Set sound volume (0.0 to 1.0)
     */
    setVolume(volume) {
        volume = Math.max(0, Math.min(1, volume));
        Object.values(this.sounds).forEach(sound => {
            sound.volume = volume;
        });
    }
}

// Initialize global achievement system
let achievementSystem;

// Create a helper function that's always available
window.triggerAchievementCheck = function() {
    if (window.achievementSystem && typeof window.achievementSystem.triggerImmediateCheck === 'function') {
        window.achievementSystem.triggerImmediateCheck();
    } else {
        // Wait a bit and retry
        setTimeout(() => {
            if (window.achievementSystem && typeof window.achievementSystem.triggerImmediateCheck === 'function') {
                window.achievementSystem.triggerImmediateCheck();
            }
        }, 500);
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        achievementSystem = new AchievementNotificationSystem();
        window.achievementSystem = achievementSystem;
    });
} else {
    achievementSystem = new AchievementNotificationSystem();
    window.achievementSystem = achievementSystem;
}

window.AchievementNotificationSystem = AchievementNotificationSystem;