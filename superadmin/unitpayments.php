<?php
require_once '../config.php';
require_once __DIR__ . '/../includes/billing_workspace.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!canManageBilling()) {
    redirect('../resident/dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'paid', 'pending', 'overdue'], true)) $statusFilter = 'all';
$activeTab = billingWorkspaceActiveTab();
$payments = [];
$totals = ['all' => 0, 'paid' => 0, 'pending' => 0, 'overdue' => 0];
$successMessage = '';

$connection = connectDb();
ensurePaymongoColumns($connection);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_paid') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId > 0) {
            if (confirmCashBillPayment($connection, $paymentId)) {
                $successMessage = 'Payment marked as paid via Cash.';
                $activeTab = 'history';
            }
        }
    } elseif ($action === 'send_reminders') {
        $reminderResult = sendDueDateReminders(3);
        $successMessage = "Reminders queued for {$reminderResult['due_count']} bill(s): {$reminderResult['emails_queued']} email notice(s), {$reminderResult['sms_queued']} SMS notice(s).";
        logAudit('send_reminders', 'payment', null, $successMessage);
    }
}

if ($successMessage !== '') {
    $_SESSION['unitpayments_success'] = $successMessage;
    $redirectParams = ['tab' => $activeTab];
    if ($search !== '') {
        $redirectParams['search'] = $search;
    }
    if ($activeTab === 'billing' && in_array($statusFilter, ['pending', 'overdue'], true)) {
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
            $payments[] = $payment;
        }
    }
}

$paymentCount = count($payments);
$paidPayments = array_values(array_filter($payments, static fn(array $payment): bool => $payment['status'] === 'paid'));
$currentPayments = array_values(array_filter($payments, static fn(array $payment): bool =>
    $payment['status'] !== 'paid' && (!in_array($statusFilter, ['pending', 'overdue'], true) || $payment['status'] === $statusFilter)
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
<?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="../assets/css/billing-workspace.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/css/billing-workspace.css'); ?>">
    <script src="../assets/js/billing-workspace.js?v=<?php echo (int)filemtime(__DIR__ . '/../assets/js/billing-workspace.js'); ?>" defer></script>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main billing-workspace">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Billing &amp; Payments</h1></div></div>
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

            <?php renderBillingWorkspaceTabs($activeTab, count($currentPayments), count($paidPayments)); ?>
            <?php foreach (['billing' => $currentPayments, 'history' => $paidPayments] as $panelKey => $payments): ?>
            <section class="unit-management-panel payment-management-panel" <?php echo billingWorkspacePanelAttributes($panelKey, $activeTab); ?>>
                <div class="billing-section-head"><div><h2><?php echo $panelKey === 'history' ? 'Payment History' : 'Outstanding Bills'; ?></h2><p><?php echo $panelKey === 'history' ? 'Completed payments and their recorded references.' : 'Review unpaid statements and confirm cash received at the office.'; ?></p></div></div>
                <form class="unit-filters" method="get" action="unitpayments.php">
                    <input type="hidden" name="tab" value="<?php echo $panelKey; ?>">
                    <label for="paymentSearch-<?php echo $panelKey; ?>">Search <?php echo $panelKey === 'history' ? 'history' : 'bills'; ?></label><input id="paymentSearch-<?php echo $panelKey; ?>" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Resident, unit or reference...">
                    <?php if ($panelKey === 'billing'): ?>
                    <label for="paymentStatus">Filter by status</label><select id="paymentStatus" name="status"><option value="all">All unpaid statuses</option><option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option></select>
                    <?php endif; ?>
                    <button type="submit">Search</button>
                </form>
                <?php include __DIR__ . '/../includes/billing_staff_table.php'; ?>
            </section>
            <?php endforeach; ?>

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
