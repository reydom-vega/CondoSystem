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
$residentContext=residentContext($connection,$userId);
$canPay=$residentContext && $residentContext['approved'] && $residentContext['account_kind']==='owner';
$billingTitle=$canPay ? 'Billing & Payments' : 'Unit Bills';

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
    requireWorkflowCsrf();
    if (!$canPay) { http_response_code(403); exit('Only the approved unit owner can pay bills. Tenant billing access is read-only.'); }
    $formAction = $_POST['form_action'] ?? '';
    if ($formAction === 'pay_bill') {
        $result = startResidentBillPayment($connection, (int)($_POST['bill_id'] ?? 0), $userId,
            (string)($_POST['payment_method'] ?? ''), $profile);
        if ($result['success']) {
            if (!empty($result['checkout_url'])) redirect($result['checkout_url']);
            $_SESSION['billing_cash_selected'] = true;
            redirect('payments.php');
        }
        $errors[] = $result['error'];
    } else {
        $errors[] = 'Unknown payment action.';
    }
}
$success = $canPay && !empty($_SESSION['billing_cash_selected']);
unset($_SESSION['billing_cash_selected']);
$openBills = getResidentVisibleBills($connection,$userId);
$billHistory = getResidentVisibleBills($connection,$userId,true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - <?php echo htmlspecialchars($billingTitle); ?></title>
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
                <?php renderResidentSidebarNavigation('payments.php'); ?>
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
                        <h1 class="dash-title"><?php echo htmlspecialchars($billingTitle); ?></h1>
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
                <div class="alert success"><strong>Cash payment selected.</strong> Pay at the management office. Billing staff will confirm the payment after receiving cash.</div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <?php if (!$canPay): ?>
                <div class="alert"><strong>Billing is read-only for tenants and occupants.</strong> You can review your unit's itemized statements and recorded payment status. Only the approved unit owner can select a payment method, pay bills, or download payment receipts.</div>
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
                    <?php if ($canPay && residentCanPayBill($connection,$userId,(int)$bill['user_id'])): ?>
                    <form method="POST" action="payments.php">
                        <?php echo workflowCsrfField(); ?>
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
                    <?php endif; ?>
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
                            <?php if ($canPay): ?>
                            <div><span>Paid via</span><strong><?php echo htmlspecialchars(paymentChannelLabel($bill['payment_channel'] ?? null, $bill['payment_method'] ?? null)); ?></strong></div>
                            <div><span>Reference</span><strong>DUES-<?php echo (int)$bill['id']; ?></strong></div>
                            <?php if (!empty($bill['paymongo_payment_id'])): ?><div><span>Payment ID</span><strong><?php echo htmlspecialchars($bill['paymongo_payment_id']); ?></strong></div><?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($bill['due_date'])): ?><div><span>Due date</span><strong><?php echo htmlspecialchars(date('M j, Y', strtotime($bill['due_date']))); ?></strong></div><?php endif; ?>
                        </div>
                        <table class="history-items">
                            <thead><tr><th>Charge</th><th class="soa-amount">Amount</th></tr></thead>
                            <tbody><?php foreach ($bill['items'] as $item): ?>
                                <tr><td><?php echo htmlspecialchars($item['category']); ?><?php if (!empty($item['description'])): ?> — <?php echo htmlspecialchars($item['description']); ?><?php endif; ?></td><td class="soa-amount">₱<?php echo number_format((float)$item['amount'], 2); ?></td></tr>
                            <?php endforeach; ?></tbody>
                        </table>
                        <?php if ($canPay && residentCanPayBill($connection,$userId,(int)$bill['user_id'])): ?><a class="history-receipt-link" href="payment_receipt.php?id=<?php echo (int)$bill['id']; ?>">Download Receipt (PDF)</a><?php endif; ?>
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
