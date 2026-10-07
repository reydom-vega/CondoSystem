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
$userId = (int)$_SESSION['user_id'];

$connection = connectDb();
ensurePaymentsTable($connection);
ensurePaymongoColumns($connection);
ensureBillingTables($connection);

$profileStmt = $connection->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc() ?: ['full_name' => $username, 'email' => ''];

$paymentMethods = [
    [
        'id'          => 'online',
        'icon'        => 'credit-card',
        'icon_class'  => 'method-icon-blue',
        'title'       => 'Pay Online',
        'description' => 'Card, GCash, or Maya via PayMongo secure checkout',
    ],
    [
        'id'          => 'bank',
        'icon'        => 'billing',
        'icon_class'  => 'method-icon-teal',
        'title'       => 'Bank Transfer',
        'description' => 'Pay through online banking via PayMongo secure checkout',
    ],
    [
        'id'          => 'cash',
        'icon'        => 'wallet',
        'icon_class'  => 'method-icon-teal',
        'title'       => 'Cash',
        'description' => 'Pay at the management office; management will confirm receipt',
    ],
];

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? 'pay_bill';

    if ($formAction === 'pay_bill') {
        $billId = (int)($_POST['bill_id'] ?? 0);
        $selectedMethod = $_POST['payment_method'] ?? '';

        $billCheck = $connection->prepare("SELECT * FROM payments WHERE id = ? AND user_id = ? AND status IN ('pending','overdue') LIMIT 1");
        $billCheck->bind_param('ii', $billId, $userId);
        $billCheck->execute();
        $bill = $billCheck->get_result()->fetch_assoc();

        if (!$bill) {
            $errors[] = 'That bill could not be found or is no longer open.';
        } elseif (!in_array($selectedMethod, ['online', 'bank', 'cash'], true)) {
            $errors[] = 'Please select a payment method.';
        } else {
            $items = getBillItems($connection, $billId);
            if (empty($items)) {
                $items = [['category' => 'Payment', 'amount' => $bill['amount']]];
            }

            $updateMethod = $connection->prepare('UPDATE payments SET payment_method = ? WHERE id = ?');
            $updateMethod->bind_param('si', $selectedMethod, $billId);
            $updateMethod->execute();
            trackEvent('payment_submitted', $bill['amount'] . ' via ' . $selectedMethod, $userId);

            if ($selectedMethod === 'cash') {
                $success = true;
            } else {
                $checkoutMethods = paymongoPaymentMethodsForSelection($selectedMethod);
                if (empty($checkoutMethods)) {
                    $errors[] = $selectedMethod === 'bank'
                        ? 'Online banking is not enabled in your PayMongo payment methods. Add dob to CONDO_PAYMONGO_METHODS.'
                        : 'No PayMongo methods are enabled for online payment.';
                } else {
                    // The resident is never marked paid here; PayMongo's signed webhook confirms completed checkouts.
                    $successUrl = buildUrl('payment_return.php?payment_id=' . $billId . '&status=success');
                    $cancelUrl = buildUrl('payment_return.php?payment_id=' . $billId . '&status=cancelled');
                    $description = 'Statement of Account — ' . ($bill['billing_period_start'] ? date('F Y', strtotime($bill['billing_period_start'])) : date('F Y'));
                    $residentName = $profile['full_name'] ?: $username;

                    $checkout = createPaymongoCheckoutSession($billId, $items, $description, $residentName, $profile['email'] ?? '', $successUrl, $cancelUrl, [], $checkoutMethods);

                    if ($checkout['success']) {
                        $update = $connection->prepare('UPDATE payments SET paymongo_checkout_id = ?, checkout_url = ?, gateway_status = NULL WHERE id = ?');
                        $update->bind_param('ssi', $checkout['checkout_id'], $checkout['checkout_url'], $billId);
                        $update->execute();
                        redirect($checkout['checkout_url']);
                    } else {
                        $errors[] = 'Could not start ' . ($selectedMethod === 'bank' ? 'bank transfer' : 'online') . ' payment: ' . $checkout['error'] . ' You can try again or choose another payment method.';
                    }
                }
            }
        }
    }
}

