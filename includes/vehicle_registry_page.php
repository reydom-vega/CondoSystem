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
if ($_SERVER['REQUEST_METHOD']==='POST' && (!($parkingWorkspaceEmbedded ?? false) || in_array($_POST['action'] ?? '', ['register_vehicle','review_vehicle'], true))) {
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
        redirect('parking.php?tab=vehicles');
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
if ($parkingWorkspaceEmbedded ?? false) { include __DIR__.'/parking_vehicle_content.php'; return; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registered Vehicles - Celandine Residences</title><link rel="stylesheet" href="../<?= $isResident?'resident':'styles' ?>.css"><link rel="stylesheet" href="../services.css?v=<?= filemtime(__DIR__.'/../services.css') ?>"><?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page"><div class="dash-layout"><aside class="sidebar" id="sidebar"><a href="<?= $isResident?'dashboard.php':buildUrl(dashboardPathForRole()) ?>" class="sidebar-brand"><?php include __DIR__.'/../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a><nav class="sidebar-nav"><?php if ($isResident): renderResidentSidebarNavigation('vehicles.php'); else: renderStaffSidebarNavigation(); endif; ?></nav></aside><div class="sidebar-overlay" id="sidebarOverlay"></div>
<main class="dashboard-main"><header class="dash-header"><div class="dash-header-left"><button type="button" class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?= systemIcon('menu','menu-icon') ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Registered Vehicles</h1></div></div><div class="dash-header-right"><?php include __DIR__.'/../notifications.php'; ?><div class="profile-menu" id="profileMenu"><button type="button" class="btn-profile-nav" id="profileToggle" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?= vehicleEscape($initials) ?></span><span class="profile-nav-name"><?= vehicleEscape($username) ?></span><span class="profile-caret">&#9662;</span></button><div class="profile-dropdown" id="profileDropdown"><?php if($isResident): ?><a href="edit_profile.php" class="profile-dropdown-item">Edit Profile</a><?php endif; ?><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger">Logout</a></div></div></div></header>
<?php include __DIR__.'/parking_vehicle_content.php'; ?></main></div>
<script src="../js/services-menu.js"></script><script src="../js/profile-menu.js"></script><script src="../js/notification-menu.js"></script></body></html>
