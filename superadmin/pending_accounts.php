<?php
require_once '../config.php';
require_once __DIR__ . '/../includes/resident_accounts.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('accounts.review');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$pendingApprovals = [];
$searchInput = $_POST['search'] ?? $_GET['search'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';
$pendingAccountsUrl = 'pending_accounts.php' . ($search !== '' ? '?search=' . rawurlencode($search) : '');

$connection = connectDb();
ensureNotificationOutboxTable($connection);
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireWorkflowCsrf();

// Handle approval or rejection action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Handle Approve
    if (isset($_POST['approve_user_id'])) {
        $userIdToApprove = (int)$_POST['approve_user_id'];
        $assignedUnit = trim($_POST['assigned_unit'] ?? '');

        if ($assignedUnit === '') {
            $unitStmt = $connection->prepare('SELECT unit_number FROM users WHERE id = ?');
            $unitStmt->bind_param('i', $userIdToApprove);
            $unitStmt->execute();
            $unitStmt->bind_result($existingUnitNumber);
            $unitStmt->fetch();
            $unitStmt->close();
            $assignedUnit = trim((string)($existingUnitNumber ?? ''));
        }

        try {
            $applicant = approvePendingResident($connection, $userIdToApprove, $assignedUnit);
            $assignedUnit = $applicant['unit_number'];
            logAudit('approve', 'user', $userIdToApprove, 'Approved resident and assigned unit ' . $assignedUnit);
            $safeName = htmlspecialchars($applicant['full_name'], ENT_QUOTES, 'UTF-8');
            $safeUnit = htmlspecialchars($assignedUnit, ENT_QUOTES, 'UTF-8');
            $emailBody = '<p>Hello ' . $safeName . ',</p><p>Your Celandine Residences account has been approved. Sign in to the resident portal.</p><p>Unit: ' . $safeUnit . '</p>';
            $accountVersion = $applicant['session_version'];
            $emailQueued = queueNotification($connection, 'account:' . $userIdToApprove . ':approved:' . $accountVersion, 'email', $applicant['email'], '[Celandine Residences] Account Approved', emailLayout('Account Approved', 'Your account is ready', $emailBody), $userIdToApprove, 'account_approved', $accountVersion);
            setFlash('success', 'Resident approved and assigned to unit ' . $assignedUnit . ($emailQueued ? '. Approval email queued for delivery.' : '. Email could not be queued; check notification worker settings.'));
        } catch (InvalidArgumentException $error) {
            setFlash('error', $error->getMessage());
        } catch (Throwable $error) {
            error_log('Resident approval failed: ' . $error->getMessage());
            setFlash('error', 'Unable to approve this account. Please try again.');
        }
        redirect($pendingAccountsUrl);
    }

    // 2. Handle Reject
    if (isset($_POST['reject_user_id'])) {
        $userIdToReject = (int)$_POST['reject_user_id'];
        $selectedRejectionReason = trim($_POST['rejection_reason'] ?? '');
        $customRejectionReason = trim($_POST['custom_rejection_reason'] ?? '');
        $allowedRejectionReasons = [
            'Unit number could not be verified.',
            'Submitted information is incomplete or could not be verified.',
            'An account with these details already exists.',
            'Registration does not meet residency requirements.',
            'Required residency documents were not provided.'
        ];

        if ($selectedRejectionReason === 'Other' && $customRejectionReason !== '' && strlen($customRejectionReason) <= 500) {
            $rejectionReason = 'Other: ' . $customRejectionReason;
        } elseif (in_array($selectedRejectionReason, $allowedRejectionReasons, true)) {
            $rejectionReason = $selectedRejectionReason;
        } else {
            setFlash('error', 'Please select a valid reason for rejecting this account.');
            redirect($pendingAccountsUrl);
        }

        $applicantStmt = $connection->prepare("SELECT full_name, email, session_version FROM users WHERE id = ? AND role = 'resident' AND status = 'pending'");
        $applicantStmt->bind_param('i', $userIdToReject);
        $applicantStmt->execute();
        $applicant = $applicantStmt->get_result()->fetch_assoc();
        $applicantStmt->close();

        if (!$applicant) {
            setFlash('error', 'This registration is no longer pending review.');
            redirect($pendingAccountsUrl);
        }

        $previousVersion = (int)$applicant['session_version'];
        $stmtReject = $connection->prepare("UPDATE users SET status = 'rejected', rejection_reason = ?, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role = 'resident' AND status = 'pending' AND session_version = ?");
        $stmtReject->bind_param('sii', $rejectionReason, $userIdToReject, $previousVersion);
        $rejected = $stmtReject->execute() && $stmtReject->affected_rows === 1;
        $stmtReject->close();
        if ($rejected) {
            logAudit('reject', 'user', $userIdToReject, 'Rejected registration for ' . $applicant['full_name'] . '. Reason: ' . $rejectionReason);
            $safeName = htmlspecialchars($applicant['full_name'], ENT_QUOTES, 'UTF-8');
            $safeReason = nl2br(htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8'));
            $emailBody = '<p style="color:#cbd5e1;line-height:1.6;">Hello ' . $safeName . ',</p>'
                . '<p style="color:#cbd5e1;line-height:1.6;">After review, we could not approve your Celandine Residences registration at this time.</p>'
                . '<p style="color:#f8fafc;font-weight:bold;margin-bottom:6px;">Reason provided by Admin:</p>'
                . '<p style="color:#cbd5e1;line-height:1.6;">' . $safeReason . '</p>'
                . '<p style="color:#cbd5e1;line-height:1.6;">You may sign in to your account to review the reason, update your information, and resubmit your application for review.</p>';
            $accountVersion = $previousVersion + 1;
            $emailQueued = queueNotification($connection, 'account:' . $userIdToReject . ':rejected:' . $accountVersion, 'email', $applicant['email'], '[Celandine Residences] Registration Update', emailLayout('Registration Update', 'Your application was not approved', $emailBody), $userIdToReject, 'account_rejected', $accountVersion);
            setFlash('success', 'User account has been rejected. Reason: ' . $rejectionReason . ($emailQueued ? '. Registration update email queued for delivery.' : '. Email could not be queued; check notification worker settings.'));
        } else {
            setFlash('error', 'Unable to reject this account. Please try again.');
        }
        redirect($pendingAccountsUrl);
    }
}

// Kunin ang mga pending registrations
$pendingResult = $connection->query("SELECT id, full_name, username, email, contact_number, unit_number, account_type, created_at FROM users WHERE role = 'resident' AND is_verified = 1 AND status = 'pending' ORDER BY created_at ASC");
if ($pendingResult) {
    $pendingApprovals = $pendingResult->fetch_all(MYSQLI_ASSOC);
}
if ($search !== '') {
    $pendingApprovals = array_filter($pendingApprovals, static function (array $pending) use ($search): bool {
        foreach (['full_name', 'username', 'email', 'contact_number', 'unit_number', 'account_type'] as $field) {
            if (stripos((string)($pending[$field] ?? ''), $search) !== false) {
                return true;
            }
        }
        return false;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Pending Accounts</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .pending-search-clear { color: var(--link-orange); font-weight: 600; white-space: nowrap; }
        .pending-approve-dialog,
        .pending-reject-dialog {
            position: fixed;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            margin: 0;
            width: min(440px, calc(100% - 32px));
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 12px;
            padding: 24px;
            background: #9ca3aa;
            color: #0f172a;
            box-shadow: 0 18px 48px rgba(15, 23, 42, .28);
        }
        .pending-approve-dialog::backdrop,
        .pending-reject-dialog::backdrop { background: rgba(15, 23, 42, .55); }
        .pending-approve-dialog h2,
        .pending-reject-dialog h2 { margin: 0 0 8px; font-size: 20px; color: #0f172a; }
        .pending-approve-dialog p,
        .pending-reject-dialog p { margin: 0 0 16px; color: #475569; }
        .pending-approve-dialog label,
        .pending-reject-dialog label { display: block; margin-bottom: 6px; font-weight: 700; color: #0f172a; }
        .pending-reject-dialog select,
        .pending-reject-dialog textarea {
            box-sizing: border-box;
            width: 100%;
            min-height: 42px;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #0f172a;
        }
        .pending-reject-dialog textarea {
            min-height: 90px;
            margin-top: 12px;
            resize: vertical;
        }
        .pending-dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .pending-dialog-actions button {
            cursor: pointer;
            min-width: 122px;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            transition: filter 0.15s ease, transform 0.1s ease;
        }
        .pending-dialog-actions button:hover { filter: brightness(0.96); }
        .pending-dialog-actions button:active { transform: translateY(1px); }
        .pending-dialog-actions .dialog-cancel { background: #e2e8f0; color: #0f172a; }
        .pending-dialog-actions .dialog-confirm-approve { background: var(--btn-orange); color: #fff; }
        .pending-dialog-actions .dialog-confirm-reject { background: var(--btn-danger); color: #fff; }

        .system-confirm-dialog.pending-approve-dialog,
        .system-confirm-dialog.pending-reject-dialog {
            background: #111a2b;
            color: #f8fafc;
            border-color: rgba(148, 163, 184, 0.22);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.4);
        }

        .system-confirm-dialog.pending-approve-dialog h2,
        .system-confirm-dialog.pending-reject-dialog h2 { color: #f8fafc; }
        .system-confirm-dialog.pending-approve-dialog p,
        .system-confirm-dialog.pending-reject-dialog p { color: #aab4c4; }
        .system-confirm-dialog.pending-approve-dialog label,
        .system-confirm-dialog.pending-reject-dialog label { color: #e2e8f0; }
        .system-confirm-dialog.pending-reject-dialog select,
        .system-confirm-dialog.pending-reject-dialog textarea {
            border-color: #3b465b;
            background: #1f293d;
            color: #f8fafc;
        }
        .system-confirm-dialog .pending-dialog-actions {
            justify-content: stretch;
        }
        .system-confirm-dialog .pending-dialog-actions button {
            flex: 1 1 0;
            min-width: 0;
            min-height: 38px;
            border-radius: 9px;
        }
        .system-confirm-dialog .pending-dialog-actions .dialog-cancel {
            background: #293346;
            color: #f8fafc;
        }
        .system-confirm-dialog .pending-dialog-actions .dialog-confirm-approve,
        .system-confirm-dialog .pending-dialog-actions .dialog-confirm-reject {
            background: linear-gradient(110deg, #f59e0b, #f97316);
            color: #fff;
        }
    </style>
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Pending Accounts</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <?php $flash = getFlash(); if ($flash): ?>
                <div class="alert pending-flash <?php echo htmlspecialchars($flash['type'] === 'error' ? 'error' : 'success'); ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <section class="unit-page-head">
                <div>
                    <h2>Pending Registrations</h2>
                    <p>Review the unit number submitted during signup and approve or reject registrations.</p>
                </div>
            </section>

            <section class="admin-panel pending-accounts-panel">
                <form class="unit-filters" method="get" action="pending_accounts.php" role="search">
                    <label for="pendingSearch">Search pending accounts</label>
                    <input id="pendingSearch" type="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search name, unit, username, email, contact or purpose..." aria-label="Search pending accounts by name, unit, username, email, contact or purpose">
                    <button type="submit">Search</button>
                    <?php if ($search !== ''): ?><a class="pending-search-clear" href="pending_accounts.php">Clear search</a><?php endif; ?>
                </form>
                <?php if (empty($pendingApprovals)): ?>
                    <p class="admin-empty"><?php echo $search !== '' ? 'No pending accounts match your search.' : 'No pending resident registrations found.'; ?></p>
                <?php else: ?>
                    <div class="pending-table-wrap">
                        <table class="pending-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Contact</th>
                                    <th>Unit</th>
                                    <th>Purpose</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingApprovals as $pending): ?>
                                    <tr>
                                        <td class="pending-name"><?php echo htmlspecialchars($pending['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($pending['username']); ?></td>
                                        <td><?php echo htmlspecialchars($pending['email']); ?></td>
                                        <td><?php echo htmlspecialchars($pending['contact_number']); ?></td>
                                        <td><?php echo htmlspecialchars($pending['unit_number'] ?? 'Not provided'); ?></td>
                                        <td><?php echo htmlspecialchars($pending['account_type'] ?? 'Not provided'); ?></td>
                                        <td class="pending-actions-cell">
                                            <div class="pending-actions">
                                                <button type="button" class="btn-approve" data-approve-user-id="<?php echo (int)$pending['id']; ?>" data-approve-user-name="<?php echo htmlspecialchars($pending['full_name'], ENT_QUOTES); ?>" data-approve-unit="<?php echo htmlspecialchars($pending['unit_number'] ?? '', ENT_QUOTES); ?>">Approve</button>
                                                <button type="button" class="btn-reject" data-reject-user-id="<?php echo (int)$pending['id']; ?>" data-reject-user-name="<?php echo htmlspecialchars($pending['full_name'], ENT_QUOTES); ?>">Reject</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <dialog class="system-confirm-dialog pending-approve-dialog" id="approveDialog" aria-labelledby="approveDialogTitle">
        <form method="POST" action="pending_accounts.php" id="approveForm"><?php echo workflowCsrfField(); ?>
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="approve_user_id" id="approveUserId">
            <label class="field-label" for="approveAssignedUnit">Verified unit</label>
            <select name="assigned_unit" id="approveAssignedUnit" required><option value="">Choose a unit</option><?php foreach(loadUnitInventory() as $unit): ?><option value="<?php echo htmlspecialchars($unit['unit_number'],ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($unit['unit_number']); ?></option><?php endforeach; ?></select>
            <h2 id="approveDialogTitle">Approve resident</h2>
            <p id="approveDialogMessage">Verify ownership or authorized occupancy. Tenants and other occupants require an existing approved owner for the selected unit.</p>
            <div class="pending-dialog-actions system-confirm-actions">
                <button type="button" class="dialog-cancel" id="cancelApprove">Cancel</button>
                <button type="submit" class="dialog-confirm-approve">Confirm Approve</button>
            </div>
        </form>
    </dialog>

    <dialog class="system-confirm-dialog pending-reject-dialog" id="rejectDialog" aria-labelledby="rejectDialogTitle">
        <form method="POST" action="pending_accounts.php" id="rejectForm"><?php echo workflowCsrfField(); ?>
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="reject_user_id" id="rejectUserId">
            <h2 id="rejectDialogTitle">Confirm rejection</h2>
            <p id="rejectDialogMessage">Choose a reason before rejecting this account.</p>
            <label for="rejectionReason">Reason</label>
            <select name="rejection_reason" id="rejectionReason" required>
                <option value="" selected disabled>Select a reason</option>
                <option value="Unit number could not be verified.">Unit number could not be verified</option>
                <option value="Submitted information is incomplete or could not be verified.">Submitted information is incomplete or could not be verified</option>
                <option value="An account with these details already exists.">An account with these details already exists</option>
                <option value="Registration does not meet residency requirements.">Registration does not meet residency requirements</option>
                <option value="Required residency documents were not provided.">Required residency documents were not provided</option>
                <option value="Other">Other (enter a reason)</option>
            </select>
            <textarea name="custom_rejection_reason" id="customRejectionReason" maxlength="500" placeholder="Type the rejection reason" aria-label="Custom rejection reason" hidden></textarea>
            <div class="pending-dialog-actions system-confirm-actions">
                <button type="button" class="dialog-cancel" id="cancelReject">Cancel</button>
                <button type="submit" class="dialog-confirm-reject">Confirm Reject</button>
            </div>
        </form>
    </dialog>
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

        const approveDialog = document.getElementById('approveDialog');
        const approveUserId = document.getElementById('approveUserId');
        const approveAssignedUnit = document.getElementById('approveAssignedUnit');
        const approveDialogMessage = document.getElementById('approveDialogMessage');

        document.querySelectorAll('[data-approve-user-id]').forEach((button) => {
            button.addEventListener('click', () => {
                approveUserId.value = button.dataset.approveUserId;
                approveAssignedUnit.value = button.dataset.approveUnit || '';
                approveDialogMessage.textContent = `Approve ${button.dataset.approveUserName}? Verify ownership or authorized occupancy. A tenant or occupant must link to the approved owner of the selected unit.`;
                approveDialog.showModal();
            });
        });

        document.getElementById('cancelApprove').addEventListener('click', () => approveDialog.close());

        const rejectDialog = document.getElementById('rejectDialog');
        const rejectUserId = document.getElementById('rejectUserId');
        const rejectDialogMessage = document.getElementById('rejectDialogMessage');
        const rejectionReason = document.getElementById('rejectionReason');
        const customRejectionReason = document.getElementById('customRejectionReason');
        document.querySelectorAll('[data-reject-user-id]').forEach((button) => {
            button.addEventListener('click', () => {
                rejectUserId.value = button.dataset.rejectUserId;
                rejectDialogMessage.textContent = `Reject registration for ${button.dataset.rejectUserName}? Select a reason to continue.`;
                rejectionReason.value = '';
                customRejectionReason.value = '';
                customRejectionReason.hidden = true;
                customRejectionReason.required = false;
                rejectDialog.showModal();
            });
        });
        rejectionReason.addEventListener('change', () => {
            const isOtherReason = rejectionReason.value === 'Other';
            customRejectionReason.hidden = !isOtherReason;
            customRejectionReason.required = isOtherReason;
            if (isOtherReason) customRejectionReason.focus();
        });
        document.getElementById('cancelReject').addEventListener('click', () => rejectDialog.close());
    </script>
</body>
</html>
