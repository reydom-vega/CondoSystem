<?php
require_once '../config.php';
require_once __DIR__.'/../includes/resident_accounts.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('residents.read');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$residents = [];

$connection = connectDb();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireWorkflowCsrf(); requireCapability('units.manage');
    $targetId=(int)($_POST['resident_id'] ?? 0);
    $target=residentContext($connection,$targetId);
    $ended=($_POST['action'] ?? '')==='end_tenancy' && $target && in_array($target['account_kind'],['tenant','occupant'],true) && unassignResidentUnit($connection,$targetId,(string)($_POST['unit_number'] ?? ''));
    setFlash($ended ? 'success' : 'error',$ended ? 'Occupant access ended. Their sessions and unopened requests were revoked; financial history was retained.' : 'Could not end that occupancy. Refresh the resident list and try again.');
    redirect('residents.php');
}
$flash=getFlash();
$result = $connection->query("SELECT id,resident_id, account_type, unit_owner_id, full_name, username, email, contact_number, unit_number, status, is_active, is_verified, last_login_at, last_seen_at, created_at FROM users WHERE role = 'resident' ORDER BY full_name ASC");
if ($result) {
    while ($resident = $result->fetch_assoc()) {
        $haystack = strtolower(implode(' ', [$resident['full_name'], $resident['username'], $resident['email'], $resident['unit_number'] ?? '', $resident['contact_number']]));
        $context=residentContext($connection,(int)$resident['id']);
        $isActive = $context['approved'] ?? false;
        $displayStatus = $isActive ? 'Active' : ((int)$resident['is_active'] !== 1 ? 'Inactive' : ($resident['status'] === 'rejected' ? 'Rejected' : ((int)$resident['is_verified'] !== 1 ? 'Unverified' : 'Pending')));

        if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
            continue;
        }
        if ($statusFilter === 'active' && !$isActive) {
            continue;
        }
        if ($statusFilter === 'pending' && $isActive) {
            continue;
        }

        $residents[] = [
            'id'=>(int)$resident['id'], 'account_kind'=>$context['account_kind'],
            'relationship'=>$resident['account_type'] ?: 'Unit Owner (legacy)',
            'owner_id'=>$context['account_kind']==='owner' ? null : $resident['unit_owner_id'],
            'can_end'=>$resident['status']==='approved' && in_array($context['account_kind'],['tenant','occupant'],true) && trim((string)$resident['unit_number'])!=='',
            'resident_id' => trim((string)($resident['unit_number'] ?? '')) !== '' ? $resident['unit_number'] : 'Unassigned',
            'name' => $resident['full_name'],
            'username' => $resident['username'],
            'unit' => trim((string)($resident['unit_number'] ?? '')) !== '' ? $resident['unit_number'] : 'Unassigned',
            'contact' => $resident['contact_number'],
            'email' => $resident['email'],
            'status' => $displayStatus,
            'last_seen_at' => $resident['last_seen_at'] ?? null,
            'last_login_at' => $resident['last_login_at'] ?? null,
            'created_at' => $resident['created_at'],
        ];
    }
}

$activeCount = count(array_filter($residents, static fn (array $resident): bool => $resident['status'] === 'Active'));
$pendingCount = count($residents) - $activeCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="../services.css">
</head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Residents</h1></div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="unit-page-head">
                <div><h2>Residents</h2><p>Review unit owners and linked tenants or occupants. End an occupant's access here; use Units to remove an owner and all linked occupants.</p></div>
                <div class="unit-summary"><span><?php echo $activeCount; ?> Active</span><span><?php echo $pendingCount; ?> Pending</span></div>
            </section>

            <section class="unit-management-panel">
                <?php if($flash): ?><div class="alert <?php echo $flash['type']==='error'?'error':'success'; ?>"><?php echo htmlspecialchars($flash['message'],ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
                <form class="unit-filters" method="get" action="residents.php">
                    <label for="residentSearch">Search residents</label>
                    <input id="residentSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search resident or unit...">
                    <label for="residentStatus">Filter by status</label>
                    <select id="residentStatus" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All residents</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                    <button type="submit">Search</button>
                </form>
                <div class="unit-table-wrap">
                    <table class="unit-table resident-table">
                        <thead><tr><th>Unit ID</th><th>Name</th><th>Relationship</th><th>Unit Number</th><th>Contact</th><th>Email</th><th>Status</th><th>Occupancy</th></tr></thead>
                        <tbody>
                            <?php if (empty($residents)): ?>
                                <tr><td colspan="8" class="unit-empty">No resident information matches your search.</td></tr>
                            <?php else: ?>
                                <?php foreach ($residents as $resident): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($resident['resident_id']); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($resident['name']); ?></strong><small>@<?php echo htmlspecialchars($resident['username']); ?></small></td>
                                        <td><?php echo htmlspecialchars($resident['relationship']); ?><?php if($resident['account_kind']!=='owner'): ?><small><?php echo $resident['owner_id'] ? 'Linked owner account #'.(int)$resident['owner_id'] : 'Owner review required'; ?></small><?php endif; ?></td>
                                        <td><?php echo htmlspecialchars($resident['unit']); ?></td>
                                        <td><?php echo htmlspecialchars($resident['contact']); ?></td>
                                        <td><?php echo htmlspecialchars($resident['email']); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($resident['status']); ?></strong>
                                            <?php $presence = userPresenceSummary($resident['last_seen_at'] ?? null, $resident['last_login_at'] ?? null); ?>
                                            <span class="unit-status <?php echo $presence['online'] ? 'online' : 'offline'; ?>"><?php echo htmlspecialchars($presence['label']); ?></span>
                                            <small class="staff-last-login"><?php echo htmlspecialchars($presence['detail']); ?></small>
                                        </td>
                                        <td><?php if($resident['can_end'] && canAccess('units.manage')): ?><form method="post" onsubmit="return confirm('End this occupant access? Review any legacy bills first. Sessions and unopened requests will be revoked.');"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="end_tenancy"><input type="hidden" name="resident_id" value="<?php echo $resident['id']; ?>"><input type="hidden" name="unit_number" value="<?php echo htmlspecialchars($resident['unit'],ENT_QUOTES,'UTF-8'); ?>"><button class="service-btn service-btn-danger" type="submit">End occupancy</button></form><?php else: ?>—<?php endif; ?></td>
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
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => { event.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
        document.addEventListener('click', (event) => { if (!profileMenu.contains(event.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
    </script>
</body>
</html>
