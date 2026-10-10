<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isTreasurer()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : (isAdmin() ? '../admin/admin_dashboard.php' : '../resident/dashboard.php')));
}

$username = $_SESSION['username'] ?? 'Treasurer';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
$paymentTotals = ['all' => 0, 'paid' => 0, 'pending' => 0, 'overdue' => 0];
$paymentCount = 0;

if (ensureBillingTables($connection)) {
    $result = $connection->query("SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS all_total, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid_total, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_total, COALESCE(SUM(CASE WHEN status = 'overdue' THEN amount ELSE 0 END), 0) AS overdue_total FROM payments");
    if ($result) {
        $totals = $result->fetch_assoc();
        $paymentCount = (int)$totals['total'];
        $paymentTotals = [
            'all' => (float)$totals['all_total'],
            'paid' => (float)$totals['paid_total'],
            'pending' => (float)$totals['pending_total'],
            'overdue' => (float)$totals['overdue_total'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Treasurer Dashboard</title>
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
                        <h1 class="dash-title">Treasurer Dashboard</h1>
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
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Treasurer</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('▣', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo $paymentCount; ?></strong><span>Payment Records</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('✓', 'admin-stat-icon admin-icon-green'); ?><strong>₱<?php echo number_format($paymentTotals['paid'], 2); ?></strong><span>Paid</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('◷', 'admin-stat-icon admin-icon-yellow'); ?><strong>₱<?php echo number_format($paymentTotals['pending'], 2); ?></strong><span>Pending</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('!', 'admin-stat-icon admin-icon-pink'); ?><strong>₱<?php echo number_format($paymentTotals['overdue'], 2); ?></strong><span>Overdue</span></div>
            </section>

        </main>
    </div>
        <script src="../js/live-updates.js"></script>
</body>
</html>
