<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!canManageBilling()) {
    redirect('../resident/dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$payments = [];
$totals = ['all' => 0, 'paid' => 0, 'pending' => 0, 'overdue' => 0];
$successMessage = '';

$connection = connectDb();
ensurePaymongoColumns($connection);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_paid') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $update = $connection->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(), payment_method = 'cash', payment_channel = 'cash' WHERE id = ? AND payment_method = 'cash' AND status IN ('pending', 'overdue')");
            $update->bind_param('i', $paymentId);
            if ($update->execute() && $update->affected_rows === 1) {
                markViolationsPaidForBill($paymentId);
                logAudit('mark_paid', 'payment', $paymentId, 'Manually marked as paid by admin via Cash');
                $successMessage = 'Payment marked as paid via Cash.';
            }
        }
    } elseif ($action === 'send_reminders') {
        $reminderResult = sendDueDateReminders(3);
        $successMessage = "Reminders sent to {$reminderResult['due_count']} resident(s) — {$reminderResult['emails_sent']} email(s), {$reminderResult['sms_sent']} SMS.";
        logAudit('send_reminders', 'payment', null, $successMessage);
    }
}

if ($successMessage !== '') {
    $_SESSION['unitpayments_success'] = $successMessage;
    $redirectParams = [];
    if ($search !== '') {
        $redirectParams['search'] = $search;
    }
    if ($statusFilter !== 'all') {
        $redirectParams['status'] = $statusFilter;
    }
    $redirectUrl = 'unitpayments.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

$successMessage = $_SESSION['unitpayments_success'] ?? '';
unset($_SESSION['unitpayments_success']);

if (ensurePaymentsTable($connection)) {
    $summaryResult = $connection->query("SELECT COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending, COALESCE(SUM(CASE WHEN status = 'overdue' THEN amount ELSE 0 END), 0) AS overdue FROM payments");
    if ($summaryResult) {
        $summary = $summaryResult->fetch_assoc();
        $totals = ['all' => (float)$summary['total'], 'paid' => (float)$summary['paid'], 'pending' => (float)$summary['pending'], 'overdue' => (float)$summary['overdue']];
    }

    $result = $connection->query("SELECT p.id, p.amount, p.payment_method, p.payment_channel, p.status, p.due_date, p.paid_at, p.created_at, p.paymongo_payment_id, p.gateway_status, u.full_name, u.username, u.unit_number FROM payments p INNER JOIN users u ON u.id = p.user_id ORDER BY p.created_at DESC");
    if ($result) {
        while ($payment = $result->fetch_assoc()) {
            $haystack = strtolower(implode(' ', [
                $payment['full_name'],
                $payment['username'],
                $payment['unit_number'],
                $payment['payment_method'],
                'DUES-' . $payment['id'],
                $payment['id'],
                $payment['paymongo_payment_id'] ?? '',
            ]));
            if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
                continue;
            }
            if ($statusFilter !== 'all' && $payment['status'] !== $statusFilter) {
                continue;
            }
            $payments[] = $payment;
        }
    }
}

