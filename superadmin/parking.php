<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isAdmin()) {
    redirect('../resident/dashboard.php');
}
if (!isSecurity() && !canReviewPermits()) {
    redirect('../admin/admin_dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
ensureParkingTables($connection);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'decide_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $adminNotes = trim($_POST['admin_notes'] ?? '');

        if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            $errors[] = 'Invalid request decision.';
        } elseif (decideParkingRequest($requestId, $decision, (int)($_POST['slot_id'] ?? 0) ?: null, $adminNotes)) {
            $success = 'Request ' . $decision . '.';
        } else {
            $errors[] = 'Could not update that request. Approval requires an available slot of the correct type with no overlapping reservation.';
        }
    } elseif ($action === 'issue_sticker') {
        $stickerOrderId = (int)($_POST['sticker_order_id'] ?? 0);
        $vehicleIds = is_array($_POST['vehicle_ids'] ?? null) ? $_POST['vehicle_ids'] : [];
        if ($stickerOrderId > 0 && markParkingStickerIssued($stickerOrderId, (int)$_SESSION['user_id'], $vehicleIds)) {
            logAudit('issue', 'parking_sticker', $stickerOrderId, 'Physical parking sticker handed to resident after verified payment');
            $success = 'Stickers issued and resident parking slots assigned from inventory.';
        } else {
            $errors[] = 'Issuance failed. Verify payment, approved vehicle links, and enough available resident slots in Parking Slot Inventory. No partial issuance or slot assignment was saved.';
        }
    } elseif ($action === 'reassign_request_slot') {
        if (reassignParkingRequestSlot((int)($_POST['request_id'] ?? 0), (int)($_POST['slot_id'] ?? 0))) {
            $success = 'Designated parking slot updated.';
        } else {
            $errors[] = 'Could not change the slot. Choose an available slot of the correct type with no conflicting reservation.';
        }
    } elseif ($action === 'reassign_vehicle_slot') {
        if (reassignStickerVehicleSlot((int)($_POST['vehicle_id'] ?? 0), (int)($_POST['slot_id'] ?? 0))) {
            $success = 'Resident vehicle parking slot updated.';
        } else {
            $errors[] = 'Could not change the resident slot. Verify the issued sticker and choose an available resident slot without reservations.';
        }
    }
}

$requests = getAllParkingRequests($connection);
$pendingRequests = array_values(array_filter($requests, static fn (array $r): bool => $r['status'] === 'pending'));
$canSeeStickerClaims = canAccess('stickers.issue') || canAccess('billing.manage');
$stickerClaims = $canSeeStickerClaims ? getParkingStickerClaims($connection) : [];
$parkingSlots = getParkingSlots($connection);
$availableSlots = 0;
$occupiedSlots = 0;
$maintenanceSlots = 0;

