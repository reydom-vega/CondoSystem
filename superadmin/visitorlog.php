<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('visitors.logs');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$connection = connectDb();
ensureVisitorLogsTable($connection);
ensureVisitorLogColumns($connection);

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

$allVisitors = [];
$result = $connection->query("SELECT v.*, lu.full_name AS logged_by_name, ou.full_name AS checked_out_by_name FROM visitor_logs v LEFT JOIN users lu ON lu.id = v.logged_by LEFT JOIN users ou ON ou.id = v.checked_out_by ORDER BY v.time_in DESC LIMIT 500");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allVisitors[] = $row;
    }
}

$visitors = array_values(array_filter($allVisitors, function (array $visitor) use ($search, $statusFilter): bool {
    if ($statusFilter !== 'all' && $visitor['status'] !== $statusFilter) {
        return false;
    }
    if ($search !== '') {
        $haystack = strtolower(implode(' ', [
            $visitor['visitor_name'] ?? '',
            $visitor['unit_number'] ?? '',
            $visitor['visitor_contact'] ?? '',
            $visitor['purpose'] ?? '',
        ]));
        if (strpos($haystack, strtolower($search)) === false) {
            return false;
        }
    }
    return true;
}));

$activeCount = countActiveVisitors($connection);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Visitor Log</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <section class="unit-page-head">
                <div><h2>Visitor Log</h2><p>Read-only view of every visitor logged in or out by Security. Check-in and check-out are handled from the Security section.</p></div>
                <div class="unit-summary"><span><?php echo $activeCount; ?> Currently Inside</span><span><?php echo count($visitors); ?> of <?php echo count($allVisitors); ?> shown</span></div>
            </section>

            <section class="unit-management-panel">
                <form class="unit-filters" method="get" action="visitorlog.php">
                    <label for="visitorSearch">Search</label>
                    <input id="visitorSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Visitor name, unit, contact...">

                    <label for="visitorStatus">Status</label>
                    <select id="visitorStatus" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="checked_in" <?php echo $statusFilter === 'checked_in' ? 'selected' : ''; ?>>Inside</option>
                        <option value="checked_out" <?php echo $statusFilter === 'checked_out' ? 'selected' : ''; ?>>Checked out</option>
                    </select>

                    <button type="submit">Filter</button>
                </form>

                <div class="unit-table-wrap">
                    <table class="unit-table">
                        <thead><tr><th>Visitor</th><th>Contact</th><th>Unit</th><th>Purpose</th><th>Checked In By</th><th>Time In</th><th>Checked Out By</th><th>Time Out</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (empty($visitors)): ?>
                                <tr><td colspan="9" class="unit-empty">No matching visitor entries.</td></tr>
                            <?php else: foreach ($visitors as $visitor): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($visitor['visitor_name']); ?> <small>(<?php echo (int)$visitor['visitor_count']; ?> visitors)</small></strong></td>
                                    <td><?php echo htmlspecialchars($visitor['visitor_contact'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($visitor['unit_number']); ?></td>
                                    <td><?php echo htmlspecialchars($visitor['purpose'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($visitor['logged_by_name'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($visitor['time_in']))); ?></td>
                                    <td><?php echo htmlspecialchars($visitor['checked_out_by_name'] ?: '—'); ?></td>
                                    <td><?php echo $visitor['time_out'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($visitor['time_out']))) : '—'; ?></td>
                                    <td><span class="visitor-badge <?php echo $visitor['status'] === 'checked_in' ? 'badge-in' : 'badge-out'; ?>"><?php echo $visitor['status'] === 'checked_in' ? 'Inside' : 'Checked out'; ?></span></td>
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
        /* Navigation is handled by the shared UI module. */
        /* Navigation is handled by the shared UI module. */
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => { event.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
        document.addEventListener('click', (event) => { if (!profileMenu.contains(event.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
    </script>
</body>
</html>