$openBills = getOpenBillsForUser($userId);
$billHistory = getBillHistoryForUser($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Billing &amp; Payments</title>
    <link rel="stylesheet" href="../resident.css?v=<?php echo (int)filemtime(__DIR__ . '/../resident.css'); ?>">
</head>
<body class="dashboard-page">

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('dashboard'); ?> Dashboard
                </a>
                <a href="payments.php" class="sidebar-link active">
                    <?php echo systemSidebarIcon('billing'); ?> Billing &amp; Payments
                </a>
                <a href="residentviolation.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('violations'); ?> Violations
                </a>
                <a href="book_amenity.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('calendar'); ?> Book Amenity
                </a>
                <a href="parking.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('parking'); ?> Parking
                </a>
                <a href="maintenance.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('maintenance'); ?> Maintenance
                </a>
                <a href="messages.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('messages'); ?> Messages
                </a>
                    <a href="announcements.php" class="sidebar-link">
                        <?php echo systemSidebarIcon('announcements'); ?> Announcements
                    </a>
            </nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu">
                        <?php echo systemIcon('menu', 'menu-icon'); ?>
                    </button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Billing &amp; Payments</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span>
                                </div>
                            </div>
                            <a href="edit_profile.php" class="profile-dropdown-item">
                                <?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile
                            </a>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger">
                                <?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert success"><strong>Payment initiated!</strong> We'll confirm your payment shortly.</div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <div class="billing-overview-grid">
                <section class="billing-overview-column billing-statements-column">
                    <h3 class="section-title">Statement of Account</h3>
                    <?php if (empty($openBills)): ?>
                        <div class="soa-card"><p class="billing-muted">You're all caught up — no outstanding bills right now.</p></div>
                    <?php else: foreach ($openBills as $bill): ?>
                    <div class="soa-card">
                    <div class="soa-head">
                        <div>
                            <strong><?php echo $unitNumber; ?></strong>
                            <?php if ($bill['billing_period_start']): ?>
                                <div class="billing-period">Billing Period: <?php echo htmlspecialchars(date('M j', strtotime($bill['billing_period_start'])) . ' – ' . date('M j, Y', strtotime($bill['billing_period_end'] ?: $bill['billing_period_start']))); ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="unit-status <?php echo htmlspecialchars($bill['status']); ?>"><?php echo htmlspecialchars(ucfirst($bill['status'])); ?></span>
                    </div>

                    <table class="soa-items">
                        <?php foreach ($bill['items'] as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['category']); ?><?php if (!empty($item['description'])): ?> — <?php echo htmlspecialchars($item['description']); ?><?php endif; ?></td>
                                <td>₱<?php echo number_format((float)$item['amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="soa-total-row"><td>Total Due</td><td>₱<?php echo number_format((float)$bill['amount'], 2); ?></td></tr>
                    </table>
                    <p class="billing-due">Due Date: <strong class="billing-due-strong"><?php echo htmlspecialchars(date('F j, Y', strtotime($bill['due_date']))); ?></strong></p>
                    <form method="POST" action="payments.php">
                        <input type="hidden" name="form_action" value="pay_bill">
                        <input type="hidden" name="bill_id" value="<?php echo (int)$bill['id']; ?>">
                        <?php foreach ($paymentMethods as $index => $method): ?>
                            <label class="payment-method-item<?php echo $index === 0 ? ' selected' : ''; ?>">
                                <input type="radio" name="payment_method" value="<?php echo htmlspecialchars($method['id']); ?>" <?php echo $index === 0 ? 'checked' : ''; ?>>
                                <span class="payment-method-icon <?php echo htmlspecialchars($method['icon_class']); ?>"><?php echo systemIcon($method['icon'], 'payment-method-symbol'); ?></span>
                                <span class="payment-method-text">
                                    <span class="payment-method-title"><?php echo htmlspecialchars($method['title']); ?></span>
                                    <span class="payment-method-desc"><?php echo htmlspecialchars($method['description']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <button type="submit" class="proceed-payment-btn">Pay ₱<?php echo number_format((float)$bill['amount'], 2); ?></button>
                    </form>
                    </div>
                    <?php endforeach; endif; ?>
                </section>

                <section class="billing-overview-column billing-history-column">
                    <h3 class="section-title">Payment History</h3>
                    <?php if (empty($billHistory)): ?>
                        <div class="billing-side-empty">No completed payments yet.</div>
                    <?php else: foreach ($billHistory as $bill): ?>
                    <details class="history-item">
                        <summary>
                            <span><strong><?php echo htmlspecialchars($bill['billing_period_start'] ? date('F Y', strtotime($bill['billing_period_start'])) : date('F Y', strtotime($bill['paid_at'] ?: $bill['created_at']))); ?></strong><small>Paid <?php echo htmlspecialchars(date('M j, Y', strtotime($bill['paid_at'] ?: $bill['created_at']))); ?></small></span>
                            <span class="history-total">₱<?php echo number_format((float)$bill['amount'], 2); ?></span>
                        </summary>
                        <div class="history-payment-meta">
                            <div><span>Payment date</span><strong><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($bill['paid_at'] ?: $bill['created_at']))); ?></strong></div>
                            <div><span>Paid via</span><strong><?php echo htmlspecialchars(paymentChannelLabel($bill['payment_channel'] ?? null, $bill['payment_method'] ?? null)); ?></strong></div>
                            <div><span>Reference</span><strong>DUES-<?php echo (int)$bill['id']; ?></strong></div>
                            <?php if (!empty($bill['paymongo_payment_id'])): ?><div><span>Payment ID</span><strong><?php echo htmlspecialchars($bill['paymongo_payment_id']); ?></strong></div><?php endif; ?>
                            <?php if (!empty($bill['due_date'])): ?><div><span>Due date</span><strong><?php echo htmlspecialchars(date('M j, Y', strtotime($bill['due_date']))); ?></strong></div><?php endif; ?>
                        </div>
                        <table class="history-items">
                            <thead><tr><th>Charge</th><th class="soa-amount">Amount</th></tr></thead>
                            <tbody><?php foreach ($bill['items'] as $item): ?>
                                <tr><td><?php echo htmlspecialchars($item['category']); ?><?php if (!empty($item['description'])): ?> — <?php echo htmlspecialchars($item['description']); ?><?php endif; ?></td><td class="soa-amount">₱<?php echo number_format((float)$item['amount'], 2); ?></td></tr>
                            <?php endforeach; ?></tbody>
                        </table>
                        <a class="history-receipt-link" href="payment_receipt.php?id=<?php echo (int)$bill['id']; ?>">Download Receipt (PDF)</a>
                    </details>
                    <?php endforeach; endif; ?>
                </section>
            </div>

        </main>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (menuToggle && sidebar && overlay) {
                menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
                overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
            }

            const profileMenu = document.getElementById('profileMenu');
            const profileToggle = document.getElementById('profileToggle');
            if (profileMenu && profileToggle) {
                profileToggle.addEventListener('click', (e) => { e.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
                document.addEventListener('click', (e) => { if (!profileMenu.contains(e.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
            }

            document.querySelectorAll('.payment-method-item').forEach((item) => {
                const radio = item.querySelector('input[type="radio"]');
                if (!radio) return;
                radio.addEventListener('change', () => {
                    item.closest('form').querySelectorAll('.payment-method-item').forEach((el) => el.classList.remove('selected'));
                    if (radio.checked) item.classList.add('selected');
                });
            });
        });
    </script>
</body>
</html>