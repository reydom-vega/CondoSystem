<?php
require_once '../config.php';

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
            $insert = $connection->prepare("INSERT INTO maintenance_requests (user_id, issue_type, location, description, preferred_date, urgency, evidence_path, status) VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, 'pending')");
            $userId = (int)$_SESSION['user_id'];
            $imagePath = $image['path'];
            $insert->bind_param('issssss', $userId, $issueType, $location, $description, $preferredDate, $urgency, $imagePath);
            if ($insert->execute()) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Maintenance Request</title>
    <link rel="stylesheet" href="../resident.css">
</head>
<body class="dashboard-page">

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
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
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Maintenance Request</h1>
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

            <?php if ($success): ?><div class="alert success maintenance-alert">Maintenance request updated successfully.</div><?php endif; ?>
            <?php if ($errors): ?><div class="alert error maintenance-alert"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

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

            <?php if ($selectedRequest): ?>
                <section class="maintenance-detail-card">
                    <div class="maintenance-detail-heading"><div><span class="dash-subtitle">REQUEST DETAILS</span><h2>Maintenance Request #<?php echo maintenanceRequestCode((int)$selectedRequest['id']); ?></h2></div><span class="maintenance-status-badge status-<?php echo htmlspecialchars($selectedRequest['status']); ?>"><?php echo htmlspecialchars(maintenanceStatusLabel($selectedRequest['status'])); ?></span></div>
                    <dl class="maintenance-detail-grid">
                        <div><dt>Issue</dt><dd><?php echo htmlspecialchars($selectedRequest['issue_type']); ?></dd></div><div><dt>Location</dt><dd><?php echo htmlspecialchars($selectedRequest['location'] ?? 'Not specified'); ?></dd></div><div><dt>Unit</dt><dd><?php echo htmlspecialchars((string)($_SESSION['unit_number'] ?? '')); ?></dd></div><div><dt>Date Submitted</dt><dd><?php echo htmlspecialchars(date('M j, Y', strtotime($selectedRequest['created_at']))); ?></dd></div><div><dt>Priority</dt><dd><?php echo htmlspecialchars(ucfirst($selectedRequest['urgency'] ?? 'normal')); ?></dd></div><div class="maintenance-detail-wide"><dt>Description</dt><dd><?php echo nl2br(htmlspecialchars($selectedRequest['description'])); ?></dd></div>
                        <?php if (!empty($selectedRequest['completion_note'])): ?><div class="maintenance-detail-wide"><dt>Completion Notes</dt><dd><?php echo nl2br(htmlspecialchars($selectedRequest['completion_note'])); ?></dd></div><?php endif; ?>
                    </dl>
                    <div class="maintenance-evidence-grid"><?php foreach (['evidence_path' => 'Submitted Photo', 'before_photo_path' => 'Before Photo', 'after_photo_path' => 'After Photo'] as $field => $label): ?><?php if (!empty($selectedRequest[$field])): ?><a href="../maintenance_evidence.php?id=<?php echo (int)$selectedRequest['id']; ?>&amp;field=<?php echo rawurlencode($field); ?>" target="_blank" rel="noopener"><img src="../maintenance_evidence.php?id=<?php echo (int)$selectedRequest['id']; ?>&amp;field=<?php echo rawurlencode($field); ?>" alt="<?php echo htmlspecialchars($label); ?>"><span><?php echo htmlspecialchars($label); ?></span></a><?php endif; ?><?php endforeach; ?></div>
                    <div class="maintenance-detail-actions"><a class="maintenance-nav-button secondary" href="maintenance.php">Close Details</a><?php if ($selectedRequest['status'] === 'completed'): ?><form method="post"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="resident_update"><input type="hidden" name="request_id" value="<?php echo (int)$selectedRequest['id']; ?>"><button class="maintenance-action-btn confirm" name="decision" value="confirm">Confirm Completion</button><button class="maintenance-action-btn problem" name="decision" value="problem">Report Problem</button></form><?php elseif ($selectedRequest['status'] === 'pending'): ?><form method="post" data-confirm="Cancel this maintenance request?" data-confirm-title="Cancel request" data-confirm-action="Cancel"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="resident_update"><input type="hidden" name="request_id" value="<?php echo (int)$selectedRequest['id']; ?>"><button class="maintenance-action-btn problem" name="decision" value="cancel">Cancel Request</button></form><?php endif; ?></div>
                </section>
            <?php endif; ?>

            <section class="maintenance-active-card maintenance-list-card">
                <div class="maintenance-list-heading"><h2>My Maintenance Requests</h2><span><?php echo count($requests); ?> requests</span></div>
                <?php if (!$requests): ?><p class="bookings-empty">No maintenance requests yet.</p><?php else: ?><div class="maintenance-request-list"><?php foreach ($requests as $request): ?><article class="maintenance-request-item"><div class="maintenance-request-head"><div><span class="maintenance-request-code"><?php echo maintenanceRequestCode((int)$request['id']); ?></span><h3><?php echo htmlspecialchars($request['issue_type']); ?></h3></div><span class="maintenance-status-badge status-<?php echo htmlspecialchars($request['status']); ?>"><?php echo htmlspecialchars(maintenanceStatusLabel($request['status'])); ?></span></div><p><?php echo htmlspecialchars((string)($_SESSION['unit_number'] ?? '')); ?> · <?php echo htmlspecialchars(date('M j, Y', strtotime($request['created_at']))); ?> · <?php echo htmlspecialchars(ucfirst($request['urgency'] ?? 'normal')); ?> priority</p><div class="maintenance-list-actions"><a href="maintenance.php?view=<?php echo (int)$request['id']; ?>" class="maintenance-nav-button">View Details</a><?php if ($request['status'] === 'completed'): ?><span>Confirm completion or report a problem in details</span><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
            </section>
        </main>

    </div>
    <script>
        // Toggle Mobile Sidebar
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('open');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
            });
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
