<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isSuperAdmin()) {
    redirect('../resident/dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();

$totalResidents = 0;
$totalUnits = getUnitInventoryCount();
if ($totalUnits <= 0) {
    $totalUnits = 1;
}
$occupiedUnits = 0;
$vacantUnits = $totalUnits;
$monthlyCollections = 0;
$overduePayments = 0;
$activeMaintenance = 0;
$occupancyRate = 0;
$recentResidents = [];
$residentResult = $connection->query("SELECT full_name, username, unit_number, created_at FROM users WHERE role = 'resident' ORDER BY created_at DESC LIMIT 5");
if ($residentResult) {
    $recentResidents = $residentResult->fetch_all(MYSQLI_ASSOC);
}

$countResult = $connection->query("SELECT COUNT(*) AS total FROM users WHERE role = 'resident'");
if ($countResult) {
    $countData = $countResult->fetch_assoc();
    $totalResidents = (int)($countData['total'] ?? 0);
}

$residentRows = $connection->query("SELECT unit_number FROM users WHERE role = 'resident' AND unit_number IS NOT NULL AND TRIM(unit_number) <> ''");
$occupiedSet = [];
if ($residentRows) {
    while ($residentRow = $residentRows->fetch_assoc()) {
        $unitNumber = normalizeUnitNumber((string) ($residentRow['unit_number'] ?? ''));
        if ($unitNumber !== '') {
            $occupiedSet[$unitNumber] = true;
        }
    }
}

$inventoryUnits = [];
foreach (loadUnitInventory() as $unit) {
    $inventoryUnits[] = normalizeUnitNumber((string) ($unit['unit_number'] ?? ''));
}
$validOccupiedUnits = array_values(array_intersect(array_keys($occupiedSet), $inventoryUnits));
$occupiedUnits = count($validOccupiedUnits);
$vacantUnits = max(0, $totalUnits - $occupiedUnits);
$occupancyRate = $totalUnits > 0 ? (int)round(($occupiedUnits / $totalUnits) * 100) : 0;
if (ensurePaymentsTable($connection)) {
    $paymentMetrics = $connection->query("SELECT COALESCE(SUM(CASE WHEN status = 'paid' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END), 0) AS collections, SUM(status = 'overdue') AS overdue FROM payments");
    if ($paymentMetrics) {
        $metrics = $paymentMetrics->fetch_assoc();
        $monthlyCollections = (float)$metrics['collections'];
        $overduePayments = (int)$metrics['overdue'];
    }
}
if (ensureMaintenanceTable($connection)) {
    $maintenanceMetrics = $connection->query("SELECT COUNT(*) AS active FROM maintenance_requests WHERE status IN ('pending', 'approved', 'in_progress', 'reopened')");
    if ($maintenanceMetrics) {
        $activeMaintenance = (int)$maintenanceMetrics->fetch_assoc()['active'];
    }
}
$unreadCount = 0;
if (ensureMessagesTable($connection)) {
    $unreadResult = $connection->query("SELECT COUNT(*) AS unread FROM messages WHERE sender_role = 'resident' AND is_read = 0");
    if ($unreadResult) {
        $unreadCount = (int)$unreadResult->fetch_assoc()['unread'];
    }
}

// Dashboard counts for booking requests and violations.
$bookingRequests = 0;
$violations = 0;

// Detect the existing tables so the dashboard remains compatible with the project schema.
$tableResult = $connection->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE '%booking%' OR table_name LIKE '%violation%')");
if ($tableResult) {
    while ($table = $tableResult->fetch_assoc()) {
        $tableName = $table['table_name'];
        $safeTableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        if (stripos($safeTableName, 'booking') !== false) {
            $result = $connection->query("SELECT COUNT(*) AS total FROM `{$safeTableName}`");
            if ($result) {
                $bookingRequests = (int)$result->fetch_assoc()['total'];
            }
        } elseif (stripos($safeTableName, 'violation') !== false) {
            $result = $connection->query("SELECT COUNT(*) AS total FROM `{$safeTableName}`");
            if ($result) {
                $violations = (int)$result->fetch_assoc()['total'];
            }
        }
    }
}

// Get analytics data
$analyticsData = getAnalytics();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Admin Dashboard</title>
    <link rel="stylesheet" href="../styles.css">
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
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Admin Dashboard</h1>
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
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('⌂', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo number_format($totalUnits); ?></strong><span>Total Units</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('♙', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $occupiedUnits; ?></strong><span>Occupied Units</span><em><?php echo $occupancyRate; ?>%</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('⌂', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $vacantUnits; ?></strong><span>Vacant Units</span><em><?php echo 100 - $occupancyRate; ?>%</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('▣', 'admin-stat-icon admin-icon-yellow'); ?><strong>₱<?php echo number_format($monthlyCollections, 2); ?></strong><span>Monthly Collections</span><em class="admin-warning"><?php echo $overduePayments; ?> Overdue</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('!', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo $activeMaintenance; ?></strong><span>Maintenance Requests</span><em class="admin-warning"><?php echo $activeMaintenance; ?> Active</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('📅', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo $bookingRequests; ?></strong><span>Booking Requests</span><em><?php echo $bookingRequests; ?> Total</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('⚠️', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $violations; ?></strong><span>Violations</span><em class="admin-warning"><?php echo $violations; ?> Total</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('💬', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $unreadCount; ?></strong><span>Messages</span><em><?php echo $unreadCount; ?> Unread</em></div>
            </section>

            <section class="admin-overview-grid">
                <div class="admin-panel" id="residents">
                    <h2>Recent Residents</h2>
                    <?php if (empty($recentResidents)): ?>
                        <p class="admin-empty">No residents have registered yet.</p>
                    <?php else: ?>
                        <?php foreach ($recentResidents as $resident): ?>
                            <div class="admin-list-row"><div><strong><?php echo htmlspecialchars($resident['unit_number']); ?> - <?php echo htmlspecialchars($resident['full_name']); ?></strong><small><?php echo htmlspecialchars($resident['username']); ?></small></div><span class="admin-pill admin-pill-success">Active</span></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="admin-panel" id="payments">
                    <h2>Outstanding Dues</h2>
                    <div class="admin-list-row"><div><strong><?php echo $overduePayments; ?> overdue bill<?php echo $overduePayments === 1 ? '' : 's'; ?></strong><small>Review balances and mark manual payments in Billing &amp; Payments.</small></div><a href="unitpayments.php" class="admin-pill <?php echo $overduePayments > 0 ? 'admin-pill-warning' : 'admin-pill-success'; ?>">View</a></div>
                    <div class="admin-list-row"><div><strong>Message alerts</strong><small><?php echo $unreadCount; ?> unread resident conversation<?php echo $unreadCount === 1 ? '' : 's'; ?>.</small></div><a href="admin_messages.php" class="admin-pill admin-pill-info">View</a></div>
                </div>
            </section>
        </main>
    </div>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
    </script>

    <script>
    </script>

    <script src="../js/live-updates.js"></script>
</body>
</html>