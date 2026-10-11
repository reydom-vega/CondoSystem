<?php
require_once __DIR__.'/../config.php';
if (!($parkingWorkspaceEmbedded ?? false)) { requireCapability('management.dashboard'); $parkingDefaultTab='slots'; require __DIR__.'/parking.php'; return; }

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isAdmin()) {
    redirect('../resident/dashboard.php');
}
if (!canAccess('parking.review')) {
    redirect('../admin/admin_dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$connection = connectDb();
ensureParkingTables($connection);

$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['import_inventory','update_status'], true)) {
    requireCapability('parking.configure');
    requireWorkflowCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'import_inventory') {
        try {
            $parkingImport = importParkingInventoryCsv($connection);
            $errors = array_merge($errors, $parkingImport['errors']);
            if ($parkingImport['ok']) {
                $success = "Imported {$parkingImport['imported']} missing parking slots; existing slots were preserved.";
                logAudit('import', 'parking_inventory', null, $success);
            }
        } catch (Throwable $error) {
            error_log('Parking inventory import failed: ' . $error->getMessage());
            $errors[] = 'The inventory could not be imported. Check the configured inventory file.';
        }
    } elseif ($action === 'update_status') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $status = $_POST['status'] ?? 'available';

        if ($slotId <= 0 || !in_array($status, ['available', 'occupied', 'maintenance'], true)) {
            $errors[] = 'Invalid slot status update.';
        } elseif (setParkingSlotStatus($slotId, $status)) {
            $success = 'Parking slot status updated.';
        } else {
            $errors[] = 'Could not update the slot. Assigned resident slots cannot be marked Available. Slots with current or future approved reservations cannot be marked Maintenance; reassign those reservations first.';
        }
    }
}

$searchTerm = trim((string)($_GET['search'] ?? ''));
$allSlots = getParkingSlots($connection);
$slots = $searchTerm === '' ? $allSlots : getParkingSlots($connection, 'all', 'all', $searchTerm);
$totalSlots = count($allSlots);
$availableSlots = 0;
$occupiedSlots = 0;
$maintenanceSlots = 0;
$residentSlots = 0;
$visitorSlots = 0;

foreach ($allSlots as $slot) {
    $status = $slot['status'] ?? 'available';
    if ($status === 'available') {
        $availableSlots++;
    } elseif ($status === 'occupied') {
        $occupiedSlots++;
    } elseif ($status === 'maintenance') {
        $maintenanceSlots++;
    }

    if (($slot['slot_type'] ?? 'resident') === 'resident') {
        $residentSlots++;
    } else {
        $visitorSlots++;
    }
}
include __DIR__.'/../includes/parking_inventory_content.php';
