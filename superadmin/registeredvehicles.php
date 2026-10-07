<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}

$isResident = ($_SESSION['role'] ?? '') === 'resident';
$isAdmin = isAdmin();
if ($isResident) {
    requireApproval();
}

$connection = connectDb();
ensureParkingTables($connection);
ensureVehiclesTable($connection);
$userId = (int)$_SESSION['user_id'];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isResident) {
    $action = $_POST['action'] ?? '';
    if ($action === 'register_vehicle') {
        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $year = filter_var($_POST['year'] ?? '', FILTER_VALIDATE_INT);
        $plateNumber = trim($_POST['plate_number'] ?? '');
        $normalizedPlate = normalizePlateNumber($plateNumber);
        $orCr = $_FILES['or_cr'] ?? null;

        if ($make === '' || $model === '' || $color === '' || $year < 1900 || $year > (int)date('Y') + 1 || $normalizedPlate === '') {
            $errors[] = 'Make, model, color, year, and plate number are required.';
        } elseif ($orCr === null || (int)$orCr['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a valid OR/CR document.';
        } elseif (hasVehiclePlateConflict($connection, $plateNumber)) {
            $errors[] = 'This plate number is already registered for another pending or approved vehicle.';
        } else {
            $orCrStored = storeVehicleDocument($orCr, 'or_cr');
            if ($orCrStored['error'] !== '') {
                $errors[] = $orCrStored['error'];
            } else {
                $photoStored = ['path' => null, 'mime' => null, 'error' => ''];
                if (!empty($_FILES['vehicle_photo']['error']) && (int)$_FILES['vehicle_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $photoStored = storeVehicleDocument($_FILES['vehicle_photo'], 'photo');
                }
                if ($photoStored['error'] !== '') {
                    $errors[] = $photoStored['error'];
                } elseif (createVehicle($connection, $userId, $make, $model, $color, $year, $plateNumber, $orCrStored['path'], $orCrStored['mime'], $photoStored['path'], $photoStored['mime'])) {
                    $success = 'Vehicle registration submitted for admin review.';
                } else {
                    $errors[] = 'Could not save the vehicle registration. Please try again.';
                }
            }
        }
    }
}

$vehicles = $isResident
    ? getVehiclesForResident($connection, $userId)
    : getAllVehicles($connection);
$pendingVehicles = getPendingVehicles($connection);
$parkingSlots = getParkingSlots($connection);
$username = $_SESSION['username'] ?? 'User';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$unitNumber = $_SESSION['unit_number'] ?? '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Celandine Residences - Registered Vehicles</title>
<link rel="stylesheet" href="../styles.css?v=<?php echo filemtime(__DIR__ . '/../styles.css'); ?>">
<style>
.vehicle-registry-page { max-width: 1180px; margin: 0 auto; padding: 24px; }
.vehicle-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.vehicle-form-grid .field { display: flex; flex-direction: column; gap: 7px; }
.vehicle-form-grid .field.full { grid-column: 1 / -1; }
.vehicle-form-grid input, .vehicle-form-grid select { padding: 10px 12px; border: 1px solid #334155; border-radius: 8px; background: #0f172a; color: #e2e8f0; }
.vehicle-form-grid input[type=file] { padding: 7px; }
.vehicle-card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
.vehicle-card { padding: 18px; border: 1px solid #273247; border-radius: 12px; background: #111827; }
.vehicle-card h4 { margin: 0 0 10px; }
.vehicle-meta { color: #94a3b8; font-size: 12px; line-height: 1.7; }
.vehicle-status { display: inline-block; padding: 4px 9px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.vehicle-status.pending { background: #f59e0b22; color: #fcd34d; }
.vehicle-status.approved { background: #10b98122; color: #6ee7b7; }
.vehicle-status.rejected { background: #ef444422; color: #fca5a5; }
.vehicle-status.inactive { background: #64748b22; color: #cbd5e1; }
.vehicle-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 13px; }
.vehicle-actions a { padding: 7px 10px; border-radius: 6px; background: #1e293b; color: #e2e8f0; text-decoration: none; font-size: 12px; }
.vehicle-empty { padding: 24px; color: #94a3b8; border: 1px dashed #334155; border-radius: 10px; }
@media (max-width: 760px) { .vehicle-form-grid { grid-template-columns: 1fr; } .vehicle-form-grid .field.full { grid-column: auto; } }
</style>
</head>
<body class="dashboard-page admin-page">
<div class="dash-layout">
<aside class="sidebar" id="sidebar">
<a href="<?php echo $isResident ? '../resident/dashboard.php' : '../superadmin/admin_dashboard.php'; ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
<nav class="sidebar-nav">
<?php if ($isResident): ?><a href="../resident/dashboard.php" class="sidebar-link">Dashboard</a><a href="../resident/parking.php" class="sidebar-link">Parking</a><a href="registeredvehicles.php" class="sidebar-link active">Registered Vehicles</a><?php else: ?><a href="admin_dashboard.php" class="sidebar-link">Dashboard</a><a href="parking.php" class="sidebar-link">Parking</a><a href="registeredvehicles.php" class="sidebar-link active">Registered Vehicles</a><?php endif; ?>
</nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<main class="dashboard-main">
<header class="dash-header"><div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Registered Vehicles</h1></div></div><div class="dash-header-right"><?php include '../notifications.php'; ?><div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span></button><div class="profile-dropdown"><a href="../logout.php" class="profile-dropdown-item">Logout</a></div></div></div></header>
<?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="vehicle-registry-page">
<h2>Vehicle Registry</h2><p>Vehicles are reviewed before approval. Plate numbers are normalized to prevent duplicate registrations.</p>
<?php if ($isResident): ?>
<form method="post" action="registeredvehicles.php" class="unit-management-panel">
<input type="hidden" name="action" value="register_vehicle">
<h3 class="section-title">Register a vehicle</h3>
<div class="vehicle-form-grid">
<div class="field"><label for="make">Make</label><input id="make" name="make" maxlength="80" required></div>
<div class="field"><label for="model">Model</label><input id="model" name="model" maxlength="100" required></div>
<div class="field"><label for="color">Color</label><input id="color" name="color" maxlength="50" required></div>
<div class="field"><label for="year">Year</label><input id="year" name="year" type="number" min="1900" max="<?php echo (int)date('Y') + 1; ?>" required></div>
<div class="field"><label for="plate_number">Plate number</label><input id="plate_number" name="plate_number" maxlength="20" placeholder="ABC 1234" required></div>
<div class="field full"><label for="or_cr">OR / CR (PDF, JPG, or PNG, max 5 MB)</label><input id="or_cr" name="or_cr" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div>
<div class="field full"><label for="vehicle_photo">Vehicle photo (optional, JPG or PNG, max 5 MB)</label><input id="vehicle_photo" name="vehicle_photo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"></div>
</div>
<button type="submit" class="proceed-payment-btn">Submit for Review</button>
</form>
<?php endif; ?>
<h3 class="section-title"><?php echo $isResident ? 'Your vehicles' : 'All vehicles'; ?></h3>
<?php if (empty($vehicles)): ?><div class="vehicle-empty">No vehicles have been registered.</div><?php else: ?><div class="vehicle-card-grid"><?php foreach ($vehicles as $vehicle): ?><article class="vehicle-card"><div style="display:flex;justify-content:space-between;gap:10px"><h4><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?></h4><span class="vehicle-status <?php echo htmlspecialchars($vehicle['status']); ?>"><?php echo htmlspecialchars(ucfirst($vehicle['status'])); ?></span></div><div class="vehicle-meta"><div>Plate: <strong><?php echo htmlspecialchars($vehicle['plate_number']); ?></strong></div><div>Color: <?php echo htmlspecialchars($vehicle['color']); ?></div><div>Year: <?php echo (int)$vehicle['year']; ?></div><div>Unit: <?php echo htmlspecialchars($vehicle['unit_number'] ?? '—'); ?></div><div>Parking slot: <?php echo htmlspecialchars($vehicle['slot_code'] ?? 'Pending / not assigned'); ?></div><?php if ($vehicle['rejection_reason']): ?><div>Rejection: <?php echo htmlspecialchars($vehicle['rejection_reason']); ?></div><?php endif; ?></div><div class="vehicle-actions"><a href="../vehicle_document.php?vehicle_id=<?php echo (int)$vehicle['id']; ?>&document=or_cr">View OR / CR</a><?php if ($vehicle['vehicle_photo_path']): ?><a href="../vehicle_document.php?vehicle_id=<?php echo (int)$vehicle['id']; ?>&document=photo">View photo</a><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
<?php if (!$isResident && !empty($pendingVehicles)): ?><div class="vehicle-empty"><strong><?php echo count($pendingVehicles); ?> pending registration(s)</strong>. Review them in the <a href="../superadmin/parking.php">Parking</a> page.</div><?php endif; ?>
</section>
</main>
</div>
<script src="../js/confirmation-ui.js"></script>
</body>
</html>
