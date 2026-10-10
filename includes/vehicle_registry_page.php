<?php
require_once __DIR__ . '/../config.php';
if (!isLoggedIn()) redirect('../login.php');
$residentVehiclePage=$residentVehiclePage??false;
$isResident=($_SESSION['role']??'')==='resident';
if ($isResident && !$residentVehiclePage) redirect('../resident/vehicles.php');
if ($residentVehiclePage && !$isResident) redirect('../superadmin/registeredvehicles.php');
if (!$isResident && !canReviewPermits() && !isSecurity()) { http_response_code(403); exit('Access denied.'); }
if ($isResident) {
    requireApproval();
    requireResidentPermission('resident.vehicles.register');
}
$db=connectDb(); ensureVehiclesTable($db); ensureStickerVehicleLinks($db);
$userId=(int)$_SESSION['user_id']; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireWorkflowCsrf();
    $uploaded=[];
    try {
        if ($isResident && ($_POST['action']??'')==='register_vehicle') {
            $orCr=storeVehicleDocument($_FILES['or_cr']??[],'or_cr');
            if ($orCr['error']) throw new InvalidArgumentException($orCr['error']);
            $uploaded[]=$orCr['path']; $photo=['path'=>null,'mime'=>null];
            if (($_FILES['vehicle_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                $photo=storeVehicleDocument($_FILES['vehicle_photo'],'photo');
                if ($photo['error']) throw new InvalidArgumentException($photo['error']);
                $uploaded[]=$photo['path'];
            }
            $year=filter_var($_POST['year']??'',FILTER_VALIDATE_INT);
            if ($year===false) throw new InvalidArgumentException('Enter a valid vehicle year.');
            $id=createVehicle($db,$userId,(string)($_POST['make']??''),(string)($_POST['model']??''),(string)($_POST['color']??''),$year,(string)($_POST['plate_number']??''),$orCr['path'],$orCr['mime'],$photo['path'],$photo['mime']);
            $uploaded=[]; logAudit('create','vehicle',$id,'Vehicle registration submitted');
            setFlash('success','Vehicle registered for admin review.' . (residentHasPermission('resident.stickers.order') ? ' You can order a sticker after approval.' : ' Paid stickers are managed by the unit owner.'));
        } elseif (!$isResident && canReviewPermits() && ($_POST['action']??'')==='review_vehicle') {
            $id=(int)($_POST['vehicle_id']??0); $decision=(string)($_POST['decision']??'');
            if (!decideVehicle($db,$id,$decision,trim((string)($_POST['review_notes']??'')))) throw new InvalidArgumentException('Review failed. Rejecting requires a reason; only pending registrations can be reviewed.');
            logAudit($decision==='approved'?'approve':'reject','vehicle',$id);
            setFlash('success','Vehicle registration '.$decision.'.');
        } else { throw new InvalidArgumentException('Invalid vehicle action.'); }
        redirect($isResident?'vehicles.php':'registeredvehicles.php');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log($e->getMessage()); $error='Unable to save the vehicle. Please try again.'; }
    foreach ($uploaded as $path) removeUnusedVehicleUpload($path);
}
$vehicles=$isResident?getVehiclesForResident($db,$userId):getAllVehicles($db);
$vehicleSlotLabels=[];
foreach (getParkingSlots($db) as $slot) $vehicleSlotLabels[(int)$slot['id']]=$slot['slot_code'];
$stickers=[];
$sql='SELECT sv.vehicle_id,sv.sticker_number,o.id AS order_id,o.claim_status,p.status AS payment_status FROM parking_sticker_vehicles sv JOIN parking_sticker_orders o ON o.id=sv.order_id JOIN payments p ON p.id=o.bill_payment_id';
// No sticker rows means payments may not exist yet on a fresh installation.
ensurePaymentsTable($db);
$query=$db->prepare($sql.($isResident?' WHERE o.user_id=?':''));
if ($isResident) $query->bind_param('i',$userId); $query->execute();
foreach ($query->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $stickers[(int)$row['vehicle_id']]=$row;
$flash=getFlash(); $username=(string)($_SESSION['username']??'User'); $initials=strtoupper(substr($username,0,1));
function vehicleEscape($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function vehicleValue(string $name): string { return vehicleEscape(is_string($_POST[$name]??null)?$_POST[$name]:''); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registered Vehicles - Celandine Residences</title><link rel="stylesheet" href="../<?= $isResident?'resident':'styles' ?>.css"><link rel="stylesheet" href="../services.css?v=<?= filemtime(__DIR__.'/../services.css') ?>"><?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page"><div class="dash-layout"><aside class="sidebar" id="sidebar"><a href="<?= $isResident?'dashboard.php':buildUrl(dashboardPathForRole()) ?>" class="sidebar-brand"><?php include __DIR__.'/../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a><nav class="sidebar-nav"><?php if ($isResident): renderResidentSidebarNavigation('vehicles.php'); else: renderStaffSidebarNavigation(); endif; ?></nav></aside><div class="sidebar-overlay" id="sidebarOverlay"></div>
<main class="dashboard-main"><header class="dash-header"><div class="dash-header-left"><button type="button" class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?= systemIcon('menu','menu-icon') ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Registered Vehicles</h1></div></div><div class="dash-header-right"><?php include __DIR__.'/../notifications.php'; ?><div class="profile-menu" id="profileMenu"><button type="button" class="btn-profile-nav" id="profileToggle" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?= vehicleEscape($initials) ?></span><span class="profile-nav-name"><?= vehicleEscape($username) ?></span><span class="profile-caret">&#9662;</span></button><div class="profile-dropdown" id="profileDropdown"><?php if($isResident): ?><a href="edit_profile.php" class="profile-dropdown-item">Edit Profile</a><?php endif; ?><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger">Logout</a></div></div></div></header>
<?php if($error): ?><div class="alert error" role="alert"><?= vehicleEscape($error) ?></div><?php endif; ?><?php if($flash): ?><div class="alert success" role="status"><?= vehicleEscape($flash['message']) ?></div><?php endif; ?>
<section class="service-panel"><div class="service-request-heading"><div><h2><?= $isResident?'Your vehicle registry':'Vehicle registration reviews' ?></h2><p><?= $isResident ? (residentHasPermission('resident.stickers.order') ? 'Register your resident vehicle and upload its OR/CR. Order one sticker per approved vehicle. Visitor vehicles use temporary parking passes.' : 'Register your own vehicle and upload its OR/CR for management review. Paid parking stickers and their billing are managed by the unit owner.') : 'Review ownership documents here. Paid parking stickers are issued from Parking & Stickers and linked to approved vehicles.' ?></p></div><?php if (!$isResident || residentHasPermission('resident.stickers.order')): ?><a class="service-btn service-btn-secondary" href="parking.php#residentSticker"><?= systemSidebarIcon('parking') ?> Parking &amp; Stickers</a><?php elseif (residentHasPermission('resident.parking.request')): ?><a class="service-btn service-btn-secondary" href="parking.php#visitorParking"><?= systemSidebarIcon('parking') ?> Visitor Parking</a><?php endif; ?></div>
<?php if($isResident): ?><form method="post" action="vehicles.php" enctype="multipart/form-data" class="service-form"><?= workflowCsrfField() ?><input type="hidden" name="action" value="register_vehicle">
<?php foreach(['make'=>'Make','model'=>'Model','color'=>'Color','plate_number'=>'Plate number'] as $name=>$label): ?><label class="field-label" for="vehicle_<?= $name ?>"><?= $label ?><input id="vehicle_<?= $name ?>" name="<?= $name ?>" value="<?= vehicleValue($name) ?>" maxlength="<?= ['make'=>80,'model'=>100,'color'=>50,'plate_number'=>20][$name] ?>" required></label><?php endforeach; ?>
<label class="field-label" for="vehicle_year">Year<input id="vehicle_year" name="year" type="number" min="1900" max="<?= (int)date('Y')+1 ?>" value="<?= vehicleValue('year') ?>" required></label><label class="field-label" for="vehicle_or_cr">OR/CR · PDF, JPG or PNG · max 5 MB<input id="vehicle_or_cr" name="or_cr" type="file" accept="application/pdf,image/jpeg,image/png" required></label><label class="field-label service-wide" for="vehicle_photo">Vehicle photo (optional) · JPG or PNG · max 5 MB<input id="vehicle_photo" name="vehicle_photo" type="file" accept="image/jpeg,image/png"></label><div class="service-submit service-wide"><button class="service-btn" type="submit">Submit vehicle for review</button></div></form><?php endif; ?></section>
<section class="service-panel"><h2><?= $isResident?'Your vehicles':'Registered vehicles' ?></h2><?php if(!$vehicles): ?><p>No registered vehicles yet.</p><?php endif; ?><div class="vehicle-card-grid">
<?php foreach($vehicles as $vehicle): $sticker=$stickers[(int)$vehicle['id']]??null; ?><article class="vehicle-card" id="vehicle-<?= (int)$vehicle['id'] ?>"><div class="service-request-heading"><h3><?= vehicleEscape($vehicle['plate_number']) ?></h3><span class="badge unit-status <?= vehicleEscape($vehicle['status']) ?>"><?= vehicleEscape(ucfirst($vehicle['status'])) ?></span></div><p><?= vehicleEscape($vehicle['year'].' '.$vehicle['make'].' '.$vehicle['model'].' · '.$vehicle['color']) ?></p><?php if(!$isResident): ?><p><?= vehicleEscape($vehicle['full_name']) ?> · Unit <?= vehicleEscape($vehicle['unit_number']) ?></p><?php endif; ?>
<?php if($vehicle['rejection_reason']): ?><p>Review note: <?= vehicleEscape($vehicle['rejection_reason']) ?></p><?php endif; ?>
<p>Sticker: <strong><?= $isResident && !residentHasPermission('resident.stickers.order') ? 'Managed by the unit owner' : ($sticker?($sticker['sticker_number']?vehicleEscape($sticker['sticker_number']):($sticker['payment_status']==='paid'?'Paid · awaiting issuance':'Awaiting payment')):($vehicle['status']==='approved'?'Ready to request':'Available after vehicle approval')) ?></strong></p>
<p>Parking slot: <strong><?= vehicleEscape($vehicleSlotLabels[(int)($vehicle['parking_slot_id'] ?? 0)] ?? 'Not assigned') ?></strong></p>
<div class="service-actions"><?php if($isResident || canReviewPermits()): ?><a class="service-btn service-btn-secondary" href="../vehicle_document.php?vehicle_id=<?= (int)$vehicle['id'] ?>&amp;document=or_cr" target="_blank" rel="noopener">View OR/CR</a><?php if($vehicle['vehicle_photo_path']): ?><a class="service-btn service-btn-secondary" href="../vehicle_document.php?vehicle_id=<?= (int)$vehicle['id'] ?>&amp;document=photo" target="_blank" rel="noopener">View photo</a><?php endif; ?><?php endif; ?>
<?php if($isResident && residentHasPermission('resident.stickers.order') && $vehicle['status']==='approved' && !$sticker): ?><a class="service-btn" href="parking.php?vehicle_id=<?= (int)$vehicle['id'] ?>#residentSticker">Request sticker</a><?php endif; ?></div>
<?php if(!$isResident && canReviewPermits() && $vehicle['status']==='pending'): ?><form method="post" class="service-actions"><?= workflowCsrfField() ?><input type="hidden" name="action" value="review_vehicle"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><input name="review_notes" maxlength="255" aria-label="Review note" placeholder="Reason required for rejection"><button class="service-btn service-btn-approve" name="decision" value="approved">Approve vehicle</button><button class="service-btn service-btn-danger" name="decision" value="rejected">Reject vehicle</button></form><?php endif; ?></article><?php endforeach; ?></div></section></main></div>
<script src="../js/services-menu.js"></script><script src="../js/profile-menu.js"></script><script src="../js/notification-menu.js"></script></body></html>