$paymentCount = count($payments);
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
                <a href="<?php echo isTreasurer() ? '../treasurer/treasurer_dashboard.php' : 'admin_dashboard.php'; ?>" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <?php if (isSuperAdmin()): ?>
                <a href="units.php" class="sidebar-link"><?php echo systemSidebarIcon('units'); ?> Units</a>
                <a href="residents.php" class="sidebar-link"><?php echo systemSidebarIcon('residents'); ?> Residents</a>
                <a href="pending_accounts.php" class="sidebar-link"><?php echo systemSidebarIcon('pending'); ?> Pending Accounts</a>
                <a href="staff.php" class="sidebar-link"><?php echo systemSidebarIcon('staff'); ?> Staff Management</a>
                <?php endif; ?>
                <a href="unitpayments.php" class="sidebar-link active"><?php echo systemSidebarIcon('billing'); ?> Billing & Payments</a>
                <a href="generate_bills.php" class="sidebar-link"><?php echo systemSidebarIcon('bills'); ?> Generate Bills</a>
                <?php if (isSuperAdmin()): ?>
                    <a href="violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
                    <a href="bookingrequest.php" class="sidebar-link"><?php echo systemSidebarIcon('calendar'); ?> Booking Requests</a>
                    <a href="maintenancerequests.php" class="sidebar-link"><?php echo systemSidebarIcon('maintenance'); ?> Maintenance Requests</a>
                    <a href="admin_messages.php" class="sidebar-link"><?php echo systemSidebarIcon('messages'); ?> Messages</a>
                    <a href="announcements.php" class="sidebar-link"><?php echo systemSidebarIcon('announcements'); ?> Announcements</a>
                    <a href="analytics.php" class="sidebar-link"><?php echo systemSidebarIcon('analytics'); ?> Analytics</a>
                    <a href="parking.php" class="sidebar-link"><?php echo systemSidebarIcon('parking'); ?> Parking</a>
                    <a href="auditlog.php" class="sidebar-link"><?php echo systemSidebarIcon('audit'); ?> Audit Log</a>
                    <a href="visitorlog.php" class="sidebar-link"><?php echo systemSidebarIcon('visitors'); ?> Visitor Log</a>
                <?php endif; ?>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Payments</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <?php if ($successMessage): ?><div class="alert success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>

            <section class="unit-page-head"><div><h2>Unit Payments</h2><p>Track payment activity and outstanding balances for all residents.</p></div><div class="unit-summary"><span><?php echo $paymentCount; ?> Records</span><span>₱<?php echo number_format($totals['all'], 2); ?> Total</span></div></section>

            <section class="payment-overview-grid">
                <div class="payment-overview-card"><span>Total Recorded</span><strong>₱<?php echo number_format($totals['all'], 2); ?></strong></div>
                <div class="payment-overview-card paid"><span>Paid</span><strong>₱<?php echo number_format($totals['paid'], 2); ?></strong></div>
                <div class="payment-overview-card pending"><span>Pending</span><strong>₱<?php echo number_format($totals['pending'], 2); ?></strong></div>
                <div class="payment-overview-card overdue"><span>Overdue</span><strong>₱<?php echo number_format($totals['overdue'], 2); ?></strong></div>
            </section>

            <section class="unit-management-panel payment-management-panel">
                <form class="unit-filters" method="get" action="unitpayments.php">
                    <label for="paymentSearch">Search payments</label><input id="paymentSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search resident or unit...">
                    <label for="paymentStatus">Filter by status</label><select id="paymentStatus" name="status"><option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option><option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Paid</option><option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option></select>
                    <button type="submit">Search</button>
                </form>
                <div class="unit-table-wrap"><table class="unit-table payment-table"><thead><tr><th>Resident</th><th>Unit</th><th>Amount</th><th>Method</th><th>Date</th><th>Time</th><th>Reference</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    <?php if (empty($payments)): ?><tr><td colspan="9" class="unit-empty">No payment records found.</td></tr><?php else: ?>
                        <?php foreach ($payments as $payment): $items = getBillItems($connection, (int)$payment['id']); $reference = 'DUES-' . (int)$payment['id']; $paymentDate = !empty($payment['paid_at']) ? $payment['paid_at'] : $payment['created_at']; ?><tr><td><strong><?php echo htmlspecialchars($payment['full_name']); ?></strong><small>@<?php echo htmlspecialchars($payment['username']); ?></small></td><td><?php echo htmlspecialchars($payment['unit_number']); ?></td><td>
                            <?php if (empty($items)): ?>
                                <strong>₱<?php echo number_format((float)$payment['amount'], 2); ?></strong>
                            <?php else: ?>
                                <details><summary><strong>₱<?php echo number_format((float)$payment['amount'], 2); ?></strong></summary>
                                    <div style="margin-top:6px; font-size:12px; color:#9ca3af;">
                                        <?php foreach ($items as $item): ?><div><?php echo htmlspecialchars($item['category']); ?>: ₱<?php echo number_format((float)$item['amount'], 2); ?></div><?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td><td><?php echo htmlspecialchars(paymentChannelLabel($payment['payment_channel'] ?? null, $payment['payment_method'] ?? null)); ?></td><td class="payment-date-cell"><?php echo htmlspecialchars(date('M j, Y', strtotime($paymentDate))); ?></td><td class="payment-time-cell"><?php echo htmlspecialchars(date('g:i A', strtotime($paymentDate))); ?></td><td><strong><?php echo htmlspecialchars($reference); ?></strong><?php if (!empty($payment['paymongo_payment_id'])): ?><br><small>Payment ID: <?php echo htmlspecialchars($payment['paymongo_payment_id']); ?></small><?php endif; ?></td><td><span class="unit-status <?php echo htmlspecialchars($payment['status']); ?>"><?php echo htmlspecialchars(ucfirst($payment['status'])); ?></span></td><td><?php if (in_array($payment['status'], ['pending', 'overdue'], true) && $payment['payment_method'] === 'cash'): ?><form method="post" class="manual-payment-confirm"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>"><button type="submit" class="btn-small btn-approve">Confirm Cash Paid</button></form><?php elseif (in_array($payment['status'], ['pending', 'overdue'], true) && in_array($payment['payment_method'], ['bank', 'online'], true)): ?><span>Awaiting PayMongo</span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
                    <?php endif; ?>
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
