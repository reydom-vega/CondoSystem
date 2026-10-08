<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isSuperAdmin()) {
    redirect('../admin/admin_dashboard.php');
}

$username = $_SESSION['username'] ?? 'SuperAdmin';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$roleLabels = getUserRoles();
$staffRoles = array_diff_key($roleLabels, ['resident' => true]);
$roleFilter = $_GET['role'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$errors = [];
$success = '';
$connection = connectDb();
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireWorkflowCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $newRole = strtolower(trim($_POST['role'] ?? ''));

    if ($action === 'add_staff') {
        $fullName = trim($_POST['full_name'] ?? '');
        $newUsername = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $newRole = strtolower(trim($_POST['role'] ?? ''));

        if ($fullName === '' || $contactNumber === '' || !isset($staffRoles[$newRole])) {
            $errors[] = 'Please complete the staff account fields.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newUsername)) {
            $errors[] = 'Username must be 3-20 characters and contain only letters, numbers, or underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        } elseif (!isPasswordStrong($password)) {
            $errors[] = 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.';
        } else {
            $check = $connection->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $check->bind_param('ss', $newUsername, $email);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                $errors[] = 'That username or email is already in use.';
            } else {
                ensureAuditLogTable($connection);
                $connection->begin_transaction();
                try {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $unitNumber = 'ADMIN'; $isVerified = 1;
                    $insert = $connection->prepare('INSERT INTO users (full_name, username, email, contact_number, unit_number, password_hash, is_verified, is_active, role) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
                    $insert->bind_param('ssssssis', $fullName, $newUsername, $email, $contactNumber, $unitNumber, $passwordHash, $isVerified, $newRole);
                    if (!$insert->execute()) throw new RuntimeException('Staff account insert failed.');
                    $createdUserId = (int)$connection->insert_id;
                    $staffIdLabel = 'STAFF-' . str_pad((string)$createdUserId, 5, '0', STR_PAD_LEFT);
                    $staffUpdate = $connection->prepare('UPDATE users SET staff_id = ? WHERE id = ?');
                    $staffUpdate->bind_param('si', $staffIdLabel, $createdUserId);
                    if (!$staffUpdate->execute() || $staffUpdate->affected_rows !== 1) throw new RuntimeException('Staff ID assignment failed.');
                    if (!logAudit('create', 'staff', $createdUserId, 'Created staff account with role ' . $roleLabels[$newRole], $connection)) throw new RuntimeException('Staff creation audit failed.');
                    $connection->commit();
                    $success = 'Staff account created successfully.';
                } catch (Throwable $error) {
                    $connection->rollback();
                    error_log('Staff account creation failed: ' . $error->getMessage());
                    $errors[] = 'Unable to create that staff account. Check the details and try again.';
                }

            }
        }
    } elseif ($action === 'toggle_status') {
        $active = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        if ($staffId <= 0 || $staffId === (int)($_SESSION['user_id'] ?? 0)) {
            $errors[] = 'You cannot deactivate or change your own account.';
        } else {
            $update = $connection->prepare("UPDATE users SET is_active = ?, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role <> 'resident'");
            $update->bind_param('ii', $active, $staffId);
            if ($update->execute() && $update->affected_rows > 0) {
                ensureRememberTokensTable($connection);
                $tokens = $connection->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
                $tokens->bind_param('i', $staffId);
                $tokens->execute();
                logAudit($active ? 'reactivate' : 'deactivate', 'staff', $staffId, 'Staff account ' . ($active ? 'reactivated' : 'deactivated'));
                $success = 'Staff account status updated.';
            } else {
                $errors[] = 'Unable to update that staff account status.';
            }
        }
    } elseif ($action === 'force_logout') {
        if ($staffId <= 0 || $staffId === (int)($_SESSION['user_id'] ?? 0)) {
            $errors[] = 'You cannot force logout your own account.';
        } else {
            $update = $connection->prepare("UPDATE users SET reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role <> 'resident'");
            $update->bind_param('i', $staffId);
            if ($update->execute() && $update->affected_rows > 0) {
                ensureRememberTokensTable($connection);
                $tokens = $connection->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
                $tokens->bind_param('i', $staffId);
                $tokens->execute();
                logAudit('force_logout', 'staff', $staffId, 'Invalidated active sessions and remember-me tokens');
                $success = 'Staff sessions invalidated.';
            } else {
                $errors[] = 'Unable to invalidate that staff account sessions.';
            }
        }
    } elseif ($action === 'reset_password') {
        $newPassword = $_POST['new_password'] ?? '';
        if ($staffId <= 0 || $staffId === (int)($_SESSION['user_id'] ?? 0)) {
            $errors[] = 'You cannot reset your own password here.';
        } elseif (!isPasswordStrong($newPassword)) {
            $errors[] = 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $connection->prepare("UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role <> 'resident'");
            $update->bind_param('si', $passwordHash, $staffId);
            if ($update->execute() && $update->affected_rows > 0) {
                ensureRememberTokensTable($connection);
                $tokens = $connection->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
                $tokens->bind_param('i', $staffId);
                $tokens->execute();
                logAudit('reset_password', 'staff', $staffId, 'Staff password reset by SuperAdmin');
                $success = 'Staff password reset and active sessions invalidated.';
            } else {
                $errors[] = 'Unable to reset that staff password.';
            }
        }
    } elseif ($action === 'update_role' && ($staffId <= 0 || !isset($staffRoles[$newRole]))) {
        $errors[] = 'Please select a valid staff role.';
    } elseif ($action === 'update_role' && $staffId === (int)($_SESSION['user_id'] ?? 0)) {
        $errors[] = 'You cannot change your own SuperAdmin role.';
    } elseif ($action === 'update_role') {
        $update = $connection->prepare("UPDATE users SET role = ?, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role <> 'resident'");
        $update->bind_param('si', $newRole, $staffId);
        if ($update->execute() && $update->affected_rows > 0) {
            $connection->query('UPDATE users SET session_version = session_version + 1 WHERE id = ' . $staffId);
            ensureRememberTokensTable($connection);
            $tokens = $connection->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
            $tokens->bind_param('i', $staffId);
            $tokens->execute();
            logAudit('update_role', 'staff', $staffId, 'Staff role changed to ' . $roleLabels[$newRole]);
            $success = 'Staff role updated successfully.';
        } else {
            $errors[] = 'Unable to update that staff account.';
        }
    }
}

