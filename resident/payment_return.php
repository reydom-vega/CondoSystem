<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$paymentId = (int)($_GET['payment_id'] ?? 0);
$returnStatus = $_GET['status'] ?? '';
$isStickerPayment = false;

$connection = connectDb();
$actorId=(int)$_SESSION['user_id'];
$actorContext=residentContext($connection,$actorId);
if (!$actorContext || !$actorContext['approved'] || !in_array($actorContext['account_kind'],['owner','tenant'],true)) {
    http_response_code(403); exit('Only the approved unit owner can access checkout status. View your statements in Billing.');
}
ensurePaymongoColumns($connection);
ensureBillingTables($connection);
if ($actorContext['account_kind']==='tenant') {
    $check=$connection->prepare('SELECT user_id FROM payments WHERE id=?');
    $check->bind_param('i',$paymentId); $check->execute(); $tenantBill=$check->get_result()->fetch_assoc();
    if (!$tenantBill || !residentCanPayBill($connection,$actorId,(int)$tenantBill['user_id'],$paymentId)) { http_response_code(403); exit('You can access checkout status only for your own parking bills.'); }
}
$payment = null;
if ($paymentId > 0) {
    $stmt = $connection->prepare('SELECT id, user_id, amount, status, payment_method, paymongo_checkout_id, paymongo_payment_id, gateway_status, paid_at FROM payments WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    if ($payment && !residentCanPayBill($connection,$actorId,(int)$payment['user_id'],$paymentId)) $payment=null;
    if ($payment && $payment['status'] !== 'paid' && $returnStatus === 'success') {
        $lastCheck = (int)($_SESSION['payment_reconcile_at'][$paymentId] ?? 0);
        if (time() - $lastCheck >= 15) {
            $_SESSION['payment_reconcile_at'][$paymentId] = time();
            try { reconcilePaymongoCheckoutPayment($connection, $paymentId, (int)$_SESSION['user_id']); }
            catch (Throwable $error) { error_log('Payment return reconciliation is temporarily unavailable.'); }
        }
        $refreshStmt = $connection->prepare('SELECT id, user_id, amount, status, payment_method, paymongo_checkout_id, paymongo_payment_id, gateway_status, paid_at FROM payments WHERE id = ? LIMIT 1');
        $refreshStmt->bind_param('i', $paymentId);
        $refreshStmt->execute();
        $payment = $refreshStmt->get_result()->fetch_assoc();
        if ($payment && !residentCanPayBill($connection,$actorId,(int)$payment['user_id'],$paymentId)) $payment=null;
    }
    if ($payment && ensureParkingStickerOrdersTable($connection)) {
        $stickerStmt = $connection->prepare('SELECT id FROM parking_sticker_orders WHERE bill_payment_id = ? AND user_id = ? LIMIT 1');
        $stickerStmt->bind_param('ii', $paymentId, $payment['user_id']);
        $stickerStmt->execute();
        $isStickerPayment = (bool)$stickerStmt->get_result()->fetch_assoc();
    }
}

// The redirect itself proves nothing. The payment is confirmed only by the
// signed webhook or an authenticated PayMongo checkout-session lookup.
$isPaid = $payment && $payment['status'] === 'paid';
$isPending = $payment && in_array($payment['status'], ['pending','overdue'], true);

// Presentation follows the verified record; a checkout redirect is not payment proof.
$returnTone = 'danger';
$returnIcon = 'help';
$returnBadge = 'Record unavailable';
$returnTitle = 'Payment not found';
$returnDescription = "We couldn't find that payment record on your account. Return to Billing & Payments to view your bills.";
if ($isPaid) {
    $returnTone = 'success';
    $returnIcon = 'check-circle';
    $returnBadge = 'Paid';
    $returnTitle = 'Payment confirmed';
    $returnDescription = 'Your payment has been received and recorded. Thank you!';
    if ($isStickerPayment) {
        $gatewayVerified = !empty($payment['paymongo_payment_id']) || strpos((string)$payment['gateway_status'], 'payment.paid') !== false || strpos((string)$payment['gateway_status'], 'checkout_session.paid') !== false;
        $returnDescription = 'Your parking sticker payment has been ' . ($gatewayVerified ? 'verified by PayMongo.' : 'confirmed.');
    }
} elseif ($payment && $returnStatus === 'cancelled') {
    $returnTone = 'warning';
    $returnIcon = 'undo';
    $returnBadge = 'Checkout cancelled';
    $returnTitle = 'You returned from checkout';
    $returnDescription = 'This does not cancel a payment already submitted. Check the recorded status in Billing & Payments before trying again.';
} elseif ($isPending) {
    $returnTone = 'warning';
    $returnIcon = 'clock';
    $returnBadge = 'Awaiting confirmation';
    $returnTitle = $returnStatus === 'success' ? 'Confirming your payment' : 'Payment not yet confirmed';
    $returnDescription = $returnStatus === 'success'
        ? 'We are waiting for payment confirmation from PayMongo. Your bill will be marked paid once the payment is verified.'
        : 'No confirmed payment has been recorded for this bill. Review the latest status in Billing & Payments before trying again.';
} elseif ($payment) {
    if ($payment['status'] === 'rolled_forward') $returnTone = 'info';
    $returnIcon = 'info';
    $returnBadge = ucwords(str_replace('_', ' ', $payment['status']));
    $returnTitle = $payment['status'] === 'rolled_forward' ? 'Bill carried forward' : 'Payment not confirmed';
    $returnDescription = 'Review this bill in Billing & Payments for its recorded status, or contact the management office for assistance.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Payment Status</title>
    <link rel="stylesheet" href="../resident.css?v=<?php echo (int)filemtime(__DIR__ . '/../resident.css'); ?>">
    <?php if ($isPending && $returnStatus === 'success'): ?>
    <meta http-equiv="refresh" content="5">
    <?php endif; ?>
    <?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="../assets/css/payment-return.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/payment-return.css'); ?>">
</head>
<body class="portal-ui dashboard-page payment-return-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav">
                <?php renderResidentSidebarNavigation('payments.php'); ?>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Payment Status</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span></div></div><a href="edit_profile.php" class="profile-dropdown-item"><?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile</a><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <section class="payment-summary-card payment-return-card payment-return-card--<?php echo htmlspecialchars($returnTone, ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="paymentReturnTitle">
                <header class="payment-return-header">
                    <?php echo systemIcon($returnIcon, 'payment-return-icon'); ?>
                    <span class="payment-return-badge"><?php echo htmlspecialchars($returnBadge); ?></span>
                    <h2 id="paymentReturnTitle"><?php echo htmlspecialchars($returnTitle); ?></h2>
                    <p class="payment-return-description"><?php echo htmlspecialchars($returnDescription); ?></p>
                </header>

                <?php if ($payment): ?>
                <div class="payment-return-amount">
                    <span><?php echo $isPaid ? 'Amount paid' : 'Bill amount'; ?></span>
                    <strong>&#8369;<?php echo number_format((float)$payment['amount'], 2); ?></strong>
                </div>
                <dl class="payment-return-details">
                    <div><dt>Bill reference</dt><dd>#<?php echo (int)$payment['id']; ?></dd></div>
                    <div><dt>Recorded status</dt><dd><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $payment['status']))); ?></dd></div>
                    <?php if (!empty($payment['payment_method'])): ?>
                    <div><dt>Payment method</dt><dd><?php echo htmlspecialchars($payment['payment_method'] === 'online' ? 'Online payment' : paymentChannelLabel(null, $payment['payment_method'])); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($isPaid && !empty($payment['paid_at'])): ?>
                    <div><dt>Payment date</dt><dd><time datetime="<?php echo htmlspecialchars(str_replace(' ', 'T', $payment['paid_at']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(date('M j, Y, g:i A', strtotime($payment['paid_at']))); ?></time></dd></div>
                    <?php endif; ?>
                </dl>
                <?php endif; ?>

                <?php if ($isPaid && $isStickerPayment): ?>
                <aside class="payment-return-note">
                    <?php echo systemIcon('pin'); ?>
                    <div><h3>Collect your parking sticker</h3><p>Please visit the management office to claim your physical parking sticker. No receipt upload is needed.</p></div>
                </aside>
                <?php elseif ($isPending && $returnStatus === 'success'): ?>
                <aside class="payment-return-note" role="status">
                    <?php echo systemIcon('clock'); ?>
                    <div><h3>Checking for an update</h3><p>This page refreshes automatically every 5 seconds. If your status has not changed after a minute, check Billing &amp; Payments or contact the management office before trying again.</p></div>
                </aside>
                <?php endif; ?>

                <div class="payment-return-actions">
                    <?php if ($isPaid && $isStickerPayment): ?>
                    <a href="parking.php" class="service-btn payment-return-action"><?php echo systemIcon('parking'); ?>View Sticker Request</a>
                    <?php else: ?>
                    <a href="payments.php" class="service-btn payment-return-action"><?php echo systemIcon('wallet'); ?>Back to Billing &amp; Payments</a>
                    <?php endif; ?>
                </div>
                <p class="payment-return-help">Need help? Contact the management office<?php echo $payment ? ' with your bill reference.' : '.'; ?></p>
            </section>
        </main>
    </div>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (menuToggle) { /* Navigation is handled by the shared UI module. */ }
        if (overlay) { /* Navigation is handled by the shared UI module. */ }
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', (e) => { e.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
            document.addEventListener('click', (e) => { if (!profileMenu.contains(e.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
        }
    </script>
</body>
</html>
