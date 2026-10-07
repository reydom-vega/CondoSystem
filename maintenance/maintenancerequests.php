<?php
require_once '../config.php';

if (isLoggedIn() && isMaintenance()) {
    redirect('../superadmin/maintenancerequests.php');
}

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isAdmin() || isTreasurer() || isSecurity()) {
    redirect('../resident/dashboard.php');
}

$username = $_SESSION['username'] ?? 'Maintenance';
$roleLabel = getUserRoles()[$_SESSION['role'] ?? 'maintenance'] ?? 'Maintenance';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
$tableReady = ensureMaintenanceTable($connection);
$statusFilter = $_GET['status'] ?? 'all';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$tableReady) {
        $errors[] = 'Maintenance request storage is unavailable.';
    } elseif ($requestId <= 0 || !in_array($status, ['pending', 'in_progress', 'completed', 'rejected'], true)) {
        $errors[] = 'Invalid maintenance request update.';
    } else {
        $update = $connection->prepare('UPDATE maintenance_requests SET status = ? WHERE id = ?');
        $update->bind_param('si', $status, $requestId);
        if (!$update->execute()) {
            $errors[] = 'Unable to update the request status.';
        } else {
            logAudit($status === 'completed' ? 'approve' : ($status === 'rejected' ? 'reject' : 'update'), 'maintenance_request', $requestId, 'Maintenance request status set to ' . $status);
        }
    }
}

$requests = [];
if ($tableReady) {
    $result = $connection->query('SELECT m.*, u.full_name, u.username, u.unit_number FROM maintenance_requests m INNER JOIN users u ON u.id = m.user_id ORDER BY m.created_at DESC');
    if ($result) {
        while ($request = $result->fetch_assoc()) {
            if ($statusFilter === 'all' || $request['status'] === $statusFilter) {
                $requests[] = $request;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Maintenance Requests</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo isMaintenance() ? '../maintenance/maintenance_dashboard.php' : 'admin_dashboard.php'; ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav">
                <?php if (isMaintenance()): ?>
                    <a href="../maintenance/maintenance_dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                    <a href="../maintenance/maintenancerequests.php" class="sidebar-link active"><?php echo systemSidebarIcon('maintenance'); ?> Maintenance Requests</a>
                <?php else: ?>
                <a href="admin_dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <a href="units.php" class="sidebar-link"><?php echo systemSidebarIcon('units'); ?> Units</a>
                <a href="residents.php" class="sidebar-link"><?php echo systemSidebarIcon('residents'); ?> Residents</a>
                <?php if (isSuperAdmin()): ?>
                    <a href="pending_accounts.php" class="sidebar-link"><?php echo systemSidebarIcon('pending'); ?> Pending Accounts</a>
                    <a href="staff.php" class="sidebar-link"><?php echo systemSidebarIcon('staff'); ?> Staff Management</a>
                    <a href="unitpayments.php" class="sidebar-link"><?php echo systemSidebarIcon('billing'); ?> Billing & Payments</a>
                    <a href="generate_bills.php" class="sidebar-link"><?php echo systemSidebarIcon('bills'); ?> Generate Bills</a>
                    <a href="violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
                <?php endif; ?>
                <a href="bookingrequest.php" class="sidebar-link"><?php echo systemSidebarIcon('calendar'); ?> Booking Requests</a>
                <a href="maintenancerequests.php" class="sidebar-link active"><?php echo systemSidebarIcon('maintenance'); ?> Maintenance Requests</a>
                <a href="admin_messages.php" class="sidebar-link"><?php echo systemSidebarIcon('messages'); ?> Messages</a>
                <a href="announcements.php" class="sidebar-link"><?php echo systemSidebarIcon('announcements'); ?> Announcements</a>
                <?php if (isSuperAdmin()): ?><a href="analytics.php" class="sidebar-link"><?php echo systemSidebarIcon('analytics'); ?> Analytics</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="parking.php" class="sidebar-link"><?php echo systemSidebarIcon('parking'); ?> Parking</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="auditlog.php" class="sidebar-link"><?php echo systemSidebarIcon('audit'); ?> Audit Log</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="visitorlog.php" class="sidebar-link"><?php echo systemSidebarIcon('visitors'); ?> Visitor Log</a><?php endif; ?>
                <?php endif; ?>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Maintenance Requests</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit"><?php echo htmlspecialchars($roleLabel); ?></span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <section class="unit-page-head"><div><h2>Maintenance Requests</h2><p>Review resident issues and update their progress.</p></div></section>
            <section class="unit-management-panel">
                <form class="unit-filters" method="get" action="maintenancerequests.php">
                    <label for="requestStatus">Filter requests</label>
                    <select id="requestStatus" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <button type="submit">Filter</button>
                </form>
                <div class="unit-table-wrap">
                    <table class="unit-table">
                        <thead><tr><th>Resident</th><th>Unit</th><th>Issue</th><th>Description</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (empty($requests)): ?><tr><td colspan="6" class="unit-empty">No maintenance requests found.</td></tr>
                            <?php else: foreach ($requests as $request): ?><tr>
                                <td><strong><?php echo htmlspecialchars($request['full_name']); ?></strong><small>@<?php echo htmlspecialchars($request['username']); ?></small></td>
                                <td><?php echo htmlspecialchars($request['unit_number']); ?></td>
                                <td><?php echo htmlspecialchars($request['issue_type']); ?></td>
                                <td class="request-description"><?php echo htmlspecialchars($request['description']); ?></td>
                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime($request['created_at']))); ?></td>
                                <td><form method="post" class="status-form"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>"><select name="status" aria-label="Update request status" onchange="this.form.submit()"><option value="pending" <?php echo $request['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="in_progress" <?php echo $request['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option><option value="completed" <?php echo $request['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option><option value="rejected" <?php echo $request['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option></select></form></td>
                            </tr><?php endforeach; endif; ?>
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
