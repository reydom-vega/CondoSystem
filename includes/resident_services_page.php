<?php
require_once __DIR__ . '/../config.php';
if (!isLoggedIn()) redirect('../login.php');
$staffServices = $staffServices ?? false;
if ($staffServices) {
    if (!isSecurity() && !canReviewPermits()) { http_response_code(403); exit('Access denied.'); }
    $serviceKind = isSecurity() ? 'visitor' : (($_GET['kind'] ?? 'permit') === 'visitor' ? 'visitor' : 'permit');
} else {
    if (isAdmin()) redirect('../admin/admin_dashboard.php');
    requireApproval();
    requireResidentPermission($serviceKind === 'visitor' ? 'resident.visitors.register' : 'resident.permits.request');
}
$db = connectDb();
$ready = ensureResidentServicesTables($db);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    try {
        if (!$ready) throw new RuntimeException('Request storage is unavailable.');
        $action = $_POST['action'] ?? 'create';
        $id = (int)($_POST['request_id'] ?? 0);
        if ($staffServices) {
            if (!decideResidentServiceRequest($db, $id, (string)($_POST['decision'] ?? ''), trim((string)($_POST['admin_notes'] ?? '')))) throw new InvalidArgumentException('The request cannot be updated. It may already be processed or expired.');
            logAudit($_POST['decision'] === 'approved' ? 'approve' : 'reject', 'resident_service_request', $id);
        } elseif ($action === 'cancel') {
            if (!cancelResidentServiceRequest($db, (int)$_SESSION['user_id'], $id, $serviceKind)) throw new InvalidArgumentException('This request can no longer be cancelled.');
            logAudit('cancel', 'resident_service_request', $id);
        } else {
            $id = $serviceKind === 'visitor' ? registerVisitorWithParking($db, (int)$_SESSION['user_id'], $_POST) : createResidentServiceRequest($db, (int)$_SESSION['user_id'], $serviceKind, $_POST);
            logAudit('create', 'resident_service_request', $id, $serviceKind . ' registration');
        }
        setFlash('success', !$staffServices && $action === 'create' && $serviceKind === 'visitor' ? (($_POST['needs_parking'] ?? '') === '1' ? 'Visitor registered and linked parking request submitted for review.' : 'Visitor registered successfully. Security will review your request.') : 'Request saved successfully.');
        redirect($staffServices ? 'service_requests.php?kind=' . $serviceKind : ($serviceKind === 'visitor' ? 'visitors.php' : 'permits.php'));
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { error_log($e->getMessage()); $error = 'Unable to save your request. Please try again.'; }
}
$requests = $ready ? getResidentServiceRequests($db, $serviceKind, $staffServices ? null : (int)$_SESSION['user_id']) : [];
$title = $staffServices ? 'Resident Service Requests' : ($serviceKind === 'visitor' ? 'Visitor Registration' : 'Permit Requests');
$flash = getFlash();
$username = (string)($_SESSION['username'] ?? 'Resident');
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$linkedParking = [];
if ($serviceKind === 'visitor' && $ready) {
    ensureParkingTables($db);
    $parkingQuery = $db->prepare('SELECT visitor_registration_id, id, status, vehicle_plate FROM parking_requests WHERE visitor_registration_id IS NOT NULL' . ($staffServices ? '' : ' AND user_id = ?') . ' ORDER BY id DESC');
    if (!$staffServices) $parkingQuery->bind_param('i', $_SESSION['user_id']);
    $parkingQuery->execute(); $parking = $parkingQuery->get_result();
    while ($row = $parking->fetch_assoc()) {
        // Display only links to the registrations already authorized by the request query.
        $linkedParking[(int)$row['visitor_registration_id']] ??= $row;
    }
}
function serviceEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function serviceValue(string $name, string $default = ''): string { return serviceEscape(is_string($_POST[$name] ?? null) ? $_POST[$name] : $default); }
$canRequestParking = $staffServices || residentHasPermission('resident.parking.request');
$needsParking = $canRequestParking && ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['needs_parking'] ?? '') : ($_GET['needs_parking'] ?? '')) === '1';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= serviceEscape($title) ?> - Celandine Residences</title><link rel="stylesheet" href="../<?= $staffServices ? 'styles' : 'resident' ?>.css"><link rel="stylesheet" href="../services.css?v=<?= filemtime(__DIR__ . '/../services.css') ?>"></head>
<body class="dashboard-page"><div class="dash-layout">
<aside class="sidebar" id="sidebar"><a href="<?= $staffServices ? buildUrl(dashboardPathForRole()) : 'dashboard.php' ?>" class="sidebar-brand"><?php include __DIR__ . '/../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a><nav class="sidebar-nav">
<?php if ($staffServices): ?><?php renderStaffSidebarNavigation(); ?><?php else: ?>
<?php renderResidentSidebarNavigation($serviceKind === 'visitor' ? 'visitors.php' : 'permits.php'); ?>
<?php endif; ?></nav></aside><div class="sidebar-overlay" id="sidebarOverlay"></div>
<main class="dashboard-main"><header class="dash-header"><div class="dash-header-left"><button type="button" class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?= systemIcon('menu','menu-icon') ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title"><?= serviceEscape($title) ?></h1></div></div><div class="dash-header-right"><?php include __DIR__ . '/../notifications.php'; ?><div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?= serviceEscape($initials) ?></span><span class="profile-nav-name"><?= serviceEscape($username) ?></span><span class="profile-caret">&#9662;</span></button><div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?= serviceEscape($initials) ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?= serviceEscape($username) ?></span><span class="profile-dropdown-unit"><?= $staffServices ? 'Staff' : 'Unit ' . serviceEscape($_SESSION['unit_number'] ?? 'Not assigned') ?></span></div></div><?php if (!$staffServices): ?><a href="edit_profile.php" class="profile-dropdown-item"><?= systemIcon('edit','system-action-icon') ?> Edit Profile</a><?php endif; ?><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?= systemIcon('arrow-right','system-action-icon') ?> Logout</a></div></div></div></header>
<?php if ($error): ?><div class="alert error" role="alert"><?= serviceEscape($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert success" role="status"><?= serviceEscape($flash['message']) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="alert error">Request storage is unavailable.</div><?php endif; ?>
<?php if (!$staffServices && $ready): ?>
<section class="service-panel"><h2><?= $serviceKind === 'visitor' ? 'Register an expected visitor' : 'Request a permit' ?></h2><p><?= $serviceKind === 'visitor' ? ('Tell security who is visiting your unit and when.' . ($canRequestParking ? ' If your guest brings a vehicle, submit their parking request here too.' : ' Visitor parking is disabled for your account type.')) : 'Management reviews your request. An approved permit authorizes only the activity and dates shown.' ?></p>
<form method="post" class="service-form"><?= workflowCsrfField() ?>
<?php if ($serviceKind === 'visitor'): ?>
<label class="field-label" for="visitor_name">Visitor name<input id="visitor_name" name="visitor_name" maxlength="120" value="<?= serviceValue('visitor_name') ?>" placeholder="Guest's full name" autocomplete="off" required></label><label class="field-label" for="visitor_contact">Contact number (optional)<input id="visitor_contact" name="visitor_contact" maxlength="30" type="tel" value="<?= serviceValue('visitor_contact') ?>" placeholder="e.g. 0917 123 4567" autocomplete="off"></label>
<?php else: ?><label class="field-label" for="permit_type">Permit type<select id="permit_type" name="permit_type" required><?php foreach (RESIDENT_PERMIT_TYPES as $type): ?><option <?= ($_POST['permit_type'] ?? '') === $type ? 'selected' : '' ?>><?= serviceEscape($type) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label class="field-label" for="service_start_date"><?= $serviceKind === 'visitor' ? 'Visit date' : 'Start date' ?><input id="service_start_date" name="start_date" type="date" min="<?= date('Y-m-d') ?>" value="<?= serviceValue('start_date') ?>" required></label>
<?php if ($serviceKind === 'permit'): ?><label class="field-label" for="service_end_date">End date<input id="service_end_date" name="end_date" type="date" min="<?= date('Y-m-d') ?>" value="<?= serviceValue('end_date') ?>" required></label><?php endif; ?>
<label class="field-label service-wide" for="service_details">Purpose / details<textarea id="service_details" name="details" maxlength="500" rows="3" placeholder="Tell security the purpose of the visit" required><?= serviceValue('details') ?></textarea></label>
<?php if ($serviceKind === 'visitor' && $canRequestParking): ?><div class="service-wide service-parking-choice"><label class="checkbox" for="needs_parking"><input type="checkbox" id="needs_parking" name="needs_parking" value="1" aria-controls="visitorParkingFields" <?= $needsParking ? 'checked' : '' ?>> My visitor needs parking</label><p>A parking request for the visit date will be submitted with this registration. Security reviews access and parking slot availability.</p></div><fieldset id="visitorParkingFields" class="service-wide service-parking-fields" <?= $needsParking ? '' : 'hidden disabled' ?>><legend>Visitor vehicle</legend><div class="service-form"><label class="field-label" for="visitor_vehicle_plate">Plate number<input id="visitor_vehicle_plate" name="vehicle_plate" maxlength="20" value="<?= serviceValue('vehicle_plate') ?>" placeholder="e.g. ABC 1234" required></label><label class="field-label" for="visitor_vehicle_description">Vehicle (optional)<input id="visitor_vehicle_description" name="vehicle_description" maxlength="120" value="<?= serviceValue('vehicle_description') ?>" placeholder="e.g. Silver Toyota Vios"></label></div></fieldset><?php endif; ?>
<div class="service-wide service-submit"><button type="submit"><?= $serviceKind === 'visitor' ? 'Register visitor' : 'Submit for review' ?></button><?php if ($serviceKind === 'visitor' && $canRequestParking): ?><a class="service-btn service-btn-secondary" href="parking.php#visitorParking">Request parking for an existing visitor</a><?php endif; ?></div></form></section>
<?php endif; ?>
<section class="service-panel"><h2><?= $staffServices ? 'Review requests' : 'Your requests' ?></h2>
<?php if (!$requests): ?><p>No requests yet.</p><?php endif; ?>
<?php foreach ($requests as $request): ?><article class="service-request"><div class="service-request-heading"><h3>#<?= (int)$request['id'] ?> · <?= serviceEscape($request['request_kind'] === 'visitor' ? $request['visitor_name'] : $request['permit_type']) ?></h3><span class="badge"><?= serviceEscape($request['status']) ?><?= $request['end_date'] < date('Y-m-d') ? ' · expired' : '' ?></span></div>
<p><?= serviceEscape($request['start_date']) ?><?= $request['start_date'] !== $request['end_date'] ? ' to ' . serviceEscape($request['end_date']) : '' ?> · Unit <?= serviceEscape($request['unit_number']) ?><?php if ($staffServices): ?> · <?= serviceEscape($request['full_name']) ?><?php endif; ?></p>
<p><?= serviceEscape($request['details']) ?></p><?php if ($request['admin_notes']): ?><p>Review note: <?= serviceEscape($request['admin_notes']) ?></p><?php endif; ?>
<?php if ($serviceKind === 'visitor' && $canRequestParking): ?><?php $visitorParking = $linkedParking[(int)$request['id']] ?? null; ?><?php if ($visitorParking): ?><p class="service-parking-status">Parking: <strong><?= serviceEscape(ucfirst($visitorParking['status'])) ?></strong> · <?= serviceEscape($visitorParking['vehicle_plate']) ?> <a class="service-btn service-btn-secondary" href="parking.php#parking-request-<?= (int)$visitorParking['id'] ?>">View parking request</a></p><?php endif; ?><?php if (!$staffServices && (!$visitorParking || in_array($visitorParking['status'], ['cancelled','rejected'], true)) && in_array($request['status'], ['pending','approved','checked_in'], true) && $request['end_date'] >= date('Y-m-d')): ?><p><a class="service-btn service-btn-secondary" href="parking.php?visitor_id=<?= (int)$request['id'] ?>#visitorParking">Request parking for this visitor</a></p><?php endif; ?><?php endif; ?>
<?php if (in_array($request['status'], ['approved','checked_in','checked_out'], true)): ?><a class="service-btn service-btn-secondary" href="<?= serviceEscape(residentServicePassUrl($request)) ?>">View access pass</a><?php endif; ?>
<?php if ($staffServices && $request['status'] === 'pending' && $request['end_date'] >= date('Y-m-d')): ?><form method="post" class="service-actions"><?= workflowCsrfField() ?><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input name="admin_notes" maxlength="500" placeholder="Review note"><button name="decision" value="approved">Approve</button><button name="decision" value="rejected">Reject</button></form>
<?php elseif (!$staffServices && in_array($request['status'], ['pending','approved'], true)): ?><form method="post" class="service-actions"><?= workflowCsrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button type="submit" class="service-btn service-btn-danger">Cancel request</button></form><?php endif; ?>
</article><?php endforeach; ?></section></main></div>
<script src="../js/services-menu.js"></script><script src="../js/profile-menu.js"></script><script src="../js/notification-menu.js"></script></body></html>
