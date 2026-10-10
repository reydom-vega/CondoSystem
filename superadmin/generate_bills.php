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
$connection = connectDb();
ensureBillingTables($connection);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_generate') {
        $dueDate = trim($_POST['due_date'] ?? '');
        $notify = isset($_POST['notify_residents']);

        $template = [];
        $bulkCategories = $_POST['bulk_category'] ?? [];
        $bulkAmounts = $_POST['bulk_amount'] ?? [];

        foreach ($bulkCategories as $index => $category) {
            $categoryName = trim((string)$category);
            $amount = (float)($bulkAmounts[$index] ?? 0);

            if ($categoryName !== '' && $amount >= 0) {
                $template[$categoryName] = $amount;
            }
        }

        $periodStart = $dueDate !== '' ? date('Y-m-01', strtotime($dueDate)) : '';
        $periodEnd = $dueDate !== '' ? date('Y-m-t', strtotime($dueDate)) : '';

        if (!workflowDate($dueDate)) {
            $errors[] = 'Due date is required.';
        } elseif (empty($template)) {
            $errors[] = 'Add at least one charge with an amount greater than or equal to zero.';
        } else {
            $result = generateStandardMonthlyBills($template, $periodStart, $periodEnd, $dueDate);
            if ($result['error']) {
                $errors[] = $result['error'];
            } else {
                logAudit('bulk_generate', 'payment', 0, "Generated {$result['created']} bill(s) for period {$periodStart} to {$periodEnd}, due {$dueDate}. Skipped {$result['skipped']} resident(s) with an open bill.");
                $success = "Generated {$result['created']} monthly bill(s). Skipped {$result['skipped']} resident(s) already billed for this period or unable to receive a bill. Earlier outstanding bills remain separately payable.";
                if ($notify && !empty($result['bill_ids'])) {
                    $queuedCount = 0;
                    foreach ($result['bill_ids'] as $billId) {
                        $queuedCount += (int)notifyResidentOfNewBill($billId);
                    }
                    $success .= ' Email notices queued for ' . $queuedCount . ' bill(s).';
                }
            }
        }
    } elseif ($action === 'custom_bill') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $dueDate = trim($_POST['due_date'] ?? '');
        $notify = isset($_POST['notify_resident_custom']);

        $categories = $_POST['item_category'] ?? [];
        $amounts = $_POST['item_amount'] ?? [];
        $items = [];
        foreach ($categories as $i => $category) {
            $amount = (float)($amounts[$i] ?? 0);
            if (trim($category) !== '' && $amount > 0) {
                $items[] = ['category' => trim($category), 'description' => '', 'amount' => $amount];
            }
        }

        $periodStart = null;
        $periodEnd = null;

        if ($targetUserId <= 0 || !workflowDate($dueDate)) {
            $errors[] = 'Please choose a resident and a due date.';
        } elseif (empty($items)) {
            $errors[] = 'Add at least one charge with an amount greater than zero.';
        } else {
            $billId = createBill($targetUserId, $items, $periodStart, $periodEnd, $dueDate);
            if ($billId) {
                logAudit('create', 'payment', $billId, 'Custom bill created or updated for user #' . $targetUserId);
                $success = 'Bill created.';
                if ($notify) {
                    $success .= notifyResidentOfNewBill($billId) ? ' Email notice queued for delivery.' : ' Email notice could not be queued.';
                }
            } else {
                $errors[] = 'Could not create the bill.';
            }
        }
    }
}

if ($success !== '') {
    $_SESSION['billing_success'] = $success;
    header('Location: generate_bills.php');
    exit;
}

$success = $_SESSION['billing_success'] ?? '';
unset($_SESSION['billing_success']);

$residentsResult = $connection->query("SELECT id, full_name, unit_number FROM users WHERE role = 'resident' AND is_verified = 1 AND is_active=1 AND status='approved' AND (account_type IS NULL OR TRIM(account_type)='' OR LOWER(TRIM(account_type))='resident owner') ORDER BY unit_number ASC");
$residents = $residentsResult ? $residentsResult->fetch_all(MYSQLI_ASSOC) : [];

$defaultDueDate = date('Y-m-d');
$bulkChargeOptions = STANDARD_CHARGE_CATEGORIES;
$historySearch = trim($_GET['history_search'] ?? '');
$historyStatus = $_GET['history_status'] ?? 'all';
$allowedHistoryStatuses = ['all', 'pending', 'paid', 'overdue', 'rejected', 'rolled_forward'];
if (!in_array($historyStatus, $allowedHistoryStatuses, true)) {
    $historyStatus = 'all';
}

