<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isSecurity()) {
    redirect(isMaintenance() ? '../maintenance/maintenance_dashboard.php' : (isTreasurer() ? '../treasurer/treasurer_dashboard.php' : (isAdmin() ? '../admin/admin_dashboard.php' : '../resident/dashboard.php')));
}

$connection = connectDb();
ensureVisitorLogsTable($connection);
ensureVisitorLogColumns($connection);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'checkin') {
        $visitorName = trim($_POST['visitor_name'] ?? '');
        $visitorContact = trim($_POST['visitor_contact'] ?? '');
        $unitNumber = trim($_POST['unit_number'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');

        if ($visitorName === '' || $unitNumber === '') {
            setFlash('error', 'Visitor name and unit number are required.');
        } else {
            $loggedBy = (int)($_SESSION['user_id'] ?? 0);
            $newVisitorId = logVisitorIn($connection, $visitorName, $visitorContact, $unitNumber, $purpose, $loggedBy);
            if ($newVisitorId !== false) {
                logAudit('checkin', 'visitor', $newVisitorId, 'Logged in visitor ' . $visitorName . ' for unit ' . $unitNumber);
                setFlash('success', htmlspecialchars($visitorName) . ' has been logged in.');
            } else {
                setFlash('error', 'Could not log the visitor in. Please try again.');
            }
        }
    } elseif ($action === 'checkout') {
        $visitorLogId = (int)($_POST['visitor_log_id'] ?? 0);
        $checkedOutBy = (int)($_SESSION['user_id'] ?? 0);
        if ($visitorLogId > 0 && logVisitorOut($connection, $visitorLogId, $checkedOutBy)) {
            logAudit('checkout', 'visitor', $visitorLogId, 'Checked out visitor entry #' . $visitorLogId);
            setFlash('success', 'Visitor checked out.');
        } else {
            setFlash('error', 'Could not check out that visitor.');
        }
    }

    redirect('visitor_log.php');
}

$username = $_SESSION['username'] ?? 'Security';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$activeVisitors = getActiveVisitors($connection);
$recentVisitors = getRecentVisitors($connection, 10);
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Visitor Log</title>
    <link rel="stylesheet" href="../security.css">
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="security_dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav">
                <a href="security_dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <a href="scanner.php" class="sidebar-link"><?php echo systemSidebarIcon('scanner'); ?> QR Scanner</a>
                <a href="visitor_log.php" class="sidebar-link active"><?php echo systemSidebarIcon('visitors'); ?> Visitor Log</a>
                <a href="../superadmin/parking.php" class="sidebar-link"><?php echo systemSidebarIcon('parking'); ?> Parking Requests</a>
                <a href="../superadmin/violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Visitor Log</h1></div>
                </div>
                <div class="dash-header-right">
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Security</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <?php if ($flash): ?>
                <div class="flash-box flash-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'error' : 'success'); ?>"><?php echo $flash['message']; ?></div>
            <?php endif; ?>

            <section class="visitor-layout">
                <div class="admin-panel visitor-panel">
                    <h2>Log a Visitor In</h2>
                    <form method="post" class="visitor-form">
                        <input type="hidden" name="action" value="checkin">
                        <label for="visitor_name">Visitor name</label>
                        <input type="text" id="visitor_name" name="visitor_name" placeholder="e.g. Juan Dela Cruz" required maxlength="120">
                        <label for="visitor_contact">Contact number (optional)</label>
                        <input type="text" id="visitor_contact" name="visitor_contact" placeholder="e.g. 09171234567" maxlength="30">
                        <label for="unit_number">Unit to visit</label>
                        <input type="text" id="unit_number" name="unit_number" placeholder="e.g. 12A" required maxlength="20">
                        <label for="purpose">Purpose (optional)</label>
                        <input type="text" id="purpose" name="purpose" placeholder="e.g. Delivery, family visit" maxlength="150">
                        <button type="submit">Log Visitor In</button>
                    </form>
                </div>

                <div class="admin-panel visitor-panel">
                    <h2>Currently Inside (<?php echo count($activeVisitors); ?>)</h2>
                    <?php if (empty($activeVisitors)): ?>
                        <p class="visitor-empty">No visitors currently checked in.</p>
                    <?php else: ?>
                        <table class="visitor-table">
                            <thead><tr><th>Visitor</th><th>Unit</th><th>Time In</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($activeVisitors as $visitor): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($visitor['visitor_name']); ?>
                                        <?php if (!empty($visitor['purpose'])): ?><br><span style="color: var(--text-muted); font-size: 11px;"><?php echo htmlspecialchars($visitor['purpose']); ?></span><?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($visitor['unit_number']); ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, g:i A', strtotime($visitor['time_in']))); ?></td>
                                    <td>
                                        <form method="post" style="margin: 0;">
                                            <input type="hidden" name="action" value="checkout">
                                            <input type="hidden" name="visitor_log_id" value="<?php echo (int)$visitor['id']; ?>">
                                            <button type="submit" class="visitor-checkout-btn">Check Out</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <div class="visitor-history">
                        <h2>Recent Activity</h2>
                        <?php if (empty($recentVisitors)): ?>
                            <p class="visitor-empty">No visitor activity yet.</p>
                        <?php else: ?>
                            <table class="visitor-table">
                                <thead><tr><th>Visitor</th><th>Unit</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentVisitors as $visitor): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($visitor['visitor_name']); ?></td>
                                        <td><?php echo htmlspecialchars($visitor['unit_number']); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, g:i A', strtotime($visitor['time_in']))); ?></td>
                                        <td>
                                            <?php echo $visitor['time_out'] ? htmlspecialchars(date('M j, g:i A', strtotime($visitor['time_out']))) : '—'; ?>
                                            <?php if (!empty($visitor['checked_out_by_name'])): ?><br><span style="color: var(--text-muted); font-size: 11px;">by <?php echo htmlspecialchars($visitor['checked_out_by_name']); ?></span><?php endif; ?>
                                        </td>
                                        <td><span class="visitor-badge <?php echo $visitor['status'] === 'checked_in' ? 'badge-in' : 'badge-out'; ?>"><?php echo $visitor['status'] === 'checked_in' ? 'Inside' : 'Checked out'; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <script src="../js/profile-menu.js"></script>
    <script src="../js/notification-menu.js"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
    </script>
</body>
</html>