<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/parking_workspace.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();
if (!residentHasPermission('resident.parking.request') && !residentHasPermission('resident.stickers.order') && !residentHasPermission('resident.vehicles.register')) {
    requireResidentPermission('resident.parking.request');
}

$userId = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$connection = connectDb();
ensureParkingTables($connection);
$parkingPolicy = getParkingPolicy($connection);

$errors = [];
$success = '';
$stickerError = '';
$stickerSuccess = '';
$parkingFlash = getFlash();
if ($parkingFlash) $success = (string)$parkingFlash['message'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'register_vehicle') {
    requireWorkflowCsrf();
    $formAction = $_POST['form_action'] ?? 'parking_request';

    if ($formAction === 'buy_sticker') {
        requireResidentPermission('resident.stickers.order');
        $stickerVehicleIds = is_array($_POST['vehicle_ids'] ?? null) ? array_values(array_unique(array_map('intval', $_POST['vehicle_ids']))) : [];
        $stickerQuantity = count($stickerVehicleIds);
        if ($stickerQuantity < 1 || $stickerQuantity > (int)$parkingPolicy['sticker_max_quantity']) {
            $stickerError = 'Select between 1 and ' . (int)$parkingPolicy['sticker_max_quantity'] . ' approved vehicles.';
        }

        $existingStickerOrder = getLatestParkingStickerOrder($userId);
        if ($stickerError === '' && $existingStickerOrder && $existingStickerOrder['claim_status'] !== 'issued' && $existingStickerOrder['status'] !== 'cancelled') {
            $stickerError = 'You already have a sticker order in progress. Check its status in Requests.';
        } elseif ($stickerError === '') {
            $billId = purchaseParkingStickers($connection, $userId, $stickerQuantity, $stickerVehicleIds);
            if ($billId) redirect('payments.php');
            $stickerError = 'Unable to create the sticker order. Check for an existing order, then try again.';
        }
    } elseif ($formAction === 'cancel_visitor_request') {
        requireResidentPermission('resident.parking.request');
        $requestId = (int)($_POST['request_id'] ?? 0);
        if (cancelVisitorParkingRequest($connection, $userId, $requestId)) {
            logAudit('cancel', 'parking_request', $requestId, 'Resident cancelled visitor parking');
            setFlash('success', 'Parking request cancelled. Your visitor registration remains active.');
            redirect('parking.php?tab=requests#parking-request-' . $requestId);
        }
        $errors[] = 'This parking request can no longer be cancelled.';
    } elseif ($formAction === 'visitor_request') {
        requireResidentPermission('resident.parking.request');
        try {
            $visitorId = filter_var($_POST['visitor_registration_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$visitorId) throw new InvalidArgumentException('Select a registered visitor. You can register a new visitor from the link below.');
            $requestId = requestLinkedVisitorParking($connection, $userId, $visitorId, $_POST);
            logAudit('create', 'parking_request', $requestId, 'Linked visitor registration #' . $visitorId);
            setFlash('success', 'Parking request submitted for your registered visitor. Security will review the visit and assign a parking slot.');
            redirect('parking.php?tab=requests#parking-request-' . $requestId);
        } catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
        catch (Throwable $e) { error_log($e->getMessage()); $errors[] = 'Unable to submit visitor parking. Please try again.'; }
    } else {
        $errors[] = 'Unknown parking action. Refresh the page and try again.';
    }
}
$myStickerRequests = residentHasPermission('resident.stickers.order') ? getParkingStickerOrdersForUser($userId) : [];
$latestStickerOrder = $myStickerRequests[0] ?? null;
ensureStickerVehicleLinks($connection);
$eligibleStickerVehicles = residentHasPermission('resident.stickers.order') ? getEligibleStickerVehicles($connection,$userId) : [];
$stickerRequestVehicles = [];
foreach ($myStickerRequests as $stickerRequest) $stickerRequestVehicles[(int)$stickerRequest['id']] = getStickerVehicles($connection,(int)$stickerRequest['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_vehicle') requireResidentPermission('resident.vehicles.register');
$parkingTabs = parkingWorkspaceTabs(true);
$parkingActiveTab = parkingWorkspaceActiveTab($parkingTabs, $parkingDefaultTab ?? 'parking');
$parkingVehiclesContent = isset($parkingTabs['vehicles']) ? parkingWorkspaceModule('resident-vehicles') : '';
$myRequests = residentHasPermission('resident.parking.request') ? getParkingRequestsForUser($userId) : [];
$activeParkingVisitors = [];
foreach ($myRequests as $request) {
    if (!empty($request['visitor_registration_id']) && in_array($request['status'], ['pending','approved'], true)) $activeParkingVisitors[(int)$request['visitor_registration_id']] = true;
}
$parkingVisitors = array_values(array_filter(getResidentServiceRequests($connection, 'visitor', $userId), static fn(array $v): bool => in_array($v['status'], ['pending','approved','checked_in'], true) && $v['end_date'] >= date('Y-m-d') && !isset($activeParkingVisitors[(int)$v['id']])));
$selectedVisitorId = (int)($_POST['visitor_registration_id'] ?? $_GET['visitor_id'] ?? 0);
$selectedVisitor = null;
foreach ($parkingVisitors as $visitor) { if ((int)$visitor['id'] === $selectedVisitorId) $selectedVisitor = $visitor; }
function parkingFormValue(string $field, string $default = ''): string { return htmlspecialchars(is_string($_POST[$field] ?? null) ? $_POST[$field] : $default, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - Parking &amp; Vehicles</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('resident.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="../services.css?v=<?php echo filemtime(__DIR__ . '/../services.css'); ?>">
<?php renderPortalUiHead(); ?>
<link rel="stylesheet" href="../assets/css/parking-workspace.css?v=<?= filemtime(__DIR__.'/../assets/css/parking-workspace.css') ?>">
<script defer src="../assets/js/parking-workspace.js?v=<?= filemtime(__DIR__.'/../assets/js/parking-workspace.js') ?>"></script>
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

        <main class="dashboard-main parking-workspace resident-parking-workspace">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu">
                        <?php echo systemIcon('menu', 'menu-icon'); ?>
                    </button>
                    <div>
                        <span class="dash-subtitle">THE CELANDINE</span>
                        <h1 class="dash-title">Parking &amp; Vehicles</h1>
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

            <?php renderParkingWorkspaceTabs($parkingTabs, $parkingActiveTab, ['requests'=>count($myRequests)+count($myStickerRequests)]); ?>
            <?php if ($success): ?><div class="alert success"><strong>Submitted!</strong> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($stickerError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($stickerError); ?></div><?php endif; ?>
            <?php if ($stickerSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars($stickerSuccess); ?></div><?php endif; ?>

            <?php if (isset($parkingTabs['parking'])): ?><section <?= parkingWorkspacePanelAttributes('parking', $parkingActiveTab) ?>>
            <?php include __DIR__ . '/../includes/resident_sticker_panel.php'; ?>

            <?php if (residentHasPermission('resident.parking.request')): ?>
            <section class="payment-methods-card parking-request-card" id="visitorParking">
                <h3 class="section-title">Visitor Parking</h3>
                <p class="amenity-desc">Choose your registered visitor and add their vehicle. The parking period must include their visit date and can cover up to <?php echo (int)$parkingPolicy['visitor_max_days']; ?> days, including the start date. A parking pass requires an approved visitor registration and an assigned slot.</p>
                <?php if (residentHasPermission('resident.visitors.register')): ?><div class="service-button-row"><a class="service-btn" href="visitors.php?needs_parking=1">Register visitor with parking</a><a class="service-btn service-btn-secondary" href="visitors.php">Manage visitor registrations</a></div><?php endif; ?>
                <?php if (!$parkingVisitors): ?><div class="service-empty-state"><p>No eligible visitors need a parking request. Register a visitor first, or check your existing requests in the Requests tab.</p><?php if (residentHasPermission('resident.visitors.register')): ?><a class="service-btn service-btn-secondary" href="visitors.php?needs_parking=1">Register visitor &amp; request parking</a><?php endif; ?></div><?php else: ?>
                <form method="POST" action="parking.php#visitorParking" class="visitor-parking-form"><?php echo workflowCsrfField(); ?>
                    <input type="hidden" name="form_action" value="visitor_request">
                    <div class="parking-field service-wide"><label class="field-label" for="visitor_registration_id">Registered visitor</label><select class="form-date" id="visitor_registration_id" name="visitor_registration_id" required><option value="">Select a visitor</option><?php foreach ($parkingVisitors as $visitor): ?><option value="<?php echo (int)$visitor['id']; ?>" data-visit-date="<?php echo htmlspecialchars($visitor['start_date']); ?>" <?php echo (int)$visitor['id'] === $selectedVisitorId ? 'selected' : ''; ?>><?php echo htmlspecialchars($visitor['visitor_name'] . ' · ' . date('M j, Y', strtotime($visitor['start_date'])) . ' · ' . ucfirst($visitor['status'])); ?></option><?php endforeach; ?></select></div>

                    <div class="parking-field"><label class="field-label" for="vehicle_plate">Plate Number</label>
                    <input class="form-date" type="text" id="vehicle_plate" name="vehicle_plate" placeholder="e.g. ABC 1234" maxlength="20" value="<?php echo parkingFormValue('vehicle_plate'); ?>" required>
                    </div>

                    <div class="parking-field"><label class="field-label" for="vehicle_description">Vehicle (optional)</label>
                    <input class="form-date" type="text" id="vehicle_description" name="vehicle_description" placeholder="e.g. Silver Toyota Vios" maxlength="120" value="<?php echo parkingFormValue('vehicle_description'); ?>">
                    </div>

                    <div class="parking-field"><label class="field-label" for="start_date">Start Date</label>
                    <input class="form-date" type="date" id="start_date" name="start_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo parkingFormValue('start_date', $selectedVisitor['start_date'] ?? ''); ?>" required>
                    </div>

                    <div class="parking-field"><label class="field-label" for="end_date">End date</label>
                    <input class="form-date" type="date" id="end_date" name="end_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo parkingFormValue('end_date', $selectedVisitor['end_date'] ?? ''); ?>" required>
                    </div>

                    <button type="submit" class="service-btn service-wide">Submit parking request</button>
                </form>
                <?php endif; ?>
            </section>

            <?php endif; ?>
            </section><?php endif; ?>
            <?php if (isset($parkingTabs['vehicles'])): ?><section <?= parkingWorkspacePanelAttributes('vehicles', $parkingActiveTab) ?>><?= $parkingVehiclesContent ?></section><?php endif; ?>
            <?php if (isset($parkingTabs['requests'])): ?><section <?= parkingWorkspacePanelAttributes('requests', $parkingActiveTab) ?>><?php include __DIR__.'/../includes/resident_parking_requests.php'; ?></section><?php endif; ?>
        </main>

    </div>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (menuToggle) {
            /* Navigation is handled by the shared UI module. */
        }
        if (overlay) {
            /* Navigation is handled by the shared UI module. */
        }
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', (e) => { e.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
            document.addEventListener('click', (e) => { if (!profileMenu.contains(e.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
        }

        // Keep the date picker consistent with the configured inclusive duration.
        const visitorSelect = document.getElementById('visitor_registration_id');
        const endDateField = document.getElementById('end_date');
        const startDateField = document.getElementById('start_date');
        function syncEndDateMax() {
            if (!startDateField || !endDateField || !startDateField.value) return;
            const max = new Date(startDateField.value + 'T00:00:00Z');
            max.setUTCDate(max.getUTCDate() + <?php echo (int)$parkingPolicy['visitor_max_days'] - 1; ?>);
            endDateField.min = startDateField.value;
            endDateField.max = max.toISOString().slice(0, 10);
            if (endDateField.value < endDateField.min || endDateField.value > endDateField.max) endDateField.value = startDateField.value;
        }
        visitorSelect?.addEventListener('change', () => {
            const visitDate = visitorSelect.selectedOptions[0]?.dataset.visitDate;
            if (visitDate && startDateField && endDateField) { startDateField.value = visitDate; endDateField.value = visitDate; syncEndDateMax(); }
        });
        syncEndDateMax();
        if (startDateField && endDateField) {
            startDateField.addEventListener('change', syncEndDateMax);
        }
        const stickerOrderTotal = document.getElementById('stickerOrderTotal');
        const stickerOrderForm = document.getElementById('stickerOrderForm');
        if (stickerOrderTotal && stickerOrderForm) {
            const boxes = [...stickerOrderForm.querySelectorAll('input[name="vehicle_ids[]"]')];
            function syncStickerSelection() {
                const quantity = boxes.filter(box => box.checked).length;
                const allowed = quantity > 0 && quantity <= Number(stickerOrderTotal.dataset.maxQuantity);
                stickerOrderTotal.textContent = (Number(stickerOrderTotal.dataset.unitPrice) * quantity).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
                document.getElementById('submitStickerOrder').disabled = !allowed;
            }
            boxes.forEach(box => box.addEventListener('change', syncStickerSelection));
            syncStickerSelection();
        }
    </script>
</body>
</html>
