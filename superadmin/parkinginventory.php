<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isAdmin()) {
    redirect('../resident/dashboard.php');
}
if (!isSuperAdmin()) {
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
            $errors[] = 'Slot status cannot change while its standing assignment or current reservations conflict with the selected status.';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Parking Inventory</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime(__DIR__ . '/../styles.css'); ?>">
    <link rel="stylesheet" href="../services.css">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Parking Inventory</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit">Administrator</span>
                                </div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>
            <div class="service-actions"><a class="service-btn service-btn-secondary" href="parking_configuration.php">Parking configuration</a><a class="service-btn service-btn-secondary" href="parking.php">Parking requests</a><form method="post"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="import_inventory"><button class="service-btn service-btn-secondary" type="submit">Import missing inventory slots</button></form></div>

            <?php if ($success !== ''): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="unit-page-head">
                <div>
                    <h2>Parking Slot Inventory</h2>
                    <p>Manage resident and visitor parking slots across all levels.</p>
                </div>
                <div class="unit-summary">
                    <span><?php echo $totalSlots; ?> Total Slots</span>
                    <a class="registered-vehicles-link" href="parking.php">Parking Requests</a>
                    <a class="registered-vehicles-link" href="registeredvehicles.php">Registered Vehicles</a>
                </div>
            </section>

            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🅿️', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo $totalSlots; ?></strong><span>Total Slots</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('✅', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $availableSlots; ?></strong><span>Available</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🚗', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $occupiedSlots; ?></strong><span>Occupied</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🛠️', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo $maintenanceSlots; ?></strong><span>Maintenance</span></div>
            </section>

            <section class="unit-management-panel parking-panel parking-inventory-panel">
                <div class="csv-import-panel">
                    <div>
                        <h3 class="section-title">Parking Inventory</h3>
                </div>

                <form method="get" class="parking-search-form">
                    <div class="field">
                        <label class="field-label" for="inventory_search">Search parking inventory</label>
                        <input type="search" id="inventory_search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Slot number, plate number, or unit number">
                    </div>
                    <button type="submit" class="btn-primary">Search</button>
                    <?php if ($searchTerm !== ''): ?>
                        <a class="registered-vehicles-link" href="parkinginventory.php">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="unit-table-wrap">
                    <table class="unit-table">
                        <thead>
                            <tr>
                                <th>Slot Code</th>
                                <th>Level</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Plate</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($slots)): ?>
                                <tr>
                                    <td colspan="7" class="unit-empty"><?php echo $searchTerm === '' ? 'No parking slots have been added yet.' : 'No parking slots match your search.'; ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($slots as $slot): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars((string)($slot['slot_code'] ?? '—')); ?></strong></td>
                                        <td><?php echo htmlspecialchars((string)($slot['level'] ?? '—')); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst((string)($slot['slot_type'] ?? 'resident'))); ?></td>
                                        <td>
                                            <span class="unit-status <?php echo htmlspecialchars((string)($slot['status'] ?? 'available')); ?>"><?php echo htmlspecialchars(ucfirst((string)($slot['status'] ?? 'available'))); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)($slot['assigned_name'] ?? 'Unassigned')); ?><?php if (!empty($slot['assigned_unit'])): ?><br><small><?php echo htmlspecialchars((string)$slot['assigned_unit']); ?></small><?php endif; ?></td>
                                        <td><?php echo htmlspecialchars((string)($slot['vehicle_plate'] ?? '—')); ?></td>
                                        <td>
                                            <div class="parking-slot-actions">
                                                <form method="post" class="parking-status-form" data-confirm="Update the status of parking slot <?php echo htmlspecialchars((string)($slot['slot_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>?" data-confirm-title="Update parking slot" data-confirm-action="Update"><?php echo workflowCsrfField(); ?>
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="slot_id" value="<?php echo (int)($slot['id'] ?? 0); ?>">
                                                    <select name="status" aria-label="Update status for <?php echo htmlspecialchars((string)($slot['slot_code'] ?? 'slot')); ?>">
                                                        <option value="available" <?php echo (($slot['status'] ?? 'available') === 'available') ? 'selected' : ''; ?>>Available</option>
                                                        <option value="occupied" <?php echo (($slot['status'] ?? 'available') === 'occupied') ? 'selected' : ''; ?>>Occupied</option>
                                                        <option value="maintenance" <?php echo (($slot['status'] ?? 'available') === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                                    </select>
                                                    <button type="submit" class="btn-small btn-neutral">Update</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
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
        profileToggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const isOpen = profileMenu.classList.toggle('open');
            profileToggle.setAttribute('aria-expanded', isOpen);
        });
        document.addEventListener('click', (event) => {
            if (!profileMenu.contains(event.target)) {
                profileMenu.classList.remove('open');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
</body>
</html>
