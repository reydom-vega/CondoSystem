<?php
require_once '../config.php';
require_once '../includes/maintenance_workspace.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();
requireResidentPermission('resident.maintenance.request');

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$errors = [];
$success = false;
$connection = connectDb();
$tableReady = ensureMaintenanceTable($connection);
$issueType = '';
$location = '';
$description = '';
$preferredDate = '';
$urgency = 'normal';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    if (!$tableReady) {
        $errors[] = 'Maintenance request storage is unavailable.';
    } elseif (($_POST['action'] ?? '') === 'resident_update') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? '');
        $allowedDecisions = ['confirm' => ['completed', 'closed'], 'problem' => ['completed', 'reopened'], 'cancel' => ['pending', 'cancelled']];
        if ($requestId <= 0 || !isset($allowedDecisions[$decision])) {
            $errors[] = 'Invalid maintenance request action.';
        } else {
            [$expectedStatus, $nextStatus] = $allowedDecisions[$decision];
            $update = $connection->prepare('UPDATE maintenance_requests SET status = ? WHERE id = ? AND user_id = ? AND status = ?');
            $userId = (int)$_SESSION['user_id'];
            $update->bind_param('siis', $nextStatus, $requestId, $userId, $expectedStatus);
            if ($update->execute() && $update->affected_rows === 1) {
                $success = true;
                logAudit('update', 'maintenance_request', $requestId, 'Resident changed maintenance request status to ' . $nextStatus);
            } else {
                $errors[] = 'This request can no longer be updated.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'submit_request') {
        $issueType = trim((string)($_POST['issue_type'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $preferredDate = trim((string)($_POST['preferred_date'] ?? ''));
        $urgency = (string)($_POST['urgency'] ?? 'normal');
        $issueTypes = ['Plumbing', 'Electrical', 'Air Conditioning', 'Water Leak', 'Door / Lock', 'Appliance', 'Other'];
        $locations = ['Kitchen', 'Bathroom', 'Bedroom', 'Living Room', 'Balcony', 'Other'];

        if (!in_array($issueType, $issueTypes, true) || !in_array($location, $locations, true) || !in_array($urgency, ['low', 'normal', 'urgent'], true) || $description === '') {
            $errors[] = 'Choose a valid issue, location, and urgency, and describe the problem.';
        }
        if ($preferredDate !== '' && (!workflowDate($preferredDate) || $preferredDate < date('Y-m-d'))) {
            $errors[] = 'Choose a valid preferred schedule date from today onward.';
        }
        if (strlen($description) > 5000) $errors[] = 'Keep the description to 5,000 characters or fewer.';
        $image = storeMaintenanceImage($_FILES['evidence'] ?? []);
        if ($image['error'] !== '') {
            $errors[] = $image['error'];
        }

        if (!$errors) {
            $insert = $connection->prepare("INSERT INTO maintenance_requests (user_id, issue_type, location, description, preferred_date, urgency, evidence_path, status,unit_number_snapshot) SELECT ?, ?, ?, ?, NULLIF(?, ''), ?, ?, 'pending',unit_number FROM users WHERE id=? AND role='resident' AND is_active=1 AND archived_at IS NULL AND is_verified=1 AND status='approved'");
            $userId = (int)$_SESSION['user_id'];
            $imagePath = $image['path'];
            $insert->bind_param('issssssi', $userId, $issueType, $location, $description, $preferredDate, $urgency, $imagePath, $userId);
            if ($insert->execute() && $insert->affected_rows===1) {
                $success = true;
                trackEvent('maintenance_request', $issueType, $userId);
                $issueType = $location = $description = $preferredDate = '';
                $urgency = 'normal';
            } else {
                if ($imagePath !== null) {
                    @unlink(dirname(__DIR__) . '/private_uploads/maintenance_evidence/' . $imagePath);
                }
                $errors[] = 'Unable to save your request. Please try again.';
            }
        } elseif (!empty($image['path'])) {
            @unlink(dirname(__DIR__) . '/private_uploads/maintenance_evidence/' . $image['path']);
        }
    }
}

$requests = [];
$selectedRequest = null;
if ($tableReady) {
    $requestQuery = $connection->prepare('SELECT * FROM maintenance_requests WHERE user_id = ? ORDER BY created_at DESC');
    $userId = (int)$_SESSION['user_id'];
    $requestQuery->bind_param('i', $userId);
    $requestQuery->execute();
    $requests = $requestQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $selectedId = (int)($_GET['view'] ?? 0);
    foreach ($requests as $request) {
        if ((int)$request['id'] === $selectedId) {
            $selectedRequest = $request;
            break;
        }
    }
}
$tabs = ['form' => 'Request Form', 'requests' => 'Requests', 'history' => 'History'];
$requestGroups = ['requests' => [], 'history' => []];
foreach ($requests as $request) $requestGroups[maintenanceWorkspaceIsHistory($request) ? 'history' : 'requests'][] = $request;
$selected = $selectedRequest;
$defaultTab = $_SERVER['REQUEST_METHOD'] === 'POST' && $success ? 'requests' : 'form';
$activeTab = maintenanceWorkspaceActiveTab($tabs, $selected, $defaultTab);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_request') $activeTab = $success ? 'requests' : 'form';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - Maintenance Request</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('resident.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('assets/css/maintenance-workspace.css'), ENT_QUOTES, 'UTF-8') ?>">
    <script src="../assets/js/maintenance-workspace.js" defer></script>
</head>
<body class="portal-ui dashboard-page">

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
            </a>

            <nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu">
                        <?php echo systemIcon('menu', 'menu-icon'); ?>
                    </button>
                    <div>
                        <span class="dash-subtitle">THE CELANDINE</span>
                        <h1 class="dash-title">Maintenance Request</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <?= profileAvatar('profile-avatar') ?>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <?= profileAvatar('profile-dropdown-avatar') ?>
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

            <?php if ($success): ?><div class="alert success maintenance-alert">Maintenance request updated successfully.</div><?php endif; ?>
            <?php if ($errors): ?><div class="alert error maintenance-alert"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <div class="maintenance-workspace">
            <?php renderMaintenanceWorkspaceTabs($tabs, $activeTab, ['requests' => count($requestGroups['requests']), 'history' => count($requestGroups['history'])]); ?>
            <section <?php echo maintenanceWorkspacePanelAttributes('form', $activeTab); ?>>
            <section class="maintenance-form-card resident-maintenance-form">
                <h2 class="section-title">Submit New Request</h2>
                <form method="post" action="maintenance.php" enctype="multipart/form-data" class="maintenance-submit-form"><?php echo workflowCsrfField(); ?>
                    <input type="hidden" name="action" value="submit_request">
                    <div class="maintenance-form-grid">
                        <div class="form-group"><label class="field-label" for="issue_type">Issue / Category</label><select id="issue_type" name="issue_type" class="form-select" required><option value="">Select an issue</option><?php foreach (['Plumbing', 'Electrical', 'Air Conditioning', 'Water Leak', 'Door / Lock', 'Appliance', 'Other'] as $option): ?><option value="<?php echo htmlspecialchars($option); ?>" <?php echo $issueType === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label class="field-label" for="location">Location</label><select id="location" name="location" class="form-select" required><option value="">Select a location</option><?php foreach (['Kitchen', 'Bathroom', 'Bedroom', 'Living Room', 'Balcony', 'Other'] as $option): ?><option value="<?php echo htmlspecialchars($option); ?>" <?php echo $location === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label class="field-label" for="urgency">Urgency</label><select id="urgency" name="urgency" class="form-select"><option value="low" <?php echo $urgency === 'low' ? 'selected' : ''; ?>>Low</option><option value="normal" <?php echo $urgency === 'normal' ? 'selected' : ''; ?>>Normal</option><option value="urgent" <?php echo $urgency === 'urgent' ? 'selected' : ''; ?>>Urgent</option></select></div>
                        <div class="form-group"><label class="field-label" for="preferred_date">Preferred Schedule (optional)</label><input type="date" id="preferred_date" name="preferred_date" class="form-date" value="<?php echo htmlspecialchars($preferredDate); ?>"></div>
                        <div class="form-group maintenance-form-wide"><label class="field-label" for="description">Description</label><textarea id="description" name="description" class="form-textarea" rows="4" required placeholder="Describe the issue in detail..."><?php echo htmlspecialchars($description); ?></textarea></div>
                        <div class="form-group maintenance-form-wide"><label class="field-label" for="evidence">Photo / Evidence (optional, JPG, PNG, WebP; max 5 MB)</label><input type="file" id="evidence" name="evidence" class="maintenance-file-input" accept="image/jpeg,image/png,image/webp"></div>
                    </div>
                    <button type="submit" class="maintenance-submit-btn">Submit Maintenance Request</button>
                </form>
            </section>

            </section>
            <?php foreach ($requestGroups as $tabKey => $tabRequests): ?>
                <section <?php echo maintenanceWorkspacePanelAttributes($tabKey, $activeTab); ?>>
                    <p class="maintenance-tab-description"><?php echo $tabKey === 'history' ? 'Successful, rejected, and cancelled requests.' : 'Track your requests. Completed work remains here until you confirm completion or report a problem.'; ?></p>
                    <?php
                    $requests = $tabRequests;
                    $selectedRequest = $selected && (maintenanceWorkspaceIsHistory($selected) ? 'history' : 'requests') === $tabKey ? $selected : null;
                    require '../includes/resident_maintenance_records.php';
                    ?>
                </section>
            <?php endforeach; ?>
            </div>
        </main>

    </div>
    <script>
        // Toggle Mobile Sidebar
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (menuToggle) {
            /* Navigation is handled by the shared UI module. */
        }

        if (overlay) {
            /* Navigation is handled by the shared UI module. */
        }

        // Profile Dropdown Menu Toggle
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');

        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = profileMenu.classList.toggle('open');
                profileToggle.setAttribute('aria-expanded', isOpen);
            });

            document.addEventListener('click', (e) => {
                if (!profileMenu.contains(e.target)) {
                    profileMenu.classList.remove('open');
                    profileToggle.setAttribute('aria-expanded', 'false');
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    profileMenu.classList.remove('open');
                    profileToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    </script>
</body>
</html>
