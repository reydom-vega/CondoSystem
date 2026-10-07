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
ensurePaymongoColumns($connection);
$payment = null;
if ($paymentId > 0) {
    $stmt = $connection->prepare('SELECT id, amount, status, payment_method, paymongo_checkout_id, paymongo_payment_id, gateway_status, paid_at FROM payments WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $paymentId, $_SESSION['user_id']);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    if ($payment && $payment['status'] !== 'paid' && $returnStatus === 'success') {
        reconcilePaymongoCheckoutPayment($connection, $paymentId, (int)$_SESSION['user_id']);
        $refreshStmt = $connection->prepare('SELECT id, amount, status, payment_method, paymongo_checkout_id, paymongo_payment_id, gateway_status, paid_at FROM payments WHERE id = ? AND user_id = ? LIMIT 1');
        $refreshStmt->bind_param('ii', $paymentId, $_SESSION['user_id']);
        $refreshStmt->execute();
        $payment = $refreshStmt->get_result()->fetch_assoc();
    }
    if ($payment && ensureParkingStickerOrdersTable($connection)) {
        $stickerStmt = $connection->prepare('SELECT id FROM parking_sticker_orders WHERE bill_payment_id = ? AND user_id = ? LIMIT 1');
        $stickerStmt->bind_param('ii', $paymentId, $_SESSION['user_id']);
        $stickerStmt->execute();
        $isStickerPayment = (bool)$stickerStmt->get_result()->fetch_assoc();
    }
}

// The redirect itself proves nothing. The payment is confirmed only by the
// signed webhook or an authenticated PayMongo checkout-session lookup.
$isPaid = $payment && $payment['status'] === 'paid';
$isPending = $payment && $payment['status'] !== 'paid';
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
</head>
<body class="dashboard-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <a href="payments.php" class="sidebar-link active"><?php echo systemSidebarIcon('billing'); ?> Billing &amp; Payments</a>
                <a href="book_amenity.php" class="sidebar-link"><?php echo systemSidebarIcon('calendar'); ?> Book Amenity</a>
                <a href="parking.php" class="sidebar-link"><?php echo systemSidebarIcon('parking'); ?> Parking</a>
                <a href="maintenance.php" class="sidebar-link"><?php echo systemSidebarIcon('maintenance'); ?> Maintenance</a>
                <a href="messages.php" class="sidebar-link"><?php echo systemSidebarIcon('messages'); ?> Messages</a>
                <a href="announcements.php" class="sidebar-link"><?php echo systemSidebarIcon('announcements'); ?> Announcements</a>
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

            <section class="payment-summary-card payment-return-card">
                <?php if (!$payment): ?>
                    <?php echo systemIconFromGlyph('❓', 'payment-return-icon'); ?>
                    <h3 class="section-title">Payment Not Found</h3>
                    <p>We couldn't find that payment record on your account.</p>
                <?php elseif ($isPaid): ?>
                    <?php echo systemIconFromGlyph('✅', 'payment-return-icon'); ?>
                    <h3 class="section-title">Payment Confirmed</h3>
                    <?php if ($isStickerPayment): ?>
                        <p>Your parking sticker payment of <strong>₱<?php echo number_format((float)$payment['amount'], 2); ?></strong> has been <?php echo !empty($payment['paymongo_payment_id']) || strpos((string)$payment['gateway_status'], 'payment.paid') !== false || strpos((string)$payment['gateway_status'], 'checkout_session.paid') !== false ? 'verified by PayMongo' : 'confirmed'; ?>.</p>
                        <p>Please visit the management office to claim your physical parking sticker. No receipt upload is needed.</p>
                    <?php else: ?>
                        <p>Your payment of <strong>₱<?php echo number_format((float)$payment['amount'], 2); ?></strong> has been received. Thank you!</p>
                    <?php endif; ?>
                <?php elseif ($returnStatus === 'cancelled'): ?>
                    <?php echo systemIconFromGlyph('↩️', 'payment-return-icon'); ?>
                    <h3 class="section-title">Checkout Cancelled</h3>
                    <p>No charge was made. You can try again anytime from the Billing &amp; Payments page.</p>
                <?php else: ?>
                    <?php echo systemIconFromGlyph('⏳', 'payment-return-icon'); ?>
                    <h3 class="section-title">Confirming Your Payment…</h3>
                    <p>PayMongo is finalizing your payment. This page will refresh automatically — it usually only takes a few seconds. If this doesn't update after a minute, your payment may not have completed; please check Billing &amp; Payments or contact the management office.</p>
                <?php endif; ?>
                <?php if ($isPaid && $isStickerPayment): ?>
                    <a href="parking.php" class="proceed-payment-btn payment-return-action">View Sticker Request</a>
                <?php else: ?>
                    <a href="payments.php" class="proceed-payment-btn payment-return-action">Back to Billing &amp; Payments</a>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (menuToggle) { menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); }); }
        if (overlay) { overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); }); }
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', (e) => { e.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
            document.addEventListener('click', (e) => { if (!profileMenu.contains(e.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
        }
    </script>
</body>
</html>