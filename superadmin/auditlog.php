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

$search = trim($_GET['search'] ?? '');
$actionFilter = $_GET['action'] ?? 'all';
$entityFilter = $_GET['entity'] ?? 'all';
$roleFilter = $_GET['role'] ?? 'all';

$allLogs = getAuditLogs(500);
$actionOptions = array_values(array_unique(array_column($allLogs, 'action')));
sort($actionOptions);
$entityOptions = array_values(array_unique(array_column($allLogs, 'entity_type')));
sort($entityOptions);
$roleLabels = getUserRoles();
$roleOptions = array_keys($roleLabels);

$logs = array_values(array_filter($allLogs, function (array $log) use ($search, $actionFilter, $entityFilter, $roleFilter): bool {
    if ($actionFilter !== 'all' && $log['action'] !== $actionFilter) {
        return false;
    }
    if ($entityFilter !== 'all' && $log['entity_type'] !== $entityFilter) {
        return false;
    }
    if ($roleFilter !== 'all' && ($log['resolved_role'] ?? '') !== $roleFilter) {
        return false;
    }
    if ($search !== '') {
        $haystack = strtolower(implode(' ', [$log['actor_id'], $log['admin_name'], $log['resolved_role'], $log['action'], $log['entity_type'], $log['details']]));
        if (strpos($haystack, strtolower($search)) === false) {
            return false;
        }
    }
    return true;
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .audit-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .audit-approve, .audit-create, .audit-mark_paid, .audit-webhook_confirm, .audit-assign, .audit-issue, .audit-waive, .audit-bulk_generate, .audit-checkin { background: #10b98122; color: #10b981; }
        .audit-reject, .audit-delete { background: #ef444422; color: #ef4444; }
        .audit-update, .audit-notify, .audit-send_reminders, .audit-release, .audit-update_status, .audit-auto_login, .audit-login, .audit-checkout { background: #3b82f622; color: #60a5fa; }
    </style>
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="admin_dashboard.php" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav">
                <a href="admin_dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <a href="units.php" class="sidebar-link"><?php echo systemSidebarIcon('units'); ?> Units</a>
                <a href="residents.php" class="sidebar-link"><?php echo systemSidebarIcon('residents'); ?> Residents</a>
                <a href="pending_accounts.php" class="sidebar-link"><?php echo systemSidebarIcon('pending'); ?> Pending Accounts</a>
                <a href="staff.php" class="sidebar-link"><?php echo systemSidebarIcon('staff'); ?> Staff Management</a>
                <a href="unitpayments.php" class="sidebar-link"><?php echo systemSidebarIcon('billing'); ?> Billing & Payments</a>
                <a href="generate_bills.php" class="sidebar-link"><?php echo systemSidebarIcon('bills'); ?> Generate Bills</a>
                <a href="violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
                <a href="bookingrequest.php" class="sidebar-link"><?php echo systemSidebarIcon('calendar'); ?> Booking Requests</a>
                <a href="maintenancerequests.php" class="sidebar-link"><?php echo systemSidebarIcon('maintenance'); ?> Maintenance Requests</a>
                <a href="admin_messages.php" class="sidebar-link"><?php echo systemSidebarIcon('messages'); ?> Messages</a>
                <a href="announcements.php" class="sidebar-link"><?php echo systemSidebarIcon('announcements'); ?> Announcements</a>
                <a href="analytics.php" class="sidebar-link"><?php echo systemSidebarIcon('analytics'); ?> Analytics</a>
                <a href="parking.php" class="sidebar-link"><?php echo systemSidebarIcon('parking'); ?> Parking</a>
                <a href="auditlog.php" class="sidebar-link active"><?php echo systemSidebarIcon('audit'); ?> Audit Log</a>
                <a href="visitorlog.php" class="sidebar-link"><?php echo systemSidebarIcon('visitors'); ?> Visitor Log</a>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Audit Log</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <section class="unit-page-head">
                <div><h2>Audit Log</h2><p>Every admin approval, rejection, and change — who did it, when, and to what.</p></div>
                <div class="unit-summary"><span><?php echo count($logs); ?> of <?php echo count($allLogs); ?> shown</span></div>
            </section>

            <section class="unit-management-panel">
                <form class="unit-filters" method="get" action="auditlog.php">
                    <label for="logSearch">Search</label>
                    <input id="logSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Admin name, detail...">

                    <label for="logAction">Action</label>
                    <select id="logAction" name="action">
                        <option value="all">All actions</option>
                        <?php foreach ($actionOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $actionFilter === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $opt))); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="logEntity">Entity</label>
                    <select id="logEntity" name="entity">
                        <option value="all">All types</option>
                        <?php foreach ($entityOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $entityFilter === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $opt))); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="logRole">Role</label>
                    <select id="logRole" name="role">
                        <option value="all">All roles</option>
                        <?php foreach ($roleOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $roleFilter === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleLabels[$opt] ?? ucfirst($opt)); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">Filter</button>
                </form>

                <div class="unit-table-wrap">
                    <table class="unit-table">
                        <thead><tr><th>When</th><th>Staff ID</th><th>Role</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="7" class="unit-empty">No matching audit entries.</td></tr>
                            <?php else: foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))); ?></td>
                                    <td><?php echo htmlspecialchars($log['actor_id'] ?: 'System'); ?></td>
                                    <td><span class="audit-badge"><?php echo htmlspecialchars(ucfirst($log['resolved_role'] ?? 'Unknown')); ?></span></td>
                                    <td><span class="audit-badge audit-<?php echo htmlspecialchars($log['action']); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?></span></td>
                                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $log['entity_type'])); ?><?php if ($log['entity_id']): ?> #<?php echo (int)$log['entity_id']; ?><?php endif; ?></td>
                                    <td><?php echo htmlspecialchars($log['details'] ?: '—'); ?></td>
                                    <td><small><?php echo htmlspecialchars($log['ip_address'] ?: '—'); ?></small></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
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
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => { event.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
        document.addEventListener('click', (event) => { if (!profileMenu.contains(event.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
    </script>
</body>
</html>