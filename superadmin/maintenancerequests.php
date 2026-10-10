<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('maintenance.work');

$username = $_SESSION['username'] ?? 'Maintenance';
$roleLabel = getUserRoles()[$_SESSION['role'] ?? 'maintenance'] ?? 'Maintenance';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
$tableReady = ensureMaintenanceTable($connection);
$errors = [];
$success = '';
$statuses = ['pending', 'approved', 'in_progress', 'completed', 'closed', 'rejected', 'cancelled', 'reopened'];
$statusCounts = array_fill_keys($statuses, 0);
$statusFilter = (string)($_GET['status'] ?? 'all');
$search = trim((string)($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $requestQuery = $connection->prepare('SELECT status FROM maintenance_requests WHERE id = ? LIMIT 1');
    $requestQuery->bind_param('i', $requestId);
    $requestQuery->execute();
    $current = $requestQuery->get_result()->fetch_assoc();
    $currentStatus = $current['status'] ?? '';
    $transitions = [
        'approve' => ['pending', 'approved'],
        'reject' => ['pending', 'rejected'],
        'start' => ['approved', 'in_progress'],
        'complete' => ['in_progress', 'completed'],
        'reopen_start' => ['reopened', 'in_progress'],
        'cancel' => ['pending', 'cancelled'],
    ];

    if (in_array($action, ['approve', 'reject', 'cancel'], true) && !canAccess('maintenance.review')) {
        $errors[] = 'Only management can review or cancel a pending request.';
    } elseif (!$tableReady || $requestId <= 0 || !$current) {
        $errors[] = 'Maintenance request not found.';
    } elseif (isset($transitions[$action])) {
        [$expectedStatus, $nextStatus] = $transitions[$action];
        if ($currentStatus !== $expectedStatus) {
            $errors[] = 'This request has changed and cannot use that action now.';
        } elseif ($action === 'complete') {
            $completionNote = trim((string)($_POST['completion_note'] ?? ''));
            if (strlen($completionNote) > 5000) $errors[] = 'Completion notes must be 5,000 characters or fewer.';
            $before = storeMaintenanceImage($_FILES['before_photo'] ?? []);
            $after = storeMaintenanceImage($_FILES['after_photo'] ?? []);
            foreach ([$before, $after] as $upload) {
                if ($upload['error'] !== '') {
                    $errors[] = $upload['error'];
                }
            }
            if (!$errors) {
                $update = $connection->prepare('UPDATE maintenance_requests SET status = ?, completion_note = ?, before_photo_path = COALESCE(?, before_photo_path), after_photo_path = COALESCE(?, after_photo_path) WHERE id = ? AND status = ?');
                $beforePath = $before['path'];
                $afterPath = $after['path'];
                $update->bind_param('ssssis', $nextStatus, $completionNote, $beforePath, $afterPath, $requestId, $expectedStatus);
                if ($update->execute() && $update->affected_rows === 1) {
                    $success = 'Request marked completed.';
                    logAudit('update', 'maintenance_request', $requestId, 'Maintenance request completed with notes');
                } else {
                    $errors[] = 'Unable to update the request.';
                }
            }
            if ($errors) {
                foreach ([$before, $after] as $upload) {
                    if (!empty($upload['path'])) {
                        @unlink(dirname(__DIR__) . '/private_uploads/maintenance_evidence/' . $upload['path']);
                    }
                }
            }
        } else {
            $update = $connection->prepare('UPDATE maintenance_requests SET status = ? WHERE id = ? AND status = ?');
            $update->bind_param('sis', $nextStatus, $requestId, $expectedStatus);
            if ($update->execute() && $update->affected_rows === 1) {
                $success = 'Request status updated.';
                logAudit($action === 'reject' ? 'reject' : ($action === 'approve' ? 'approve' : 'update'), 'maintenance_request', $requestId, 'Maintenance request status set to ' . $nextStatus);
            } else {
                $errors[] = 'Unable to update the request.';
            }
        }
    } else {
        $errors[] = 'Invalid maintenance request action.';
    }
}

$requests = [];
$selectedRequest = null;
if ($tableReady) {
    $statusResult = $connection->query('SELECT status, COUNT(*) AS total FROM maintenance_requests GROUP BY status');
    if ($statusResult) {
        while ($statusRow = $statusResult->fetch_assoc()) {
            if (isset($statusCounts[$statusRow['status']])) {
                $statusCounts[$statusRow['status']] = (int)$statusRow['total'];
            }
        }
    }
    $sql = "SELECT m.*, u.full_name, u.username, u.unit_number FROM maintenance_requests m INNER JOIN users u ON u.id = m.user_id WHERE (? = 'all' OR m.status = ?) AND (? = '' OR u.full_name LIKE ? OR u.username LIKE ? OR u.unit_number LIKE ? OR m.issue_type LIKE ? OR CAST(m.id AS CHAR) LIKE ? OR CONCAT('MR-', LPAD(m.id, 5, '0')) LIKE ?) ORDER BY m.created_at DESC";
    $query = $connection->prepare($sql);
    $effectiveStatus = in_array($statusFilter, $statuses, true) ? $statusFilter : 'all';
    $needle = '%' . $search . '%';
    $requestIdSearch = preg_match('/\AMR-(\d+)\z/i', $search, $idMatch) ? ltrim($idMatch[1], '0') : $search;
    $requestIdNeedle = '%' . ($requestIdSearch === '' ? '0' : $requestIdSearch) . '%';
    $requestCodeNeedle = '%' . strtoupper($search) . '%';
    $query->bind_param('sssssssss', $effectiveStatus, $effectiveStatus, $search, $needle, $needle, $needle, $needle, $requestIdNeedle, $requestCodeNeedle);
    $query->execute();
    $requests = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $selectedId = (int)($_GET['view'] ?? 0);
    foreach ($requests as $request) {
        if ((int)$request['id'] === $selectedId) {
            $selectedRequest = $request;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Maintenance Requests</title>
    <link rel="stylesheet" href="../styles.css">
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
            <header class="dash-header"><div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Maintenance Requests</h1></div></div><div class="dash-header-right"><?php include '../notifications.php'; ?><div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button><div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit"><?php echo htmlspecialchars($roleLabel); ?></span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div></div></div></header>
            <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <section class="unit-page-head"><div><h2>Maintenance Requests</h2><p>Review, approve, and track resident maintenance requests.</p></div></section>

            <?php if ($selectedRequest): ?>
                <section class="maintenance-admin-detail">
                    <div class="maintenance-admin-detail-head"><div><span class="dash-subtitle">REQUEST DETAILS</span><h2>Maintenance Request #<?php echo maintenanceRequestCode((int)$selectedRequest['id']); ?></h2></div><span class="maintenance-status-badge status-<?php echo htmlspecialchars($selectedRequest['status']); ?>"><?php echo htmlspecialchars(maintenanceStatusLabel($selectedRequest['status'])); ?></span></div>
                    <dl class="maintenance-admin-detail-grid"><div><dt>Resident</dt><dd><?php echo htmlspecialchars($selectedRequest['username']); ?> (<?php echo htmlspecialchars($selectedRequest['full_name']); ?>)</dd></div><div><dt>Unit</dt><dd><?php echo htmlspecialchars($selectedRequest['unit_number']); ?></dd></div><div><dt>Issue</dt><dd><?php echo htmlspecialchars($selectedRequest['issue_type']); ?></dd></div><div><dt>Location</dt><dd><?php echo htmlspecialchars($selectedRequest['location'] ?? 'Not specified'); ?></dd></div><div><dt>Date Submitted</dt><dd><?php echo htmlspecialchars(date('M j, Y', strtotime($selectedRequest['created_at']))); ?></dd></div><div><dt>Priority</dt><dd><?php echo htmlspecialchars(ucfirst($selectedRequest['urgency'] ?? 'normal')); ?></dd></div><div class="maintenance-admin-wide"><dt>Description</dt><dd><?php echo nl2br(htmlspecialchars($selectedRequest['description'])); ?></dd></div><?php if (!empty($selectedRequest['preferred_date'])): ?><div><dt>Preferred Schedule</dt><dd><?php echo htmlspecialchars(date('M j, Y', strtotime($selectedRequest['preferred_date']))); ?></dd></div><?php endif; ?><?php if (!empty($selectedRequest['completion_note'])): ?><div class="maintenance-admin-wide"><dt>Completion Notes</dt><dd><?php echo nl2br(htmlspecialchars($selectedRequest['completion_note'])); ?></dd></div><?php endif; ?></dl>
                    <div class="maintenance-admin-evidence"><?php foreach (['evidence_path' => 'Submitted Photo', 'before_photo_path' => 'Before Photo', 'after_photo_path' => 'After Photo'] as $field => $label): ?><?php if (!empty($selectedRequest[$field])): ?><a href="../maintenance_evidence.php?id=<?php echo (int)$selectedRequest['id']; ?>&amp;field=<?php echo rawurlencode($field); ?>" target="_blank" rel="noopener"><img src="../maintenance_evidence.php?id=<?php echo (int)$selectedRequest['id']; ?>&amp;field=<?php echo rawurlencode($field); ?>" alt="<?php echo htmlspecialchars($label); ?>"><span><?php echo htmlspecialchars($label); ?></span></a><?php endif; ?><?php endforeach; ?></div>
                    <div class="maintenance-admin-actions">
                        <a class="maintenance-back-button" href="maintenancerequests.php?status=<?php echo rawurlencode($statusFilter); ?>&amp;search=<?php echo rawurlencode($search); ?>">Back to requests</a>
                        <?php if ($selectedRequest['status'] === 'pending' && canAccess('maintenance.review')): ?>
                            <form method="post" data-confirm="Approve maintenance request <?php echo htmlspecialchars(maintenanceRequestCode((int)$selectedRequest['id']), ENT_QUOTES, 'UTF-8'); ?>?" data-confirm-title="Approve maintenance request" data-confirm-action="Approve"><?php echo workflowCsrfField(); ?><input type="hidden" name="request_id" value="<?php echo (int)$selectedRequest['id']; ?>"><button class="maintenance-admin-button approve" name="action" value="approve">Approve</button></form>
                            <form method="post" data-confirm="Reject maintenance request <?php echo htmlspecialchars(maintenanceRequestCode((int)$selectedRequest['id']), ENT_QUOTES, 'UTF-8'); ?>? This cannot be undone." data-confirm-title="Reject maintenance request" data-confirm-action="Reject"><?php echo workflowCsrfField(); ?><input type="hidden" name="request_id" value="<?php echo (int)$selectedRequest['id']; ?>"><button class="maintenance-admin-button reject" name="action" value="reject">Reject</button></form>
                        <?php elseif ($selectedRequest['status'] === 'approved' || $selectedRequest['status'] === 'reopened'): ?>
                            <form method="post"><?php echo workflowCsrfField(); ?><input type="hidden" name="request_id" value="<?php echo (int)$selectedRequest['id']; ?>"><button class="maintenance-admin-button approve" name="action" value="<?php echo $selectedRequest['status'] === 'reopened' ? 'reopen_start' : 'start'; ?>">Start Work</button></form>
                        <?php endif; ?>
                    </div>
                    <?php if ($selectedRequest['status'] === 'in_progress'): ?>
                        <form method="post" enctype="multipart/form-data" class="maintenance-complete-form"><?php echo workflowCsrfField(); ?>
                            <input type="hidden" name="request_id" value="<?php echo (int)$selectedRequest['id']; ?>">
                            <input type="hidden" name="action" value="complete">
                            <label class="field-label">Completion Notes (optional)<textarea name="completion_note" rows="3" placeholder="Describe the work completed and verification..."></textarea></label>
                            <div class="maintenance-photo-fields"><label class="field-label">Before Photo (optional)<input type="file" name="before_photo" accept="image/jpeg,image/png,image/webp"></label><label class="field-label">After Photo (optional)<input type="file" name="after_photo" accept="image/jpeg,image/png,image/webp"></label></div>
                            <button class="maintenance-admin-button approve" type="submit">Mark Completed</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="maintenance-status-overview" aria-label="Request counts by status">
                <?php foreach ($statuses as $status): ?><a href="maintenancerequests.php?status=<?php echo rawurlencode($status); ?>" class="maintenance-status-summary"><span><?php echo htmlspecialchars(maintenanceStatusLabel($status)); ?></span><strong><?php echo $statusCounts[$status]; ?></strong></a><?php endforeach; ?>
            </section>

            <section class="unit-management-panel maintenance-admin-panel">
                <form class="unit-filters maintenance-admin-filters" method="get" action="maintenancerequests.php"><label for="requestStatus">Status</label><select id="requestStatus" name="status"><option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option><?php foreach ($statuses as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(maintenanceStatusLabel($status)); ?></option><?php endforeach; ?></select><label class="maintenance-search-label" for="requestSearch">Search</label><input type="search" id="requestSearch" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Resident, unit, request ID, issue"><button type="submit">Filter</button></form>
                <div class="unit-table-wrap"><table class="unit-table maintenance-admin-table"><thead><tr><th>Request ID</th><th>Resident</th><th>Unit</th><th>Issue</th><th>Description</th><th>Date Submitted</th><th>Priority</th><th>Status</th><th>Action</th></tr></thead><tbody>
                    <?php if (!$requests): ?><tr><td colspan="9" class="unit-empty">No maintenance requests found.</td></tr><?php else: foreach ($requests as $request): ?><tr><td><strong><?php echo maintenanceRequestCode((int)$request['id']); ?></strong></td><td><strong><?php echo htmlspecialchars($request['username']); ?></strong><small><?php echo htmlspecialchars($request['full_name']); ?></small></td><td><?php echo htmlspecialchars($request['unit_number']); ?></td><td><?php echo htmlspecialchars($request['issue_type']); ?></td><td class="request-description"><?php echo htmlspecialchars($request['description']); ?></td><td><?php echo htmlspecialchars(date('M j, Y', strtotime($request['created_at']))); ?></td><td><span class="maintenance-urgency urgency-<?php echo htmlspecialchars($request['urgency'] ?? 'normal'); ?>"><?php echo htmlspecialchars(ucfirst($request['urgency'] ?? 'normal')); ?></span></td><td><span class="maintenance-status-badge status-<?php echo htmlspecialchars($request['status']); ?>"><?php echo htmlspecialchars(maintenanceStatusLabel($request['status'])); ?></span></td><td><a class="maintenance-view-link" href="maintenancerequests.php?status=<?php echo rawurlencode($statusFilter); ?>&amp;search=<?php echo rawurlencode($search); ?>&amp;view=<?php echo (int)$request['id']; ?>">View</a></td></tr><?php endforeach; endif; ?>
                </tbody></table></div>
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
    </script>

</body>
</html>
