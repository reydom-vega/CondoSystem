<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval(); // pending accounts get sent back to dashboard.php, which shows the pending-approval notice
requireResidentPermission('resident.announcements.view');

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

// Get all announcements
$announcements = getAnnouncements(100, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Celandine Residences</title>
    <link rel="stylesheet" href="../resident.css">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page">

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>

            <nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu">
                        <?php echo systemIcon('menu', 'menu-icon'); ?>
                    </button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">All Announcements</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span>
                                </div>
                            </div>
                            <a href="edit_profile.php" class="profile-dropdown-item">
                                <?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile
                            </a>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger">
                                <?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="announcements-container">
                <!-- Filter and Sort Bar -->
                <div class="ann-toolbar">
                    <div class="ann-filters">
                        <button type="button" class="ann-filter-btn active" onclick="filterAnnouncements('all', this)">All</button>
                        <button type="button" class="ann-filter-btn" onclick="filterAnnouncements('high', this)"><?php echo systemIcon('violations', 'announcement-filter-icon'); ?> High Priority</button>
                        <button type="button" class="ann-filter-btn" onclick="filterAnnouncements('medium', this)"><?php echo systemIcon('clock', 'announcement-filter-icon'); ?> Medium Priority</button>
                        <button type="button" class="ann-filter-btn" onclick="filterAnnouncements('low', this)"><?php echo systemIcon('check-circle', 'announcement-filter-icon'); ?> Low Priority</button>
                    </div>
                    <select class="sort-select" id="sortSelect" onchange="sortAnnouncements()">
                        <option value="recent">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="priority">Priority First</option>
                    </select>
                </div>

                <!-- Announcements List -->
                <div id="announcementsList">
                    <div class="empty-state filter-empty" id="filterEmpty">
                        <?php echo systemIconFromGlyph('🔍', 'empty-icon'); ?>
                        <div class="empty-message">No announcements in this priority</div>
                        <div class="empty-description">Try another filter to see more updates</div>
                    </div>
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <?php echo systemIconFromGlyph('📢', 'empty-icon'); ?>
                            <div class="empty-message">No Announcements Yet</div>
                            <div class="empty-description">Check back later for important updates from management</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement):
                            $priorityClass = 'priority-low';
                            if ($announcement['priority'] === 'high') $priorityClass = 'priority-high';
                            elseif ($announcement['priority'] === 'medium') $priorityClass = 'priority-medium';
                        ?>
                            <div class="announcement-card" data-priority="<?php echo $announcement['priority']; ?>" data-date="<?php echo strtotime($announcement['created_at']); ?>">
                                <div class="announcement-header">
                                    <div>
                                        <div class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></div>
                                        <div class="announcement-meta">
                                            <?php if (!empty($announcement['category'])): ?>
                                                <div class="announcement-meta-item">
                                                    <span class="category-tag"><?php echo htmlspecialchars($announcement['category']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="announcement-meta-item">
                                                <?php echo systemIcon('calendar', 'announcement-date-icon'); ?>
                                                <span><?php echo date('M d, Y', strtotime($announcement['created_at'])); ?></span>
                                            </div>
                                            <div class="announcement-meta-item">
                                                <?php echo systemIcon('clock', 'announcement-date-icon'); ?>
                                                <span><?php echo date('H:i', strtotime($announcement['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="priority-badge <?php echo $priorityClass; ?>"><?php echo strtoupper($announcement['priority']); ?></span>
                                </div>
                                <div class="announcement-content">
                                    <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                                </div>
                                <div class="announcement-footer">
                                    <span>Management Update</span>
                                    <?php if ($announcement['priority'] === 'high'): ?>
                                        <span class="announcement-attention"><?php echo systemIcon('violations', 'announcement-attention-icon'); ?> Requires Attention</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>

    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        /* Navigation is handled by the shared UI module. */

        /* Navigation is handled by the shared UI module. */

        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');

        profileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = profileMenu.classList.toggle('open');
            profileToggle.setAttribute('aria-expanded', isOpen);
        });

        document.addEventListener('click', (e) => {
            if (!profileMenu.contains(e.target)) {
                profileMenu.classList.remove('open');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                profileMenu.classList.remove('open');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });

        // Filter functionality
        function filterAnnouncements(priority, clickedBtn) {
            const cards = document.querySelectorAll('.announcement-card');
            const buttons = document.querySelectorAll('.ann-filter-btn');

            buttons.forEach(btn => btn.classList.remove('active'));
            clickedBtn.classList.add('active');

            let visible = 0;
            cards.forEach(card => {
                if (priority === 'all' || card.dataset.priority === priority) {
                    card.style.display = 'block';
                    visible++;
                } else {
                    card.style.display = 'none';
                }
            });

            const filterEmpty = document.getElementById('filterEmpty');
            if (filterEmpty) {
                filterEmpty.style.display = (cards.length > 0 && visible === 0) ? 'block' : 'none';
            }
        }

        // Sort functionality
        function sortAnnouncements() {
            const sortValue = document.getElementById('sortSelect').value;
            const container = document.getElementById('announcementsList');
            const cards = Array.from(container.querySelectorAll('.announcement-card'));

            if (sortValue === 'recent') {
                cards.sort((a, b) => parseInt(b.dataset.date) - parseInt(a.dataset.date));
            } else if (sortValue === 'oldest') {
                cards.sort((a, b) => parseInt(a.dataset.date) - parseInt(b.dataset.date));
            } else if (sortValue === 'priority') {
                const priorityOrder = { 'high': 3, 'medium': 2, 'low': 1 };
                cards.sort((a, b) => {
                    const aPriority = priorityOrder[a.dataset.priority] || 0;
                    const bPriority = priorityOrder[b.dataset.priority] || 0;
                    return bPriority - aPriority;
                });
            }

            cards.forEach(card => container.appendChild(card));
        }
    </script>
</body>
</html>
