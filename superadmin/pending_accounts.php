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
$pendingApprovals = [];

$connection = connectDb();

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

        $applicantStmt = $connection->prepare("SELECT full_name, email FROM users WHERE id = ? AND role = 'resident' AND status = 'pending'");
        $applicantStmt->bind_param('i', $userIdToApprove);
        $applicantStmt->execute();
        $applicant = $applicantStmt->get_result()->fetch_assoc();
        $applicantStmt->close();

        if (!$applicant) {
            setFlash('error', 'This registration is no longer pending approval.');
        } elseif ($assignedUnit !== '') {
            // I-check muna kung mayroon nang approved account ang unit na ito (Limit: 1 per unit)
            $stmtCheck = $connection->prepare("SELECT COUNT(*) FROM users WHERE unit_number = ? AND role = 'resident' AND status = 'approved'");
            $stmtCheck->bind_param('s', $assignedUnit);
            $stmtCheck->execute();
            $stmtCheck->bind_result($approvedCount);
            $stmtCheck->fetch();
            $stmtCheck->close();

            if ($approvedCount >= 1) {
                setFlash('error', 'Hindi ma-approve. Ang unit na ' . $assignedUnit . ' ay mayroon nang naka-assign na active o approved na resident account.');
            } else {
                $stmtApprove = $connection->prepare("UPDATE users SET unit_number = ?, status = 'approved', is_active = 1 WHERE id = ?");
                $stmtApprove->bind_param('si', $assignedUnit, $userIdToApprove);
                $approved = $stmtApprove->execute();
                $stmtApprove->close();
                if ($approved) {
                    logAudit('approve', 'user', $userIdToApprove, 'Approved ' . $applicant['full_name'] . ' and assigned to unit ' . $assignedUnit);
                    $safeName = htmlspecialchars($applicant['full_name'], ENT_QUOTES, 'UTF-8');
                    $safeUnit = htmlspecialchars($assignedUnit, ENT_QUOTES, 'UTF-8');
                    $emailBody = '<p style="color:#cbd5e1;line-height:1.6;">Hello ' . $safeName . ',</p>'
                        . '<p style="color:#cbd5e1;line-height:1.6;">Your Celandine Residences account has been approved. You can now sign in to the resident portal.</p>'
                        . '<p style="color:#f8fafc;font-weight:bold;">Unit: ' . $safeUnit . '</p>'
                        . '<p style="color:#9ca3af;font-size:13px;">If you have questions, please contact the Admin or Property Manager.</p>';
                    $emailSent = sendMail($applicant['email'], '[Celandine Residences] Account Approved', emailLayout('Account Approved', 'Your account is ready', $emailBody));
                    setFlash('success', 'User successfully approved and assigned to unit ' . $assignedUnit . ($emailSent ? '. Approval email sent.' : '. Approval email could not be sent; check SMTP settings.'));
                } else {
                    setFlash('error', 'Unable to approve this account. Please try again.');
                }
            }
        } else {
            setFlash('error', 'Please provide a unit number for the user.');
        }
        redirect('pending_accounts.php');
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
            redirect('pending_accounts.php');
        }

        $applicantStmt = $connection->prepare("SELECT full_name, email FROM users WHERE id = ? AND role = 'resident' AND status = 'pending'");
        $applicantStmt->bind_param('i', $userIdToReject);
        $applicantStmt->execute();
        $applicant = $applicantStmt->get_result()->fetch_assoc();
        $applicantStmt->close();

        if (!$applicant) {
            setFlash('error', 'This registration is no longer pending review.');
            redirect('pending_accounts.php');
        }

        $stmtReject = $connection->prepare("UPDATE users SET status = 'rejected', rejection_reason = ? WHERE id = ? AND status = 'pending'");
        $stmtReject->bind_param('si', $rejectionReason, $userIdToReject);
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
            $emailSent = sendMail($applicant['email'], '[Celandine Residences] Registration Update', emailLayout('Registration Update', 'Your application was not approved', $emailBody));
            setFlash('success', 'User account has been rejected. Reason: ' . $rejectionReason . ($emailSent ? '. Notification email sent.' : '. Notification email could not be sent; check SMTP settings.'));
        } else {
            setFlash('error', 'Unable to reject this account. Please try again.');
        }
        redirect('pending_accounts.php');
    }
}

// Kunin ang mga pending registrations
$pendingResult = $connection->query("SELECT id, full_name, username, email, contact_number, unit_number, account_type, created_at FROM users WHERE role = 'resident' AND is_verified = 1 AND status = 'pending' ORDER BY created_at ASC");
if ($pendingResult) {
    $pendingApprovals = $pendingResult->fetch_all(MYSQLI_ASSOC);
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
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="admin_dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav">
                <a href="admin_dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <a href="units.php" class="sidebar-link"><?php echo systemSidebarIcon('units'); ?> Units</a>
                <a href="residents.php" class="sidebar-link"><?php echo systemSidebarIcon('residents'); ?> Residents</a>
                <?php if (isSuperAdmin()): ?>
                    <a href="pending_accounts.php" class="sidebar-link active"><?php echo systemSidebarIcon('pending'); ?> Pending Accounts</a>
                    <a href="staff.php" class="sidebar-link"><?php echo systemSidebarIcon('staff'); ?> Staff Management</a>
                    <a href="unitpayments.php" class="sidebar-link"><?php echo systemSidebarIcon('billing'); ?> Billing &amp; Payments</a>
                    <a href="generate_bills.php" class="sidebar-link"><?php echo systemSidebarIcon('bills'); ?> Generate Bills</a>
                    <a href="violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
                <?php endif; ?>
                <a href="bookingrequest.php" class="sidebar-link"><?php echo systemSidebarIcon('calendar'); ?> Booking Requests</a>
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
                <?php if (empty($pendingApprovals)): ?>
                    <p class="admin-empty">No pending resident registrations found.</p>
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
        <form method="POST" action="pending_accounts.php" id="approveForm">
            <input type="hidden" name="approve_user_id" id="approveUserId">
            <input type="hidden" name="assigned_unit" id="approveAssignedUnit">
            <h2 id="approveDialogTitle">Approve resident</h2>
            <p id="approveDialogMessage">Are you sure you want to approve this resident account?</p>
            <div class="pending-dialog-actions system-confirm-actions">
                <button type="button" class="dialog-cancel" id="cancelApprove">Cancel</button>
                <button type="submit" class="dialog-confirm-approve">Confirm Approve</button>
            </div>
        </form>
    </dialog>

    <dialog class="system-confirm-dialog pending-reject-dialog" id="rejectDialog" aria-labelledby="rejectDialogTitle">
        <form method="POST" action="pending_accounts.php" id="rejectForm">
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
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
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
                approveDialogMessage.textContent = `Approve ${button.dataset.approveUserName} for unit ${approveAssignedUnit.value || 'not provided'}? This will activate the resident account.`;
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