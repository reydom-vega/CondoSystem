/**
 * Live Frontend Updates
 * Automatically updates UI elements without page reload
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        pollInterval: 3000,  // 3 seconds
        isAdmin: document.body.classList.contains('admin-page')
    };

    // State
    let isVisible = !document.hidden;
    let lastUpdate = {};

    /**
     * Get current notification counts
     */
    function getNotificationCounts() {
        const baseUrl = window.location.pathname.includes('Condo_System')
            ? '/Condo_System/api/'
            : '/api/';
        const endpoint = CONFIG.isAdmin ? 'admin_dashboard.php' : 'dashboard.php';
        
        return fetch(baseUrl + endpoint + '?t=' + Date.now())
            .then(response => response.json())
            .catch(error => {
                console.error('Failed to fetch updates:', error);
                return null;
            });
    }

    /**
     * Update notification badge in sidebar/header
     */
    function updateNotificationBadges(data) {
        if (!data || !data.success) return;

        // Update message notification
        if (data.unread_messages !== undefined && data.unread_messages > 0) {
            updateBadge('messages-badge', data.unread_messages);
        }

        // Update maintenance notification (resident only)
        if (!CONFIG.isAdmin && data.active_maintenance !== undefined && data.active_maintenance > 0) {
            updateBadge('maintenance-badge', data.active_maintenance);
        }

        // Update maintenance notification (admin)
        if (CONFIG.isAdmin && data.pending_maintenance !== undefined && data.pending_maintenance > 0) {
            updateBadge('maintenance-badge', data.pending_maintenance);
        }

        // Update booking notifications (admin only)
        if (CONFIG.isAdmin && data.pending_bookings !== undefined && data.pending_bookings > 0) {
            updateBadge('bookings-badge', data.pending_bookings);
        }

        // Update violations (admin only)
        if (CONFIG.isAdmin && data.unresolved_violations !== undefined && data.unresolved_violations > 0) {
            updateBadge('violations-badge', data.unresolved_violations);
        }

        // Update announcement count (resident)
        if (!CONFIG.isAdmin && data.announcements !== undefined && data.announcements > 0) {
            updateBadge('announcements-badge', data.announcements);
        }

        // Update payment due notifications (resident)
        if (!CONFIG.isAdmin && data.due_payments !== undefined && data.due_payments > 0) {
            updateBadge('payments-badge', data.due_payments);
        }
    }

    /**
     * Create or update a notification badge
     */
    function updateBadge(badgeId, count) {
        let badge = document.getElementById(badgeId);
        if (!badge) {
            return;  // Badge element doesn't exist on this page
        }

        const oldCount = parseInt(badge.textContent) || 0;
        if (oldCount !== count) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-flex' : 'none';
            
            // Add animation effect
            badge.style.animation = 'none';
            setTimeout(() => {
                badge.style.animation = 'pulse 0.5s ease-in-out';
            }, 10);
        }
    }

    /**
     * Check for updates
     */
    function checkForUpdates() {
        if (!isVisible) return;

        getNotificationCounts().then(data => {
            if (data && data.success) {
                updateNotificationBadges(data);
                lastUpdate = data;
            }
        });
    }

    /**
     * Start polling for updates
     */
    function startPolling() {
        // Initial check
        checkForUpdates();

        // Set up interval
        setInterval(() => {
            if (isVisible) {
                checkForUpdates();
            }
        }, CONFIG.pollInterval);
    }

    /**
     * Pause polling when page is hidden, resume when visible
     */
    document.addEventListener('visibilitychange', () => {
        isVisible = !document.hidden;
        if (isVisible) {
            checkForUpdates();
        }
    });

    // Start polling when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startPolling);
    } else {
        startPolling();
    }
})();
