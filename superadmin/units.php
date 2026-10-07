<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isAdmin()) {
    redirect('../resident/dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$units = [];
$successMessage = '';
$errorMessage = '';

$connection = connectDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_vacant') {
    $residentId = (int)($_POST['resident_id'] ?? 0);
    $unitNumber = trim((string)($_POST['unit_number'] ?? ''));

    if ($residentId <= 0) {
        $errorMessage = 'Please choose a resident to mark vacant.';
    } else {
        $stmt = $connection->prepare("UPDATE users SET unit_number = NULL, resident_id = NULL WHERE id = ? AND role = 'resident'");
        $stmt->bind_param('i', $residentId);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $successMessage = 'Unit ' . htmlspecialchars($unitNumber) . ' has been marked as vacant.';
        } else {
            $errorMessage = 'Could not mark that unit as vacant.';
        }
    }
}

$residentMap = [];
$result = $connection->query("SELECT id, full_name, username, email, contact_number, unit_number, is_verified, created_at FROM users WHERE role = 'resident' ORDER BY unit_number ASC, full_name ASC");
if ($result) {
    while ($resident = $result->fetch_assoc()) {
        $unitNumber = normalizeUnitNumber((string) ($resident['unit_number'] ?? ''));
        if ($unitNumber !== '') {
            $residentMap[$unitNumber] = $resident;
        }
    }
}

$inventory = loadUnitInventory();
if (!empty($inventory)) {
    foreach ($inventory as $item) {
        $unitNumber = normalizeUnitNumber((string) ($item['unit_number'] ?? ''));
        $resident = $residentMap[$unitNumber] ?? null;
        $isOccupied = $resident !== null;
        $residentName = $isOccupied ? ($resident['full_name'] ?? 'Assigned') : 'Vacant';
        $contact = $isOccupied ? ($resident['contact_number'] ?? '-') : '-';
        $email = $isOccupied ? ($resident['email'] ?? '-') : '-';
        $haystack = strtolower(implode(' ', [$unitNumber, $residentName, $resident['username'] ?? '', $resident['email'] ?? '']));

        if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
            continue;
        }
        if ($statusFilter === 'occupied' && !$isOccupied) {
            continue;
        }
        if ($statusFilter === 'vacant' && $isOccupied) {
            continue;
        }

        $units[] = [
            'unit' => $unitNumber,
            'resident_id' => $isOccupied ? ((int)($resident['id'] ?? 0)) : 0,
            'type' => 'Residential',
            'floor' => 'Floor ' . (int)($item['floor'] ?? 0),
            'resident' => $residentName,
            'contact' => $contact,
            'email' => $email,
            'status' => $isOccupied ? 'Occupied' : 'Vacant',
            'verified' => $isOccupied ? ((int)($resident['is_verified'] ?? 0) === 1) : false,
            'created_at' => $isOccupied ? ($resident['created_at'] ?? null) : null,
        ];
    }
} else {
    $result = $connection->query("SELECT id, full_name, username, email, contact_number, unit_number, is_verified, created_at FROM users WHERE role = 'resident' ORDER BY unit_number ASC, full_name ASC");
    if ($result) {
        while ($resident = $result->fetch_assoc()) {
            $unitNumber = trim($resident['unit_number']);
            $isOccupied = $unitNumber !== '';
            $haystack = strtolower(implode(' ', [$unitNumber, $resident['full_name'], $resident['username'], $resident['email']]));

            if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
                continue;
            }
            if ($statusFilter === 'occupied' && !$isOccupied) {
                continue;
            }
            if ($statusFilter === 'vacant' && $isOccupied) {
                continue;
            }

            preg_match('/^(\d+)/', $unitNumber, $floorMatch);
            $floor = !empty($floorMatch[1]) ? 'Floor ' . (int)ceil(((int)$floorMatch[1]) / 10) : 'Unassigned';
            $units[] = [
                'unit' => $isOccupied ? $unitNumber : 'Unassigned',
                'resident_id' => $isOccupied ? ((int)($resident['id'] ?? 0)) : 0,
                'type' => $isOccupied ? 'Residential' : 'N/A',
                'floor' => $floor,
                'resident' => $isOccupied ? $resident['full_name'] : 'Vacant',
                'contact' => $isOccupied ? $resident['contact_number'] : '-',
                'email' => $isOccupied ? $resident['email'] : '-',
                'status' => $isOccupied ? 'Occupied' : 'Vacant',
                'verified' => (int)$resident['is_verified'] === 1,
                'created_at' => $resident['created_at'],
            ];
        }
    }
}