$staff = [];
$result = $connection->query("SELECT id, staff_id, full_name, username, email, role, is_verified, is_active, last_login_at, last_seen_at, created_at FROM users WHERE role <> 'resident' ORDER BY role ASC, full_name ASC");
if ($result) {
    while ($member = $result->fetch_assoc()) {
        if ($roleFilter !== 'all' && $member['role'] !== $roleFilter) {
            continue;
        }
        $haystack = strtolower(implode(' ', [$member['full_name'], $member['username'], $member['email'], $member['role']]));
        if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
            continue;
        }
        $staff[] = $member;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Staff Management</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
    .staff-role-form {
        display: flex;
        align-items: center;
        gap: 6px;
        margin: 0;
    }

    .staff-role-form select {
        width: 120px;
        min-width: 120px;
    }

    .staff-status {
        display: inline-block;
        padding: 4px 7px;
        border-radius: 5px;
        font-size: 9px;
        font-weight: 700;
        white-space: nowrap;
    }

    .staff-inactive {
        color: #fca5a5;
    }

    .staff-last-login {
        display: block;
        color: var(--text-muted);
        font-size: 9px;
        margin-top: 3px;
        white-space: nowrap;
    }


    .staff-create-form {
        display: grid;
        grid-template-columns:
            1.2fr
            1fr
            1.2fr
            1fr
            1fr
            .9fr
            130px;

        gap: 10px;

        align-items: end;

        margin-bottom: 18px;
    }

    .staff-create-form label {
        display: flex !important;
        flex-direction: column;
        gap: 5px;

        min-width: 0;

        color: var(--text-muted);
        font-size: 10px;
        font-weight: 500;
    }

    .staff-create-form input,
    .staff-create-form select,
    .staff-table select {
        width: 100%;
        min-width: 0;

        height: 34px;

        padding: 7px 9px;

        border: 1px solid var(--input-border);
        border-radius: 7px;

        background: var(--input-bg);
        color: var(--text-primary);

        font: inherit;
        font-size: 11px;

        box-sizing: border-box;
    }

    .staff-create-form input::placeholder {
        color: #64748b;
    }

    .staff-create-form input:focus,
    .staff-create-form select:focus,
    .staff-table select:focus {
        outline: none;

        border-color: var(--link-orange);

        box-shadow:
            0 0 0 2px rgba(245, 158, 11, 0.15);
    }

    .staff-create-form > button {
        height: 34px;
        padding: 0 14px;

        white-space: nowrap;

        font-size: 11px;
        font-weight: 600;
    }


    .staff-search-row {
        display: grid;
        grid-template-columns: 1fr 130px 70px;

        gap: 8px;

        margin-bottom: 12px;
    }

    .staff-search-row input,
    .staff-search-row select {
        width: 100%;
        height: 34px;

        padding: 7px 10px;

        box-sizing: border-box;

        border: 1px solid var(--input-border);
        border-radius: 7px;

        background: var(--input-bg);
        color: var(--text-primary);

        font-size: 11px;
    }

    .staff-search-row button {
        height: 34px;
        padding: 0 12px;

        font-size: 10px;
    }




    .staff-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
    }

    .staff-table th,
    .staff-table td {
        box-sizing: border-box;
        overflow: hidden;
    }

    .staff-table th {
        padding: 9px 8px;

        font-size: 9px;
        font-weight: 700;

        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .staff-table td {
        padding: 10px 8px;

        font-size: 10px;

        vertical-align: middle;

        overflow-wrap: anywhere;
        word-break: break-word;
    }



    .staff-table th:nth-child(1),
    .staff-table td:nth-child(1) {
        width: 9%;
    }

    .staff-table th:nth-child(2),
    .staff-table td:nth-child(2) {
        width: 15%;
    }

    .staff-table th:nth-child(3),
    .staff-table td:nth-child(3) {
        width: 16%;
    }

    .staff-table th:nth-child(4),
    .staff-table td:nth-child(4) {
        width: 12%;
    }

    .staff-table th:nth-child(5),
    .staff-table td:nth-child(5) {
        width: 9%;
    }

    .staff-table th:nth-child(6),
    .staff-table td:nth-child(6) {
        width: 12%;
    }

    .staff-table th:nth-child(7),
    .staff-table td:nth-child(7) {
        width: 9%;
    }

    .staff-table th:nth-child(8),
    .staff-table td:nth-child(8) {
        width: 26%;
    }



    .staff-table td strong {
        display: block;

        max-width: 100%;

        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;

        font-size: 10px;
    }

    .staff-table td small {
        display: block;

        max-width: 100%;

        margin-top: 2px;

        color: var(--text-muted);

        font-size: 8px;

        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }



    .staff-table td:nth-child(3) {
        font-size: 9px;

        overflow-wrap: anywhere;
        word-break: break-word;
    }



    .staff-table td:nth-child(4) select {
        height: 30px;

        padding: 5px 7px;

        font-size: 9px;
    }

.staff-actions {
    display: grid;
    grid-template-columns: minmax(82px, 90px) minmax(0, 1fr);
    gap: 5px;
    align-items: start;
    width: 100%;
    min-width: 0;
}

.staff-actions > form:nth-child(1) {
    grid-column: 1;
    grid-row: 1;
}

.staff-actions > form:nth-child(2) {
    grid-column: 1;
    grid-row: 2;
}

.staff-actions > form:nth-child(3) {
    grid-column: 2;
    grid-row: 1 / span 2;
    display: flex;
    flex-direction: column;
    gap: 5px;
    width: 100%;
    min-width: 0;
}

.staff-actions > form:nth-child(1) button,
.staff-actions > form:nth-child(2) button {
    width: 90px;
    min-width: 90px;
    padding: 5px 6px;
    font-size: 9px;
    white-space: nowrap;
}

.staff-actions > form:nth-child(3) input {
    width: 100%;
    min-width: 0;
    height: 32px;
    box-sizing: border-box;
    padding: 7px 9px;
    font-size: 10px;
}

.staff-actions > form:nth-child(3) button {
    width: 100%;
    min-width: 0;
    height: 25px;
    padding: 4px 6px;
    font-size: 9px;
    white-space: nowrap;
    align-self: stretch;
}

    .staff-confirm-overlay {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.58);
        z-index: 2000;
        padding: 20px;
    }

    .staff-confirm-overlay.open {
        display: flex;
    }

    .staff-confirm-modal {
        width: min(420px, 100%);
        padding: 22px 20px 18px;
        border-radius: 14px;
        background: var(--panel-bg, #111827);
        border: 1px solid var(--border-color, rgba(148, 163, 184, 0.2));
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
    }

    .staff-confirm-modal h3 {
        margin: 0 0 10px;
        font-size: 18px;
        color: var(--text-primary);
    }

    .staff-confirm-modal p {
        margin: 0 0 18px;
        color: var(--text-muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .staff-confirm-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .staff-confirm-cancel,
    .staff-confirm-submit {
        border: 0;
        border-radius: 8px;
        padding: 10px 16px;
        font-weight: 700;
        cursor: pointer;
    }

    .staff-confirm-cancel {
        background: rgba(148, 163, 184, 0.18);
        color: var(--text-primary);
    }

    .staff-confirm-submit {
        background: linear-gradient(135deg, #f59e0b, #f97316);
        color: #fff;
    }

    .unit-page-head {
        margin-bottom: 12px;
    }

    .unit-management-panel {
        padding: 16px;
    }

    .unit-management-panel h3 {
        margin: 0 0 12px;
        font-size: 15px;
    }

    .unit-table-wrap {
        width: 100%;

        overflow-x: auto;
        overflow-y: hidden;

        border-radius: 8px;
    }


    /* =========================================
       RESPONSIVE
       ========================================= */

    @media (max-width: 1200px) {

        .staff-create-form {
            grid-template-columns:
                repeat(4, minmax(130px, 1fr));
        }

        .staff-create-form > button {
            width: 100%;
        }

        .staff-search-row {
            grid-template-columns: 1fr 130px 70px;
        }
    }


    @media (max-width: 900px) {

        .staff-create-form {
            grid-template-columns:
                repeat(2, minmax(140px, 1fr));
        }

        .staff-create-form > button {
            width: 100%;
        }

        .staff-search-row {
            grid-template-columns: 1fr 120px 70px;
        }

        .staff-table {
            min-width: 950px;
        }
    }


    @media (max-width: 600px) {

        .staff-create-form {
            grid-template-columns: 1fr;
        }

        .staff-search-row {
            grid-template-columns: 1fr;
        }

        .staff-search-row button {
            width: 100%;
        }

        .staff-table {
            min-width: 950px;
        }
    }
</style>
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Staff Management</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">SuperAdmin</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <section class="unit-page-head"><div><h2>Staff Management</h2><p>Review staff accounts and assign their system roles.</p></div><div class="unit-summary"><span><?php echo count($staff); ?> Staff Accounts</span></div></section>
            <section class="unit-management-panel">
                <h3>Add Staff Account</h3>
                <form method="post" class="staff-create-form" id="staffCreateForm"><?php echo workflowCsrfField(); ?>
                    <input type="hidden" name="action" value="add_staff">
                    <label>Full name<input type="text" name="full_name" required maxlength="100"></label>
                    <label>Username<input type="text" name="username" required maxlength="20"></label>
                    <label>Email<input type="email" name="email" required maxlength="100"></label>
                    <label>Contact number<input type="text" name="contact_number" required maxlength="20"></label>
                    <label>Password<input type="password" name="password" required minlength="8"></label>
                    <label>Role<select name="role" required><?php foreach ($staffRoles as $role => $label): ?><option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></label>
                    <button type="submit">Add Staff</button>
                </form>
                <form class="staff-search-row" method="get" action="staff.php">
                    <label for="staffSearch">Search staff</label><input id="staffSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, username, or email...">
                    <label for="staffRole">Filter by role</label><select id="staffRole" name="role"><option value="all">All roles</option><?php foreach ($staffRoles as $role => $label): ?><option value="<?php echo htmlspecialchars($role); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select>
                    <button type="submit">Filter</button>
                </form>
                <div class="unit-table-wrap"><table class="unit-table staff-table"><thead><tr><th>Staff ID</th><th>Staff Member</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Created</th><th>Actions</th></tr></thead><tbody>
                    <?php if (empty($staff)): ?><tr><td colspan="8" class="unit-empty">No staff accounts found.</td></tr><?php else: foreach ($staff as $member): ?><tr>
                        <td><strong><?php echo htmlspecialchars($member['staff_id'] ?: 'Pending'); ?></strong></td>
                        <td><strong><?php echo htmlspecialchars($member['full_name']); ?></strong><small>@<?php echo htmlspecialchars($member['username']); ?></small></td>
                        <td><?php echo htmlspecialchars($member['email']); ?></td>
                        <td><form method="post" class="staff-role-form"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="update_role"><input type="hidden" name="staff_id" value="<?php echo (int)$member['id']; ?>"><select name="role" aria-label="Role for <?php echo htmlspecialchars($member['username']); ?>" onchange="this.form.submit()"><?php foreach ($staffRoles as $role => $label): ?><option value="<?php echo htmlspecialchars($role); ?>" <?php echo $member['role'] === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></form></td>
                        <td>
                            <?php $presence = userPresenceSummary($member['last_seen_at'] ?? null, $member['last_login_at'] ?? null); ?>
                            <span class="staff-status <?php echo $presence['online'] ? 'online' : 'offline'; ?>"><?php echo htmlspecialchars($presence['label']); ?></span>
                            <small class="staff-last-login"><?php echo htmlspecialchars($presence['detail']); ?></small>
                        </td>
                        <td><?php echo $member['last_login_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($member['last_login_at']))) : 'Never'; ?></td>
                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($member['created_at']))); ?></td>
                        <td><div class="staff-actions">
                            <form method="post"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="staff_id" value="<?php echo (int)$member['id']; ?>"><input type="hidden" name="is_active" value="<?php echo (int)$member['is_active'] === 1 ? '0' : '1'; ?>"><button type="submit"><?php echo (int)$member['is_active'] === 1 ? 'Deactivate' : 'Reactivate'; ?></button></form>
                            <form method="post"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="force_logout"><input type="hidden" name="staff_id" value="<?php echo (int)$member['id']; ?>"><button type="submit">Force Logout</button></form>
                            <form method="post"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="staff_id" value="<?php echo (int)$member['id']; ?>"><input type="password" name="new_password" placeholder="New password" minlength="8" required><button type="submit">Change Password</button></form>
                        </div></td>
                    </tr><?php endforeach; endif; ?>
                </tbody></table></div>
            </section>
        </main>
    </div>

    <div class="confirm-modal system-confirm-overlay staff-confirm-overlay" id="staffConfirmOverlay" aria-hidden="true">
        <div class="confirm-dialog staff-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="staffConfirmTitle">
            <h3 id="staffConfirmTitle">Confirm staff account</h3>
            <p id="staffConfirmText">Create this staff account?</p>
            <div class="confirm-actions staff-confirm-actions">
                <button type="button" class="confirm-cancel staff-confirm-cancel" id="staffConfirmCancel">Cancel</button>
                <button type="button" class="confirm-ok staff-confirm-submit" id="staffConfirmSubmit">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });

        const staffCreateForm = document.getElementById('staffCreateForm');
        const staffConfirmOverlay = document.getElementById('staffConfirmOverlay');
        const staffConfirmText = document.getElementById('staffConfirmText');
        const staffConfirmCancel = document.getElementById('staffConfirmCancel');
        const staffConfirmSubmit = document.getElementById('staffConfirmSubmit');
        let pendingStaffForm = null;

        staffCreateForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const fullName = staffCreateForm.querySelector('[name="full_name"]').value.trim() || 'this staff member';
            const role = staffCreateForm.querySelector('[name="role"] option:checked')?.textContent?.trim() || 'selected role';

            pendingStaffForm = staffCreateForm;
            staffConfirmText.textContent = 'Create the account for ' + fullName + ' with the ' + role + ' role?';
            staffConfirmOverlay.classList.add('open');
            staffConfirmOverlay.setAttribute('aria-hidden', 'false');
        });

        staffConfirmCancel.addEventListener('click', () => {
            staffConfirmOverlay.classList.remove('open');
            staffConfirmOverlay.setAttribute('aria-hidden', 'true');
            pendingStaffForm = null;
        });

        staffConfirmSubmit.addEventListener('click', () => {
            if (pendingStaffForm) {
                staffConfirmOverlay.classList.remove('open');
                staffConfirmOverlay.setAttribute('aria-hidden', 'true');
                pendingStaffForm.submit();
            }
        });
    </script>
    <script src="../js/profile-menu.js"></script>
    <script src="../js/notification-menu.js"></script>
</body>
</html>