$historyStmt = $connection->prepare("SELECT p.id, p.amount, p.status, p.due_date, p.created_at, p.billing_period_start, p.billing_period_end,
    u.full_name, u.username, u.email, u.contact_number, u.unit_number,
    (SELECT GROUP_CONCAT(bi.category ORDER BY bi.id SEPARATOR ', ')
        FROM bill_items bi WHERE bi.payment_id = p.id) AS charge_summary
    FROM payments p
    INNER JOIN users u ON u.id = p.user_id
    WHERE (? = 'all' OR p.status = ?)
        AND (? = '' OR u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.contact_number LIKE ? OR u.unit_number LIKE ? OR CAST(p.id AS CHAR) LIKE ? OR CONCAT('DUES-', p.id) LIKE ?)
    ORDER BY p.created_at DESC
    LIMIT 100");
$historyLike = '%' . $historySearch . '%';
$historyStmt->bind_param(
    'ssssssssss',
    $historyStatus,
    $historyStatus,
    $historySearch,
    $historyLike,
    $historyLike,
    $historyLike,
    $historyLike,
    $historyLike,
    $historyLike,
    $historyLike
);
$historyStmt->execute();
$billHistory = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .bill-history-panel {
            min-height: 0;
            display: block;
            padding: 20px;
        }

        .bill-history-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .bill-history-heading .section-title {
            margin: 0 0 6px;
        }

        .bill-history-heading .panel-subtext {
            margin: 0;
        }

        .bill-history-count {
            flex: 0 0 auto;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .bill-history-filters {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) 150px auto auto;
            gap: 8px;
            margin-bottom: 12px;
        }

        .bill-history-filters label {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
        }

        .bill-history-filters input,
        .bill-history-filters select {
            min-width: 0;
            height: 38px;
            padding: 8px 10px;
            border: 1px solid var(--input-border);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
            font: inherit;
            font-size: 12px;
        }

        .bill-history-filters button,
        .bill-history-reset {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 8px 14px;
            border: 0;
            border-radius: 8px;
            background: var(--btn-orange);
            color: #fff;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
        }

        .bill-history-reset {
            background: #293346;
        }

        .bill-history-table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--dash-card-border);
            border-radius: 8px;
        }

        .bill-history-table {
            min-width: 1080px;
        }

        .bill-history-table td {
            overflow-wrap: anywhere;
        }

        .bill-history-charge-list {
            color: var(--text-muted);
            font-size: 11px;
        }

        @media (max-width: 650px) {
            .bill-history-heading {
                flex-direction: column;
            }

            .bill-history-filters {
                grid-template-columns: 1fr 1fr;
            }

            .bill-history-filters input {
                grid-column: 1 / -1;
            }
        }
    </style>
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
            <div id="confirmModal" class="confirm-modal" aria-hidden="true">
                <div class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
                    <h3 id="confirmTitle">Confirm</h3>
                    <p id="confirmMessage">Are you sure?</p>
                    <div class="confirm-actions">
                        <button type="button" class="btn-secondary confirm-cancel">Cancel</button>
                        <button type="button" class="btn-primary confirm-ok">Confirm</button>
                    </div>
                </div>
            </div>
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Generate Bills</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <section class="unit-management-panel">
                <h3 class="section-title">Create Custom Bill — Single Unit</h3>
                <p class="panel-subtext">For one-off charges, a specific resident's rent, or anything that doesn't fit the standard monthly template.</p>
                <form method="post" id="customBillForm">
                    <?php echo workflowCsrfField(); ?>
                    <input type="hidden" name="action" value="custom_bill">
                    <div class="bill-form-grid">
                        <div class="field">
                            <label>Resident</label>
                            <input type="text" id="resident_search" list="resident_options" placeholder="Type unit number or resident name" required>
                            <datalist id="resident_options">
                                <?php foreach ($residents as $resident): ?>
                                    <option value="<?php echo htmlspecialchars($resident['unit_number'] . ' - ' . $resident['full_name']); ?>" data-id="<?php echo (int)$resident['id']; ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <input type="hidden" name="user_id" id="user_id" value="">
                        </div>
                        <div class="field"><label>Due Date</label><input type="date" name="due_date" value="<?php echo $defaultDueDate; ?>" required></div>
                    </div>

                    <div id="itemRows">
                        <div class="item-row">
                            <input class="cat" type="text" name="item_category[]" value="Association Dues" placeholder="Category (e.g. Rent/Lease)">
                            <input class="amt" type="number" step="0.01" min="0" name="item_amount[]" placeholder="Amount">
                            <button type="button" class="btn-small btn-neutral remove-item-row">Remove</button>
                        </div>
                        <div class="item-row">
                            <input class="cat" type="text" name="item_category[]" value="Electricity" placeholder="Category (e.g. Rent/Lease)">
                            <input class="amt" type="number" step="0.01" min="0" name="item_amount[]" placeholder="Amount">
                            <button type="button" class="btn-small btn-neutral remove-item-row">Remove</button>
                        </div>
                        <div class="item-row">
                            <input class="cat" type="text" name="item_category[]" value="Water" placeholder="Category (e.g. Rent/Lease)">
                            <input class="amt" type="number" step="0.01" min="0" name="item_amount[]" placeholder="Amount">
                            <button type="button" class="btn-small btn-neutral remove-item-row">Remove</button>
                        </div>
                        <div class="item-row">
                            <input class="cat" type="text" name="item_category[]" value="Parking Fee" placeholder="Category (e.g. Rent/Lease)">
                            <input class="amt" type="number" step="0.01" min="0" name="item_amount[]" placeholder="Amount">
                            <button type="button" class="btn-small btn-neutral remove-item-row">Remove</button>
                        </div>
                    </div>
                    <button type="button" id="addItemRow" class="btn-small btn-neutral">+ Add Charge</button>
                    <label class="notify-check"><input type="checkbox" name="notify_resident_custom" checked> Email/SMS this resident that their bill is ready</label>
                    <button type="submit">Create Bill</button>
                </form>
            </section>

            <section class="unit-management-panel">
                <h3 class="section-title">Generate Monthly Bills — All Residents</h3>
                <p class="panel-subtext">Create one monthly statement per approved unit owner. Tenants view the owner's unit statement and cannot pay. The due date selects the billing month; repeating it skips existing statements, including paid ones. Earlier balances remain on their original bills.</p>
                <form method="post" id="bulkGenerateForm">
                    <?php echo workflowCsrfField(); ?>
                    <input type="hidden" name="action" value="bulk_generate">
                    <div class="bill-form-grid">
                        <div class="field"><label>Due Date</label><input type="date" name="due_date" value="<?php echo $defaultDueDate; ?>" required></div>
                    </div>

                    <datalist id="bulk_charge_options">
                        <?php foreach ($bulkChargeOptions as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                        <?php endforeach; ?>
                    </datalist>

                    <div id="bulkChargeRows">
                        <div class="bulk-charge-row">
                            <input type="text" name="bulk_category[]" class="bulk-category-select" list="bulk_charge_options" placeholder="Select charge…">
                            <input type="number" step="0.01" min="0" name="bulk_amount[]" placeholder="Amount">
                            <button type="button" class="btn-small btn-neutral remove-bulk-row">Remove</button>
                        </div>
                    </div>

                    <button type="button" id="addBulkChargeRow" class="btn-small btn-neutral">+ Add Charge</button>
                    <label class="notify-check"><input type="checkbox" name="notify_residents" checked> Email/SMS residents that their bill is ready</label>
                    <button type="submit">Generate for All Residents</button>
                </form>
            </section>

            <section class="unit-management-panel bill-history-panel">
                <div class="bill-history-heading">
                    <div>
                        <h3 class="section-title">Generated Bill History</h3>
                        <p class="panel-subtext">Showing up to 100 recent bills. Search resident details, unit, or bill reference.</p>
                    </div>
                    <span class="bill-history-count"><?php echo count($billHistory); ?> records shown</span>
                </div>
                <form class="bill-history-filters" method="get" action="generate_bills.php">
                    <label for="historySearch">Search bill history</label>
                    <input id="historySearch" type="search" name="history_search" value="<?php echo htmlspecialchars($historySearch); ?>" placeholder="Name, username, email, phone, unit, or DUES-123">
                    <label for="historyStatus">Filter by status</label>
                    <select id="historyStatus" name="history_status">
                        <option value="all" <?php echo $historyStatus === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="pending" <?php echo $historyStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="paid" <?php echo $historyStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="overdue" <?php echo $historyStatus === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        <option value="rejected" <?php echo $historyStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="rolled_forward" <?php echo $historyStatus === 'rolled_forward' ? 'selected' : ''; ?>>Rolled Forward</option>
                    </select>
                    <button type="submit">Search</button>
                    <a class="bill-history-reset" href="generate_bills.php">Reset</a>
                </form>
                <div class="bill-history-table-wrap">
                    <table class="unit-table bill-history-table">
                        <thead>
                            <tr><th>Bill Ref</th><th>Resident</th><th>Unit</th><th>Charges</th><th>Total</th><th>Billing Period</th><th>Due Date</th><th>Entered</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($billHistory)): ?>
                                <tr><td colspan="9" class="unit-empty">No bill history matches your search.</td></tr>
                            <?php else: ?>
                                <?php foreach ($billHistory as $bill): ?>
                                    <tr>
                                        <td><strong>DUES-<?php echo (int)$bill['id']; ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($bill['full_name']); ?></strong><small><?php echo htmlspecialchars($bill['email']); ?></small></td>
                                        <td><?php echo htmlspecialchars($bill['unit_number']); ?></td>
                                        <td class="bill-history-charge-list"><?php echo htmlspecialchars($bill['charge_summary'] ?: 'Legacy bill'); ?></td>
                                        <td><strong>₱<?php echo number_format((float)$bill['amount'], 2); ?></strong></td>
                                        <td><?php if (!empty($bill['billing_period_start'])): ?><?php echo htmlspecialchars(date('M j, Y', strtotime($bill['billing_period_start']))); ?> - <?php echo htmlspecialchars(date('M j, Y', strtotime($bill['billing_period_end'] ?: $bill['billing_period_start']))); ?><?php else: ?>—<?php endif; ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($bill['due_date']))); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($bill['created_at']))); ?></td>
                                        <td><span class="unit-status <?php echo htmlspecialchars(strtolower($bill['status'])); ?>"><?php echo htmlspecialchars($bill['status'] === 'rolled_forward' ? 'Rolled Forward' : ucfirst($bill['status'])); ?></span></td>
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
        /* Navigation is handled by the shared UI module. */
        /* Navigation is handled by the shared UI module. */
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => { event.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
        document.addEventListener('click', (event) => { if (!profileMenu.contains(event.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });

        const residentOptions = Array.from(document.querySelectorAll('#resident_options option')).map(option => {
            const value = option.value.trim();
            return {
                id: option.dataset.id || '',
                value: value,
                unit: value.split('-')[0].trim(),
                name: value.includes('-') ? value.split('-').slice(1).join('-').trim() : value
            };
        });

        const residentSearch = document.getElementById('resident_search');
        const residentHiddenId = document.getElementById('user_id');

        function resolveResidentId(inputValue) {
            const query = (inputValue || '').toLowerCase().trim();
            if (!query) {
                residentHiddenId.value = '';
                return;
            }

            const exact = residentOptions.filter(option => option.value.toLowerCase() === query);
            const candidates = exact.length ? exact : residentOptions.filter(option =>
                option.unit.toLowerCase() === query || option.name.toLowerCase() === query);
            const match = candidates.length === 1 ? candidates[0] : null;

            residentHiddenId.value = match ? match.id : '';
        }

        residentSearch.addEventListener('input', (event) => {
            resolveResidentId(event.target.value);
        });

        residentSearch.addEventListener('change', (event) => {
            resolveResidentId(event.target.value);
        });

        const customForm = document.getElementById('customBillForm');
        const customSubmitButton = customForm.querySelector('button[type="submit"]');
        const bulkForm = document.getElementById('bulkGenerateForm');
        const bulkSubmitButton = bulkForm.querySelector('button[type="submit"]');
        const confirmModal = document.getElementById('confirmModal');
        const confirmMessage = document.getElementById('confirmMessage');
        const confirmOk = document.querySelector('.confirm-ok');
        const confirmCancel = document.querySelector('.confirm-cancel');

        let pendingSubmitHandler = null;

        function openConfirmModal(message, onConfirm) {
            confirmMessage.textContent = message;
            pendingSubmitHandler = onConfirm;
            confirmModal.classList.add('open');
            confirmModal.setAttribute('aria-hidden', 'false');
        }

        function closeConfirmModal() {
            confirmModal.classList.remove('open');
            confirmModal.setAttribute('aria-hidden', 'true');
            pendingSubmitHandler = null;
        }

        confirmOk.addEventListener('click', () => {
            if (typeof pendingSubmitHandler === 'function') {
                pendingSubmitHandler();
            }
            closeConfirmModal();
        });

        confirmCancel.addEventListener('click', closeConfirmModal);
        confirmModal.addEventListener('click', (event) => {
            if (event.target === confirmModal) {
                closeConfirmModal();
            }
        });

        customForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const hasAmount = Array.from(document.querySelectorAll('#customBillForm .item-row .amt')).some(input => Number(input.value || 0) > 0);
            if (!hasAmount) {
                updateCustomSubmitState();
                return;
            }
            openConfirmModal('Are you sure you want to create this custom bill for the selected resident?', () => {
                customForm.submit();
            });
        });

        bulkForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const hasAmount = Array.from(document.querySelectorAll('#bulkGenerateForm input[name="bulk_amount[]"]')).some(input => Number(input.value || 0) > 0);
            if (!hasAmount) {
                updateBulkSubmitState();
                return;
            }
            openConfirmModal('Are you sure you want to generate these monthly bills for all residents?', () => {
                bulkForm.submit();
            });
        });

        function updateCustomSubmitState() {
            const hasAmount = Array.from(document.querySelectorAll('#customBillForm .item-row .amt')).some(input => Number(input.value || 0) > 0);
            customSubmitButton.disabled = !hasAmount;
            customSubmitButton.setAttribute('aria-disabled', String(!hasAmount));
        }

        function updateBulkSubmitState() {
            const hasAmount = Array.from(document.querySelectorAll('#bulkGenerateForm input[name="bulk_amount[]"]')).some(input => Number(input.value || 0) > 0);
            bulkSubmitButton.disabled = !hasAmount;
            bulkSubmitButton.setAttribute('aria-disabled', String(!hasAmount));
        }

        const itemRows = document.getElementById('itemRows');

        function attachItemRowHandlers(row) {
            const removeBtn = row.querySelector('.remove-item-row');
            if (!removeBtn) return;

            removeBtn.addEventListener('click', () => {
                row.remove();
                updateCustomSubmitState();
            });

            row.querySelectorAll('.amt').forEach(input => {
                input.addEventListener('input', updateCustomSubmitState);
            });
        }

        document.querySelectorAll('.remove-item-row').forEach(button => {
            const row = button.closest('.item-row');
            if (row) attachItemRowHandlers(row);
        });

        document.getElementById('addItemRow').addEventListener('click', () => {
            const row = itemRows.firstElementChild.cloneNode(true);
            row.querySelectorAll('input').forEach(input => input.value = '');
            attachItemRowHandlers(row);
            itemRows.appendChild(row);
            updateCustomSubmitState();
        });

        document.querySelectorAll('#customBillForm .item-row .amt').forEach(input => {
            input.addEventListener('input', updateCustomSubmitState);
        });

        const bulkChargeRows = document.getElementById('bulkChargeRows');
        const bulkOptions = <?php echo json_encode($bulkChargeOptions); ?>;

        document.getElementById('addBulkChargeRow').addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'bulk-charge-row';

            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'bulk_category[]';
            input.className = 'bulk-category-select';
            input.setAttribute('list', 'bulk_charge_options');
            input.placeholder = 'Select charge…';

            const amount = document.createElement('input');
            amount.type = 'number';
            amount.step = '0.01';
            amount.min = '0';
            amount.name = 'bulk_amount[]';
            amount.placeholder = 'Amount';
            amount.value = '0';

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'btn-small btn-neutral remove-bulk-row';
            removeButton.textContent = 'Remove';
            removeButton.addEventListener('click', () => {
                if (bulkChargeRows.querySelectorAll('.bulk-charge-row').length > 1) {
                    row.remove();
                    updateBulkSubmitState();
                }
            });

            amount.addEventListener('input', updateBulkSubmitState);
            row.appendChild(input);
            row.appendChild(amount);
            row.appendChild(removeButton);
            bulkChargeRows.appendChild(row);
            updateBulkSubmitState();
        });

        bulkChargeRows.querySelectorAll('.remove-bulk-row').forEach(button => {
            button.addEventListener('click', () => {
                if (bulkChargeRows.querySelectorAll('.bulk-charge-row').length > 1) {
                    button.closest('.bulk-charge-row').remove();
                    updateBulkSubmitState();
                }
            });
        });

        bulkChargeRows.querySelectorAll('input[name="bulk_amount[]"]').forEach(input => {
            input.addEventListener('input', updateBulkSubmitState);
        });

        updateCustomSubmitState();
        updateBulkSubmitState();
    </script>
</body>
</html>
