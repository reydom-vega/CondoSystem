<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isSecurity()) {
    redirect(isMaintenance() ? '../maintenance/maintenance_dashboard.php' : (isTreasurer() ? '../treasurer/treasurer_dashboard.php' : (isAdmin() ? '../admin/admin_dashboard.php' : '../resident/dashboard.php')));
}

$username = $_SESSION['username'] ?? 'Security';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$parkingPending = 0;
$violationsOpen = 0;
$visitorsInside = 0;
$connection = connectDb();

if (ensureParkingTables($connection)) {
    $result = $connection->query("SELECT COUNT(*) AS total FROM parking_requests WHERE status = 'pending'");
    if ($result) $parkingPending = (int)$result->fetch_assoc()['total'];
}
if (ensureViolationsTable($connection)) {
    $result = $connection->query("SELECT COUNT(*) AS total FROM violations WHERE status IN ('warning_issued', 'unpaid', 'disputed')");
    if ($result) $violationsOpen = (int)$result->fetch_assoc()['total'];
}

if (ensureVisitorLogsTable($connection)) {
    $visitorsInside = countActiveVisitors($connection);
}

$recentViolations = [];
$recentParking = [];
$recentVisitors = [];

if (ensureViolationsTable($connection)) {
    $result = $connection->query("SELECT * FROM violations ORDER BY id DESC LIMIT 5");
    if ($result) { while ($row = $result->fetch_assoc()) { $recentViolations[] = $row; } }
}
if (ensureParkingTables($connection)) {
    $result = $connection->query("SELECT * FROM parking_requests ORDER BY id DESC LIMIT 5");
    if ($result) { while ($row = $result->fetch_assoc()) { $recentParking[] = $row; } }
}
if (ensureVisitorLogsTable($connection)) {
    $recentVisitors = getRecentVisitors($connection, 5);
}

function pickField($row, $keys, $fallback = '—') {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') return $row[$key];
    }
    return $fallback;
}
function statusClass($status) {
    $status = strtolower((string)$status);
    if (in_array($status, ['approved', 'resolved', 'paid', 'active'])) return 'status-good';
    if (in_array($status, ['pending', 'warning_issued'])) return 'status-warn';
    if (in_array($status, ['rejected', 'unpaid', 'disputed'])) return 'status-bad';
    return 'status-neutral';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Security Dashboard</title>
    <link rel="stylesheet" href="../security.css">
</head>
<body class="dashboard-page admin-page">
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
                    <div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Security Dashboard</h1></div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Security</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>
            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('👤', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $visitorsInside; ?></strong><span>Visitors Inside</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🚗', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo $parkingPending; ?></strong><span>Pending Parking Requests</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('⚠️', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $violationsOpen; ?></strong><span>Open Violations</span></div>
            </section>

            <section class="recent-grid" aria-label="Recent activity">
                <div class="admin-panel recent-panel">
                    <div class="recent-panel-head">
                        <h2>Recent Violations</h2>
                        <a href="../superadmin/violations.php">View all →</a>
                    </div>
                    <?php if (empty($recentViolations)): ?>
                        <p class="recent-empty">No violations recorded yet.</p>
                    <?php else: ?>
                        <ul class="recent-list">
                            <?php foreach ($recentViolations as $violation):
                                $title = pickField($violation, ['unit_number', 'unit', 'resident_name', 'title', 'type', 'violation_type'], 'Violation #' . pickField($violation, ['id'], ''));
                                $sub = pickField($violation, ['description', 'reason', 'details'], '');
                                $when = pickField($violation, ['created_at', 'date_issued', 'date'], '');
                                $status = pickField($violation, ['status'], 'unknown');
                            ?>
                            <li class="recent-item">
                                <div class="recent-item-main">
                                    <span class="recent-item-title"><?php echo htmlspecialchars($title); ?></span>
                                    <span class="recent-item-sub"><?php echo htmlspecialchars($sub !== '' ? $sub : $when); ?></span>
                                </div>
                                <span class="recent-badge <?php echo statusClass($status); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="admin-panel recent-panel">
                    <div class="recent-panel-head">
                        <h2>Recent Parking Requests</h2>
                        <a href="../superadmin/parking.php">View all →</a>
                    </div>
                    <?php if (empty($recentParking)): ?>
                        <p class="recent-empty">No parking requests yet.</p>
                    <?php else: ?>
                        <ul class="recent-list">
                            <?php foreach ($recentParking as $request):
                                $title = pickField($request, ['unit_number', 'unit', 'resident_name', 'plate_number', 'vehicle_plate'], 'Request #' . pickField($request, ['id'], ''));
                                $sub = pickField($request, ['vehicle_type', 'vehicle_model', 'plate_number'], '');
                                $when = pickField($request, ['created_at', 'date_requested', 'date'], '');
                                $status = pickField($request, ['status'], 'unknown');
                            ?>
                            <li class="recent-item">
                                <div class="recent-item-main">
                                    <span class="recent-item-title"><?php echo htmlspecialchars($title); ?></span>
                                    <span class="recent-item-sub"><?php echo htmlspecialchars($sub !== '' ? $sub : $when); ?></span>
                                </div>
                                <span class="recent-badge <?php echo statusClass($status); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="admin-panel recent-panel">
                    <div class="recent-panel-head">
                        <h2>Recent Visitors</h2>
                        <a href="visitor_log.php">View all →</a>
                    </div>
                    <?php if (empty($recentVisitors)): ?>
                        <p class="recent-empty">No visitor activity yet.</p>
                    <?php else: ?>
                        <ul class="recent-list">
                            <?php foreach ($recentVisitors as $visitor):
                                $status = $visitor['status'] === 'checked_in' ? 'inside' : 'checked out';
                                $badgeClass = $visitor['status'] === 'checked_in' ? 'status-good' : 'status-neutral';
                            ?>
                            <li class="recent-item">
                                <div class="recent-item-main">
                                    <span class="recent-item-title"><?php echo htmlspecialchars($visitor['visitor_name']); ?></span>
                                    <span class="recent-item-sub">Unit <?php echo htmlspecialchars($visitor['unit_number']); ?> · <?php echo htmlspecialchars(date('M j, g:i A', strtotime($visitor['time_in']))); ?></span>
                                </div>
                                <span class="recent-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
        <script src="../js/live-updates.js"></script>
</body>
</html>