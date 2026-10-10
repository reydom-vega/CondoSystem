<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isMaintenance()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isTreasurer() ? '../treasurer/treasurer_dashboard.php' : (isAdmin() ? '../admin/admin_dashboard.php' : '../resident/dashboard.php')));
}

$username = $_SESSION['username'] ?? 'Maintenance';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
$counts = ['active' => 0, 'pending' => 0, 'approved' => 0, 'in_progress' => 0, 'completed' => 0, 'closed' => 0, 'rejected' => 0, 'cancelled' => 0, 'reopened' => 0];
$recentRequests = [];

if (ensureMaintenanceTable($connection)) {
    $result = $connection->query("SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending, SUM(status = 'approved') AS approved, SUM(status = 'in_progress') AS in_progress, SUM(status = 'completed') AS completed, SUM(status = 'closed') AS closed, SUM(status = 'rejected') AS rejected, SUM(status = 'cancelled') AS cancelled, SUM(status = 'reopened') AS reopened FROM maintenance_requests");
    if ($result) {
        $row = $result->fetch_assoc();
        $counts['active'] = (int)$row['total'];
        $counts['pending'] = (int)$row['pending'];
        $counts['in_progress'] = (int)$row['in_progress'];
        $counts['completed'] = (int)$row['completed'];
        $counts['rejected'] = (int)$row['rejected'];
    }

    $recent = $connection->query("SELECT m.*, u.full_name, u.unit_number FROM maintenance_requests m INNER JOIN users u ON u.id = m.user_id ORDER BY m.created_at DESC LIMIT 5");
    if ($recent) {
        while ($row = $recent->fetch_assoc()) {
            $recentRequests[] = $row;
        }
    }
}

$completionRate = $counts['active'] > 0 ? round(($counts['completed'] / $counts['active']) * 100) : 0;

$statusLabels = [
    'pending' => 'Pending',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'closed' => 'Successful',
    'rejected' => 'Rejected',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Maintenance Dashboard</title>
    <link rel="stylesheet" href="../styles.css">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Maintenance Dashboard</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Maintenance</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🛠️', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo $counts['active']; ?></strong><span>Total Requests</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('◷', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $counts['pending']; ?></strong><span>Pending</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('!', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo $counts['in_progress']; ?></strong><span>In Progress</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('✓', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $counts['completed']; ?></strong><span>Completed</span></div>
            </section>

            <section class="maint-widgets-grid">
                <div class="maint-widget-card">
                    <h3>Maintenance Overview</h3>
                    <?php foreach (['pending', 'in_progress', 'completed', 'rejected'] as $key): ?>
                        <?php $pct = $counts['active'] > 0 ? round(($counts[$key] / $counts['active']) * 100) : 0; ?>
                        <div class="status-bar-row">
                            <span class="status-bar-label"><?php echo $statusLabels[$key]; ?></span>
                            <span class="status-bar-track"><span class="status-bar-fill <?php echo $key; ?>" style="width: <?php echo $pct; ?>%;"></span></span>
                            <span class="status-bar-count"><?php echo $counts[$key]; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="maint-widget-card">
                    <h3>Completion Rate</h3>
                    <div class="completion-ring-wrap">
                        <div class="completion-ring" style="--pct: <?php echo $completionRate; ?>;">
                            <div class="completion-ring-inner">
                                <strong><?php echo $completionRate; ?>%</strong>
                                <span>Completed</span>
                            </div>
                        </div>
                        <div class="completion-legend">
                            <?php echo $counts['completed']; ?> of <?php echo $counts['active']; ?> requests<br>
                            resolved to date.
                        </div>
                    </div>
                </div>
            </section>

            <section class="maint-recent-card">
                <div class="maint-recent-head">
                    <h3>Recent Maintenance Requests</h3>
                    <a href="../superadmin/maintenancerequests.php">View All →</a>
                </div>
                <div class="unit-table-wrap">
                    <table class="unit-table">
                        <thead><tr><th>Resident</th><th>Unit</th><th>Issue</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (empty($recentRequests)): ?>
                                <tr><td colspan="5" class="unit-empty">No maintenance requests found.</td></tr>
                            <?php else: foreach ($recentRequests as $request): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($request['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($request['unit_number']); ?></td>
                                    <td><?php echo htmlspecialchars($request['issue_type']); ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($request['created_at']))); ?></td>
                                    <td><span class="unit-status <?php echo htmlspecialchars($request['status']); ?>"><?php echo $statusLabels[$request['status']] ?? ucfirst($request['status']); ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script src="../js/live-updates.js"></script>
</body>
</html>
