<?php
require_once '../config.php';
require_once '../includes/maintenance_workspace.php';

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
                    logAudit('completed', 'maintenance_request', $requestId, 'Maintenance request completed with notes');
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
                logAudit($action === 'reject' ? 'reject' : ($action === 'approve' ? 'approve' : $nextStatus), 'maintenance_request', $requestId, 'Maintenance request status set to ' . $nextStatus);
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
    $sql = "SELECT m.*, u.full_name, u.username, u.unit_number FROM maintenance_requests m INNER JOIN users u ON u.id = m.user_id WHERE (? = '' OR u.full_name LIKE ? OR u.username LIKE ? OR u.unit_number LIKE ? OR m.issue_type LIKE ? OR CAST(m.id AS CHAR) LIKE ? OR CONCAT('MR-', LPAD(m.id, 5, '0')) LIKE ?) ORDER BY m.created_at DESC";
    $query = $connection->prepare($sql);
    $needle = '%' . $search . '%';
    $requestIdSearch = preg_match('/\AMR-(\d+)\z/i', $search, $idMatch) ? ltrim($idMatch[1], '0') : $search;
    $requestIdNeedle = '%' . ($requestIdSearch === '' ? '0' : $requestIdSearch) . '%';
    $requestCodeNeedle = '%' . strtoupper($search) . '%';
    $query->bind_param('sssssss', $search, $needle, $needle, $needle, $needle, $requestIdNeedle, $requestCodeNeedle);
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
$tabs = ['requests' => 'Requests', 'history' => 'History'];
$requestGroups = ['requests' => [], 'history' => []];
foreach ($requests as $request) $requestGroups[maintenanceWorkspaceIsHistory($request) ? 'history' : 'requests'][] = $request;
$selected = $selectedRequest;
$defaultTab = in_array($statusFilter, ['closed', 'rejected', 'cancelled'], true) ? 'history' : 'requests';
$activeTab = maintenanceWorkspaceActiveTab($tabs, $selected, $defaultTab);
$historyStatuses = ['closed', 'rejected', 'cancelled'];
$activeStatuses = array_values(array_diff($statuses, $historyStatuses));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - Maintenance Requests</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('assets/css/maintenance-workspace.css'), ENT_QUOTES, 'UTF-8') ?>">
    <script src="../assets/js/maintenance-workspace.js" defer></script>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
            <header class="dash-header"><div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">THE CELANDINE</span><h1 class="dash-title">Maintenance Requests</h1></div></div><div class="dash-header-right"><?php include '../notifications.php'; ?><div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><?= profileAvatar('profile-avatar') ?><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button><div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><?= profileAvatar('profile-dropdown-avatar') ?><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit"><?php echo htmlspecialchars($roleLabel); ?></span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div></div></div></header>
            <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <section class="unit-page-head"><div><h2>Maintenance Requests</h2><p>Review, approve, and track resident maintenance requests.</p></div></section>

            <div class="maintenance-workspace">
            <?php renderMaintenanceWorkspaceTabs($tabs, $activeTab, [
                'requests' => array_sum(array_intersect_key($statusCounts, array_flip($activeStatuses))),
                'history' => array_sum(array_intersect_key($statusCounts, array_flip($historyStatuses))),
            ]); ?>
            <?php foreach ($requestGroups as $tabKey => $tabRequests): ?>
                <section <?php echo maintenanceWorkspacePanelAttributes($tabKey, $activeTab); ?>>
                    <p class="maintenance-tab-description"><?php echo $tabKey === 'history' ? 'Successful, rejected, and cancelled requests.' : 'Review requests, track work, and follow up on completed work awaiting resident confirmation.'; ?></p>
                    <?php
                    $panelStatuses = $tabKey === 'history' ? $historyStatuses : $activeStatuses;
                    $panelStatus = in_array($statusFilter, $panelStatuses, true) ? $statusFilter : 'all';
                    $requests = array_values(array_filter($tabRequests, static fn(array $request): bool => $panelStatus === 'all' || $request['status'] === $panelStatus));
                    $selectedRequest = $selected && (maintenanceWorkspaceIsHistory($selected) ? 'history' : 'requests') === $tabKey ? $selected : null;
                    require '../includes/staff_maintenance_records.php';
                    ?>
                </section>
            <?php endforeach; ?>
            </div>
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