foreach ($parkingSlots as $slot) {
    if (($slot['status'] ?? '') === 'available') {
        $availableSlots++;
    } elseif (($slot['status'] ?? '') === 'occupied') {
        $occupiedSlots++;
    } elseif (($slot['status'] ?? '') === 'maintenance') {
        $maintenanceSlots++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime(__DIR__ . '/../styles.css'); ?>">
<link rel="stylesheet" href="../services.css?v=<?php echo filemtime(__DIR__ . '/../services.css'); ?>"><?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Parking</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>
            <?php if (isSuperAdmin()): ?><p><a class="service-btn service-btn-secondary" href="parking_configuration.php">Parking configuration</a> · <a class="service-btn service-btn-secondary" href="parkinginventory.php">Parking inventory</a></p><?php endif; ?>

            <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <section class="unit-page-head">
                <div><h2>Parking Requests</h2><p>Slots are assigned from parking inventory when passes are approved or stickers are issued. You can change the designated slot below.</p></div>
                <div class="unit-summary">
                    <span><?php echo count($pendingRequests); ?> Pending Requests</span>
                    <a class="service-btn service-btn-secondary" href="registeredvehicles.php">Registered Vehicles</a>
                    <a class="service-btn service-btn-secondary" href="parkinginventory.php">Parking Slot Inventory</a>
                </div>
            </section>

            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('!', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo count($pendingRequests); ?></strong><span>Pending Requests</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('✅', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $availableSlots; ?></strong><span>Available Slots</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🚗', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $occupiedSlots; ?></strong><span>Occupied Slots</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🛠️', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo $maintenanceSlots; ?></strong><span>Maintenance Slots</span></div>
            </section>

            <div class="parking-request-columns">
            <section class="unit-management-panel parking-panel parking-requests-panel">
                <h3 class="section-title">Parking Requests</h3>
                <?php if (empty($requests)): ?>
                    <p class="bookings-empty">No parking requests yet.</p>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <div class="parking-request-row" id="parking-request-<?php echo (int)$req['id']; ?>">
                            <div class="parking-request-head">
                                <div>
                                    <strong><?php echo htmlspecialchars($req['full_name']); ?></strong>
                                    <?php if (!empty($req['visitor_registration_id'])): ?><p>Visitor: <?php echo htmlspecialchars($req['visitor_name'] ?? 'Registration unavailable'); ?> · <?php echo htmlspecialchars(str_replace('_', ' ', $req['visitor_status'] ?? 'unavailable')); ?> <a class="service-btn service-btn-secondary" href="service_requests.php?kind=visitor">Review visitor registration</a></p><?php endif; ?>
                                    <small> · Unit <?php echo htmlspecialchars($req['unit_number']); ?> · <?php echo $req['request_type'] === 'resident_assignment' ? 'Resident slot request' : 'Visitor parking'; ?></small><br>
                                    <span>Plate: <strong><?php echo htmlspecialchars($req['vehicle_plate']); ?></strong><?php if (!empty($req['vehicle_description'])): ?> — <?php echo htmlspecialchars($req['vehicle_description']); ?><?php endif; ?></span><br>
                                    <span><?php echo htmlspecialchars(date('M j, Y', strtotime($req['start_date']))); ?><?php if ($req['end_date']): ?> to <?php echo htmlspecialchars(date('M j, Y', strtotime($req['end_date']))); ?><?php endif; ?></span>
                                    <?php if ($req['status'] !== 'pending'): ?>
                                        <br><small>Slot: <?php echo htmlspecialchars($req['slot_code'] ?? '—'); ?><?php if (!empty($req['admin_notes'])): ?> · Note: <?php echo htmlspecialchars($req['admin_notes']); ?><?php endif; ?></small>
                                    <?php endif; ?>
                                </div>
                                <span class="unit-status <?php echo htmlspecialchars($req['status']); ?>"><?php echo htmlspecialchars(ucfirst($req['status'])); ?></span>
                            </div>
                            <?php if ($req['status'] === 'pending'): ?>
                                <form method="post" class="parking-request-decide"><?php echo workflowCsrfField(); ?>
                                    <input type="hidden" name="action" value="decide_request">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                    <select name="slot_id" aria-label="Assign parking slot">
                                        <option value="">Automatically assign from inventory</option>
                                        <?php foreach ($parkingSlots as $slot): ?>
                                            <?php if ($slot['status'] === 'available' && $slot['slot_type'] === ($req['request_type'] === 'visitor' ? 'visitor' : 'resident')): ?>
                                                <option value="<?php echo (int)$slot['id']; ?>"><?php echo htmlspecialchars($slot['slot_code'] . ' · ' . $slot['level']); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="admin_notes" placeholder="Note (optional)">
                                    <button type="submit" name="decision" value="approved" class="service-btn service-btn-approve" <?php echo !empty($req['visitor_registration_id']) && !in_array($req['visitor_status'], ['approved','checked_in'], true) ? 'disabled title="Approve the visitor registration first"' : ''; ?>>Approve</button>
                                    <button type="submit" name="decision" value="rejected" class="service-btn service-btn-danger">Reject</button>
                                </form>
                            <?php elseif ($req['status'] === 'approved' && ($req['request_type'] === 'resident_assignment' || ($req['end_date'] ?: $req['start_date']) >= date('Y-m-d'))): ?>
                                <form method="post" class="parking-request-decide"><?php echo workflowCsrfField(); ?>
                                    <input type="hidden" name="action" value="reassign_request_slot">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                    <select name="slot_id" required aria-label="Change designated parking slot">
                                        <option value="">Change designated slot</option>
                                        <?php foreach ($parkingSlots as $slot): ?>
                                            <?php if ($slot['status'] === 'available' && empty($slot['assigned_user_id']) && $slot['slot_type'] === ($req['request_type'] === 'visitor' ? 'visitor' : 'resident')): ?>
                                                <option value="<?php echo (int)$slot['id']; ?>"><?php echo htmlspecialchars($slot['slot_code'] . ' - ' . $slot['level']); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="service-btn">Save slot</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <?php if ($canSeeStickerClaims): ?><section class="unit-management-panel parking-panel sticker-requests-panel">
                <h3 class="section-title">Resident Parking Sticker Requests</h3>
                <?php if (empty($stickerClaims)): ?>
                    <p class="bookings-empty">No sticker requests yet.</p>
                <?php else: ?>
                    <?php foreach ($stickerClaims as $claim): ?>
                        <div class="parking-request-row">
                            <div class="parking-request-head">
                                <div>
                                    <strong><?php echo htmlspecialchars($claim['full_name']); ?></strong>
                                    <small> · Unit <?php echo htmlspecialchars($claim['unit_number'] ?? '—'); ?> · @<?php echo htmlspecialchars($claim['username']); ?></small><br>
                                    <span><?php echo (int)$claim['quantity']; ?> sticker(s) · ₱<?php echo number_format((float)$claim['amount'], 2); ?></span><br>
                                    <?php foreach ($claim['vehicles'] as $vehicle): ?>
                                        <p>Vehicle: <a class="service-btn service-btn-secondary" href="registeredvehicles.php#vehicle-<?php echo (int)$vehicle['id']; ?>"><?php echo htmlspecialchars($vehicle['plate_number']); ?></a>
                                            <?php if ($vehicle['sticker_number']): ?> - Sticker <strong><?php echo htmlspecialchars($vehicle['sticker_number']); ?></strong><?php endif; ?>
                                            - Slot: <strong><?php echo htmlspecialchars($vehicle['slot_code'] ?? 'Not assigned'); ?></strong>
                                        </p>
                                        <?php if ($claim['claim_status'] === 'issued' && $claim['payment_status'] === 'paid' && canAccess('stickers.issue')): ?>
                                            <form method="post" class="parking-request-decide"><?php echo workflowCsrfField(); ?>
                                                <input type="hidden" name="action" value="reassign_vehicle_slot">
                                                <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle['id']; ?>">
                                                <select name="slot_id" required aria-label="Change resident vehicle parking slot">
                                                    <option value="">Choose a resident slot</option>
                                                    <?php foreach ($parkingSlots as $slot): ?>
                                                        <?php if ($slot['slot_type'] === 'resident' && $slot['status'] === 'available' && empty($slot['assigned_user_id'])): ?>
                                                            <option value="<?php echo (int)$slot['id']; ?>"><?php echo htmlspecialchars($slot['slot_code'] . ' - ' . $slot['level']); ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="service-btn">Save slot</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!$claim['vehicles']): ?><p>Historical order: vehicle link required. <a class="service-btn service-btn-secondary" href="registeredvehicles.php">Review registered vehicles</a></p><?php endif; ?>
                                    <?php $paymentLabel = !empty($claim['paymongo_payment_id']) || strpos((string)$claim['gateway_status'], 'payment.paid') !== false ? 'Verified by PayMongo' : 'Confirmed'; ?>
                                    <small>Payment: <?php echo $claim['payment_status'] === 'paid' ? $paymentLabel : htmlspecialchars(ucfirst($claim['payment_status'])); ?><?php if ($claim['payment_status'] === 'paid' && $claim['paid_at']): ?> · <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($claim['paid_at']))); ?><?php endif; ?></small>
                                    <?php if ($claim['proof_file']): ?>
                                        <br><a class="service-btn service-btn-secondary" href="../parking_sticker_proof.php?order_id=<?php echo (int)$claim['id']; ?>" target="_blank" rel="noopener">View receipt / proof</a>
                                        <small> · Submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($claim['proof_submitted_at']))); ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php
                                    $stickerStatus = $claim['claim_status'] === 'issued'
                                        ? 'Issued'
                                        : ($claim['payment_status'] === 'paid' ? 'Paid - Ready to Issue' : 'Awaiting Payment');
                                    $stickerStatusClass = $claim['claim_status'] === 'issued'
                                        ? 'approved'
                                        : 'pending';
                                ?>
                                <span class="unit-status <?php echo $stickerStatusClass; ?>"><?php echo htmlspecialchars($stickerStatus); ?></span>
                            </div>
                            <?php if (canReviewPermits() && ($claim['claim_status'] !== 'issued' || !$claim['vehicles']) && $claim['payment_status'] === 'paid'): ?>
                                <form method="post" class="parking-request-decide" data-confirm="Confirm that this parking sticker has been handed to the resident?" data-confirm-title="Issue parking sticker" data-confirm-action="Issue sticker"><?php echo workflowCsrfField(); ?>
                                    <input type="hidden" name="action" value="issue_sticker">
                                    <input type="hidden" name="sticker_order_id" value="<?php echo (int)$claim['id']; ?>">
                                    <?php if (!$claim['vehicles']): ?><label class="field-label">Select <?php echo (int)$claim['quantity']; ?> approved vehicle(s)<select name="vehicle_ids[]" multiple required aria-label="Vehicles for historical sticker order"><?php foreach ($claim['eligible_vehicles'] as $vehicle): ?><option value="<?php echo (int)$vehicle['id']; ?>"><?php echo htmlspecialchars($vehicle['plate_number'].' · '.$vehicle['make'].' '.$vehicle['model']); ?></option><?php endforeach; ?></select></label><?php endif; ?>
                                    <button type="submit" class="service-btn service-btn-approve" <?php echo !$claim['vehicles'] && count($claim['eligible_vehicles']) < (int)$claim['quantity'] ? 'disabled' : ''; ?>><?php echo $claim['claim_status'] === 'issued' ? 'Link issued stickers to vehicles' : 'Issue physical stickers'; ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section><?php endif; ?>
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

</html>
