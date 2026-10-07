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
$connection = connectDb();
$tableReady = ensureBookingsTable($connection);
$errors = [];
$statusFilter = $_GET['status'] ?? 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$tableReady) {
        $errors[] = 'Booking storage is unavailable.';
    } elseif ($bookingId <= 0 || !in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
        $errors[] = 'Invalid booking update.';
    } else {
        $update = $connection->prepare('UPDATE bookings SET status = ? WHERE id = ?');
        $update->bind_param('si', $status, $bookingId);
        if (!$update->execute()) {
            $errors[] = 'Unable to update the booking status.';
        } else {
            logAudit($status === 'confirmed' ? 'approve' : ($status === 'cancelled' ? 'reject' : 'update'), 'booking', $bookingId, 'Booking status set to ' . $status);
        }
    }
}

$bookings = [];
if ($tableReady) {
    $result = $connection->query('SELECT b.*, u.full_name, u.username, u.unit_number, u.contact_number FROM bookings b INNER JOIN users u ON u.id = b.user_id ORDER BY b.booking_date ASC, b.booking_time ASC');
    if ($result) {
        while ($booking = $result->fetch_assoc()) {
            if ($statusFilter === 'all' || $booking['status'] === $statusFilter) {
                $bookings[] = $booking;
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
                <a href="residents.php" class="sidebar-link"><?php echo systemSidebarIcon('residents'); ?> Residents</a>
                <?php if (isSuperAdmin()): ?>
                    <a href="pending_accounts.php" class="sidebar-link"><?php echo systemSidebarIcon('pending'); ?> Pending Accounts</a>
                    <a href="staff.php" class="sidebar-link"><?php echo systemSidebarIcon('staff'); ?> Staff Management</a>
                    <a href="unitpayments.php" class="sidebar-link"><?php echo systemSidebarIcon('billing'); ?> Billing & Payments</a>
                    <a href="generate_bills.php" class="sidebar-link"><?php echo systemSidebarIcon('bills'); ?> Generate Bills</a>
                    <a href="violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
                <?php endif; ?>
                <a href="bookingrequest.php" class="sidebar-link active"><?php echo systemSidebarIcon('calendar'); ?> Booking Requests</a>
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
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Booking Requests</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button><div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div></div>
                </div>
            </header>

            <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <section class="unit-page-head"><div><h2>Booking Requests</h2><p>Review and approve resident amenity reservations.</p></div><div class="unit-summary"><span><?php echo count($bookings); ?> Requests</span></div></section>
            <section class="unit-management-panel">
                <form class="unit-filters" method="get" action="bookingrequest.php"><label for="bookingStatus">Filter bookings</label><select id="bookingStatus" name="status"><option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option><option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option><option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option></select><button type="submit">Filter</button></form>
                <div class="unit-table-wrap"><table class="unit-table"><thead><tr><th>Resident</th><th>Unit</th><th>Amenity</th><th>Date</th><th>Time</th><th>Status</th></tr></thead><tbody>
                    <?php if (empty($bookings)): ?><tr><td colspan="6" class="unit-empty">No booking requests found.</td></tr><?php else: foreach ($bookings as $booking): ?><tr><td><strong><?php echo htmlspecialchars($booking['full_name']); ?></strong><small>@<?php echo htmlspecialchars($booking['username']); ?></small></td><td><?php echo htmlspecialchars($booking['unit_number']); ?></td><td><?php echo htmlspecialchars($booking['amenity']); ?></td><td><?php echo htmlspecialchars(date('M j, Y', strtotime($booking['booking_date']))); ?></td><td><?php echo htmlspecialchars(date('g:i A', strtotime($booking['booking_time']))); ?></td><td><form method="post" class="status-form"><input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>"><select name="status" aria-label="Update booking status" onchange="this.form.submit()"><option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option><option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option></select></form></td></tr><?php endforeach; endif; ?>
                </tbody></table></div>
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
