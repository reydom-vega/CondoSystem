<?php
require_once '../config.php';
require_once __DIR__ . '/../includes/resident_accounts.php';
require_once __DIR__.'/../includes/archive_ui.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('units.manage');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$units = [];
$successMessage = '';
$errorMessage = '';

$connection = connectDb();
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireWorkflowCsrf();

if ($_SERVER['REQUEST_METHOD']==='POST') $errorMessage='Use the impact-summary confirmation to mark a unit vacant.';
$population=activeUnitPopulation($connection);
$residentMap=[];
$ownerRows=$connection->query("SELECT id,full_name,username,email,contact_number,unit_number,is_verified,created_at FROM users WHERE role='resident' AND status='approved' AND is_active=1 AND archived_at IS NULL AND (account_type IS NULL OR TRIM(account_type)='' OR LOWER(TRIM(account_type))='resident owner') ORDER BY id");
foreach($ownerRows as $resident) $residentMap[normalizeUnitNumber($resident['unit_number'])]=$resident;
foreach($connection->query("SELECT u.id,u.full_name,u.username,u.email,u.contact_number,r.unit_number,u.is_verified,u.created_at FROM account_unit_relationships r JOIN users u ON u.id=r.user_id WHERE r.relationship_kind='owner' AND r.ended_at IS NULL AND u.is_active=1 AND u.archived_at IS NULL AND u.status='approved'") as $resident) $residentMap[$resident['unit_number']]=$resident;
$inventory=loadUnitInventory();
foreach($inventory as $item) {
    $number=normalizeUnitNumber($item['unit_number']);$resident=$residentMap[$number]??null;
    $occupants=$population[$number]??[];$occupied=(bool)$occupants;
    $name=$resident['full_name']??($occupants[0]['full_name']??'Vacant');
    $haystack=strtolower(implode(' ',[$number,$name,$resident['username']??'',$resident['email']??'']));
    if($search!==''&&!str_contains($haystack,strtolower($search)))continue;
    if(($statusFilter==='occupied'&&!$occupied)||($statusFilter==='vacant'&&$occupied))continue;
    $units[]=['unit'=>$number,'resident_id'=>(int)($resident['id']??($occupants[0]['id']??0)),'type'=>'Residential','floor'=>'Floor '.(int)$item['floor'],'resident'=>$name,'occupant_count'=>count($occupants),'contact'=>$resident['contact_number']??'-','email'=>$resident['email']??'-','status'=>$occupied?'Occupied':'Vacant','verified'=>(bool)($resident['is_verified']??false),'created_at'=>$resident['created_at']??null];
}

$occupiedCount = count(array_filter($units, static fn (array $unit): bool => $unit['status'] === 'Occupied'));
$vacantCount = count($units) - $occupiedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">THE CELANDINE</span>
                        <h1 class="dash-title">Units</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <?= profileAvatar('profile-avatar') ?>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <?= profileAvatar('profile-dropdown-avatar') ?>
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
                                        <td><strong><?php echo htmlspecialchars($unit['resident']); ?></strong><?php if ($unit['email'] !== '-'): ?><small>Unit owner · <?php echo htmlspecialchars($unit['email']); ?></small><small><?php echo (int)$unit['occupant_count']; ?> active occupant(s) · <a href="residents.php?search=<?php echo rawurlencode($unit['unit']); ?>">View occupants</a></small><?php endif; ?></td>
                                        <td><?php echo htmlspecialchars($unit['contact']); ?></td>
                                        <td><span class="unit-status <?php echo strtolower($unit['status']); ?>"><?php echo htmlspecialchars($unit['status']); ?></span></td>
                                        <td>
                                            <?php if ($unit['status'] === 'Occupied' && (int)($unit['resident_id'] ?? 0) > 0): ?>
                                                <button type="button" class="unit-action-btn" data-archive-mode="vacate_unit" data-archive-user="<?php echo (int)$unit['resident_id']; ?>" data-archive-unit="<?php echo htmlspecialchars($unit['unit'],ENT_QUOTES,'UTF-8'); ?>">Mark vacant</button>
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
<?php renderArchiveConfirmation(); ?>
</body>
</html>