$occupiedCount = count(array_filter($units, static fn (array $unit): bool => $unit['status'] === 'Occupied'));
$vacantCount = count($units) - $occupiedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .vacancy-confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.68);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .vacancy-confirm-overlay.open {
            display: flex;
        }

        .vacancy-confirm-modal {
            width: min(420px, 100%);
            background: #111a2b;
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
            padding: 24px;
            color: #f8fafc;
        }

        .vacancy-confirm-modal h3 {
            margin: 0 0 12px;
            font-size: 1.3rem;
            color: #f8fafc;
        }

        .vacancy-confirm-modal p {
            margin: 0 0 20px;
            color: #aab4c4;
            line-height: 1.5;
        }

        .vacancy-confirm-actions {
            display: flex;
            justify-content: stretch;
            gap: 10px;
        }

        .vacancy-confirm-actions button {
            border: none;
            flex: 1 1 0;
            min-width: 0;
            min-height: 38px;
            border-radius: 9px;
            padding: 9px 14px;
            cursor: pointer;
            font-weight: 600;
        }

        .vacancy-confirm-cancel {
            background: #293346;
            color: #f8fafc;
        }

        .vacancy-confirm-submit {
            background: linear-gradient(110deg, #f59e0b, #f97316);
            color: #ffffff;
        }
    </style>
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="admin_dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav">
                <a href="admin_dashboard.php" class="sidebar-link"><?php echo systemSidebarIcon('dashboard'); ?> Dashboard</a>
                <a href="units.php" class="sidebar-link active"><?php echo systemSidebarIcon('units'); ?> Units</a>
                <a href="residents.php" class="sidebar-link"><?php echo systemSidebarIcon('residents'); ?> Residents</a>
                <?php if (isSuperAdmin()): ?>
                    <a href="pending_accounts.php" class="sidebar-link"><?php echo systemSidebarIcon('pending'); ?> Pending Accounts</a>
                    <a href="staff.php" class="sidebar-link"><?php echo systemSidebarIcon('staff'); ?> Staff Management</a>
                    <a href="unitpayments.php" class="sidebar-link"><?php echo systemSidebarIcon('billing'); ?> Billing &amp; Payments</a>
                    <a href="generate_bills.php" class="sidebar-link"><?php echo systemSidebarIcon('bills'); ?> Generate Bills</a>
                    <a href="violations.php" class="sidebar-link"><?php echo systemSidebarIcon('violations'); ?> Violations</a>
                <?php endif; ?>
                <a href="bookingrequest.php" class="sidebar-link"><?php echo systemSidebarIcon('calendar'); ?> Booking Requests</a>
                <a href="maintenancerequests.php" class="sidebar-link"><?php echo systemSidebarIcon('maintenance'); ?> Maintenance Requests</a>
                <a href="admin_messages.php" class="sidebar-link"><?php echo systemSidebarIcon('messages'); ?> Messages</a>
                <a href="announcements.php" class="sidebar-link"><?php echo systemSidebarIcon('announcements'); ?> Announcements</a>
                <?php if (isSuperAdmin()): ?><a href="analytics.php" class="sidebar-link"><?php echo systemSidebarIcon('analytics'); ?> Analytics</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="parking.php" class="sidebar-link"><?php echo systemSidebarIcon('parking'); ?> Parking</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="auditlog.php" class="sidebar-link"><?php echo systemSidebarIcon('audit'); ?> Audit Log</a><?php endif; ?>
                <?php if (isSuperAdmin()): ?><a href="visitorlog.php" class="sidebar-link"><?php echo systemSidebarIcon('visitors'); ?> Visitor Log</a><?php endif; ?>
            </nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Units</h1>
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
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <?php if ($successMessage !== ''): ?>
                <div class="alert success"><?php echo $successMessage; ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert error"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <section class="unit-page-head">
                <div>
                    <h2>Unit Management</h2>
                    <p>View unit assignments and resident information.</p>
                </div>
                <div class="unit-summary"><span><?php echo $occupiedCount; ?> Occupied</span><span><?php echo $vacantCount; ?> Vacant</span></div>
            </section>

            <section class="unit-management-panel">
                <form class="unit-filters" method="get" action="units.php">
                    <label for="unitSearch">Search units</label>
                    <input id="unitSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search unit or resident...">
                    <label for="unitStatus">Filter by status</label>
                    <select id="unitStatus" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All units</option>
                        <option value="occupied" <?php echo $statusFilter === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                        <option value="vacant" <?php echo $statusFilter === 'vacant' ? 'selected' : ''; ?>>Vacant</option>
                    </select>
                    <button type="submit">Search</button>
                </form>

                <div class="unit-table-wrap">
                    <table class="unit-table">
                        <thead>
                            <tr><th>Unit #</th><th>Type</th><th>Floor</th><th>Resident</th><th>Contact</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($units)): ?>
                                <tr><td colspan="7" class="unit-empty">No unit information matches your search.</td></tr>
                            <?php else: ?>
                                <?php foreach ($units as $unit): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($unit['unit']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($unit['type']); ?></td>
                                        <td><?php echo htmlspecialchars($unit['floor']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($unit['resident']); ?></strong><?php if ($unit['email'] !== '-'): ?><small><?php echo htmlspecialchars($unit['email']); ?></small><?php endif; ?></td>
                                        <td><?php echo htmlspecialchars($unit['contact']); ?></td>
                                        <td><span class="unit-status <?php echo strtolower($unit['status']); ?>"><?php echo htmlspecialchars($unit['status']); ?></span></td>
                                        <td>
                                            <?php if ($unit['status'] === 'Occupied' && (int)($unit['resident_id'] ?? 0) > 0): ?>
                                                <form method="post" class="unit-vacancy-form" data-resident="<?php echo htmlspecialchars($unit['resident']); ?>" data-unit="<?php echo htmlspecialchars($unit['unit']); ?>">
                                                    <input type="hidden" name="action" value="mark_vacant">
                                                    <input type="hidden" name="resident_id" value="<?php echo (int)($unit['resident_id'] ?? 0); ?>">
                                                    <input type="hidden" name="unit_number" value="<?php echo htmlspecialchars($unit['unit']); ?>">
                                                    <button type="submit" class="unit-action-btn">Mark vacant</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="unit-empty-state">Vacant</span>
                                            <?php endif; ?>
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

    <div class="confirm-modal system-confirm-overlay vacancy-confirm-overlay" id="vacancyConfirmOverlay" aria-hidden="true">
        <div class="confirm-dialog vacancy-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="vacancyConfirmTitle">
            <h3 id="vacancyConfirmTitle">Confirm unit vacancy</h3>
            <p id="vacancyConfirmText">This will remove the resident assignment for this unit.</p>
            <div class="confirm-actions vacancy-confirm-actions">
                <button type="button" class="confirm-cancel vacancy-confirm-cancel" id="vacancyConfirmCancel">Cancel</button>
                <button type="button" class="confirm-ok vacancy-confirm-submit" id="vacancyConfirmSubmit">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => { event.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
        document.addEventListener('click', (event) => { if (!profileMenu.contains(event.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });

        const vacancyOverlay = document.getElementById('vacancyConfirmOverlay');
        const vacancyText = document.getElementById('vacancyConfirmText');
        const vacancyCancel = document.getElementById('vacancyConfirmCancel');
        const vacancySubmit = document.getElementById('vacancyConfirmSubmit');
        let pendingVacancyForm = null;

        document.querySelectorAll('.unit-vacancy-form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const resident = form.dataset.resident || 'this resident';
                const unit = form.dataset.unit || 'this unit';

                pendingVacancyForm = form;
                vacancyText.textContent = 'Mark unit ' + unit + ' as vacant for ' + resident + '? This removes the resident assignment for this unit.';
                vacancyOverlay.classList.add('open');
                vacancyOverlay.setAttribute('aria-hidden', 'false');
            });
        });

        vacancyCancel.addEventListener('click', () => {
            vacancyOverlay.classList.remove('open');
            vacancyOverlay.setAttribute('aria-hidden', 'true');
            pendingVacancyForm = null;
        });

        vacancySubmit.addEventListener('click', () => {
            if (pendingVacancyForm) {
                vacancyOverlay.classList.remove('open');
                vacancyOverlay.setAttribute('aria-hidden', 'true');
                pendingVacancyForm.submit();
            }
        });
    </script>
</body>
</html>