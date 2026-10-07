<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isAdmin()) {
    redirect('../resident/dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$residents = [];

$connection = connectDb();
$result = $connection->query("SELECT resident_id, full_name, username, email, contact_number, unit_number, is_verified, last_login_at, last_seen_at, created_at FROM users WHERE role = 'resident' ORDER BY full_name ASC");
if ($result) {
    while ($resident = $result->fetch_assoc()) {
        $haystack = strtolower(implode(' ', [$resident['full_name'], $resident['username'], $resident['email'], $resident['unit_number'], $resident['contact_number']]));
        $isActive = (int)$resident['is_verified'] === 1;

        if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
            continue;
        }
        if ($statusFilter === 'active' && !$isActive) {
            continue;
        }
        if ($statusFilter === 'pending' && $isActive) {
            continue;
        }

        $residents[] = [
            'resident_id' => trim($resident['unit_number']) !== '' ? $resident['unit_number'] : 'Unassigned',
            'name' => $resident['full_name'],
            'username' => $resident['username'],
            'unit' => trim($resident['unit_number']) !== '' ? $resident['unit_number'] : 'Unassigned',
            'contact' => $resident['contact_number'],
            'email' => $resident['email'],
            'status' => $isActive ? 'Active' : 'Pending',
            'last_seen_at' => $resident['last_seen_at'] ?? null,
            'last_login_at' => $resident['last_login_at'] ?? null,
            'created_at' => $resident['created_at'],
        ];
    }
}

$activeCount = count(array_filter($residents, static fn (array $resident): bool => $resident['status'] === 'Active'));
$pendingCount = count($residents) - $activeCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="admin_dashboard.php" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav">
                <a href="admin_dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <a href="units.php" class="sidebar-link"><?php echo systemSidebarIcon('units'); ?> Units</a>
                <a href="residents.php" class="sidebar-link active"><?php echo systemSidebarIcon('residents'); ?> Residents</a>
                <?php if (isSuperAdmin()): ?>
                    <a href="pending_accounts.php" class="sidebar-link"><?php echo systemSidebarIcon('pending'); ?> Pending Accounts</a>
                    <a href="staff.php" class="sidebar-link"><?php echo systemSidebarIcon('staff'); ?> Staff Management</a>
                    <a href="unitpayments.php" class="sidebar-link"><?php echo systemSidebarIcon('billing'); ?> Billing &amp; Payments</a>
                    <a href="generate_bills.php" class="sidebar-link"><?php echo systemSidebarIcon('bills'); ?> Generate Bills</a>
                    <a href="violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
                <?php endif; ?>
                <a href="bookingrequest.php" class="sidebar-link"><?php echo systemSidebarIcon('calendar'); ?> Booking Requests</a>
                <a href="maintenancerequests.php" class="sidebar-link"><?php echo systemSidebarIcon('maintenance'); ?> Maintenance Requests</a>
                <a href="admin_messages.php" class="sidebar-link"><?php echo systemSidebarIcon('messages'); ?> Messages</a>
                <a href="announcements.php" class="sidebar-link"><?php echo systemSidebarIcon('announcements'); ?> Announcements</a>
                <?php if (isSuperAdmin()): ?><a href="analytics.php" class="sidebar-link"><?php echo systemSidebarIcon('analytics'); ?> Analytics</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="parking.php" class="sidebar-link"><?php echo systemSidebarIcon('parking'); ?> Parking</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="auditlog.php" class="sidebar-link"><?php echo systemSidebarIcon('audit'); ?> Audit Log</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="visitorlog.php" class="sidebar-link"><?php echo systemSidebarIcon('visitors'); ?> Visitor Log</a><?php endif; ?>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Residents</h1></div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="unit-page-head">
                <div><h2>Residents</h2><p>View resident contact and account information.</p></div>
                <div class="unit-summary"><span><?php echo $activeCount; ?> Active</span><span><?php echo $pendingCount; ?> Pending</span></div>
            </section>

            <section class="unit-management-panel">
                <form class="unit-filters" method="get" action="residents.php">
                    <label for="residentSearch">Search residents</label>
                    <input id="residentSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search resident or unit...">
                    <label for="residentStatus">Filter by status</label>
                    <select id="residentStatus" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All residents</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                    <button type="submit">Search</button>
                </form>
                <div class="unit-table-wrap">
                    <table class="unit-table resident-table">
                        <thead><tr><th>Unit ID</th><th>Name</th><th>Unit Number</th><th>Contact</th><th>Email</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (empty($residents)): ?>
                                <tr><td colspan="6" class="unit-empty">No resident information matches your search.</td></tr>
                            <?php else: ?>
                                <?php foreach ($residents as $resident): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($resident['resident_id']); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($resident['name']); ?></strong><small>@<?php echo htmlspecialchars($resident['username']); ?></small></td>
                                        <td><?php echo htmlspecialchars($resident['unit']); ?></td>
                                        <td><?php echo htmlspecialchars($resident['contact']); ?></td>
                                        <td><?php echo htmlspecialchars($resident['email']); ?></td>
                                        <td>
                                            <?php $presence = userPresenceSummary($resident['last_seen_at'] ?? null, $resident['last_login_at'] ?? null); ?>
                                            <span class="unit-status <?php echo $presence['online'] ? 'online' : 'offline'; ?>"><?php echo htmlspecialchars($presence['label']); ?></span>
                                            <small class="staff-last-login"><?php echo htmlspecialchars($presence['detail']); ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
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
