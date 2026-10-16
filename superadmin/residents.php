<?php
require_once '../config.php';
require_once __DIR__.'/../includes/resident_accounts.php';
require_once __DIR__.'/../includes/archive_ui.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('residents.read');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$search = trim($_GET['search'] ?? '');
$residents = [];

$connection = connectDb();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireWorkflowCsrf();requireCapability('archive.manage');
    setFlash('error','Use End Occupancy or End Account Relationship to review the impact and confirm an archive reason.');redirect('residents.php');
}
$flash=getFlash();
$result = $connection->query("SELECT id,resident_id, account_type, unit_owner_id, full_name, username, email, contact_number, unit_number, status, is_active, is_verified, last_login_at, last_seen_at, created_at FROM users WHERE role = 'resident' AND status = 'approved' AND is_verified=1 AND is_active=1 AND archived_at IS NULL ORDER BY full_name ASC");
if ($result) {
    while ($resident = $result->fetch_assoc()) {
        $haystack = strtolower(implode(' ', [$resident['full_name'], $resident['username'], $resident['email'], $resident['unit_number'] ?? '', $resident['contact_number']]));
        $context=residentContext($connection,(int)$resident['id']);
        $displayStatus = ($context['approved'] ?? false) ? 'Active' : 'Approved';

        if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
            continue;
        }

        $residents[] = [
            'id'=>(int)$resident['id'], 'account_kind'=>$context['account_kind'],
            'relationship'=>($resident['account_type'] ?: 'Unit Owner (legacy)').($context['account_kind']==='owner'&&!$context['occupying']?' ? non-occupying owner; ownership retained':''),
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

$activeCount = count($residents);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('services.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><span class="dash-subtitle">THE CELANDINE</span><h1 class="dash-title">Residents</h1></div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><?= profileAvatar('profile-avatar') ?><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header"><?= profileAvatar('profile-dropdown-avatar') ?><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="unit-page-head">
                <div><h2>Residents</h2><p>Review approved unit owners and linked tenants or occupants. Pending registrations appear in Pending Accounts. Super Admin can end a selected relationship here. Mark Vacant in Units ends physical occupancy while preserving legal ownership.</p></div>
                <div class="unit-summary"><span><?php echo $activeCount; ?> Approved</span></div>
            </section>

            <section class="unit-management-panel">
                <?php if($flash): ?><div class="alert <?php echo $flash['type']==='error'?'error':'success'; ?>"><?php echo htmlspecialchars($flash['message'],ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
                <form class="unit-filters" method="get" action="residents.php">
                    <label for="residentSearch">Search residents</label>
                    <input id="residentSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search resident or unit...">
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
                                        <td><?php if(canAccess('archive.manage') && ($resident['can_end'] || ($resident['account_kind']==='owner' && $resident['status']==='Active'))): ?><button class="service-btn service-btn-danger" type="button" data-archive-mode="<?= $resident['can_end']?'end_occupancy':'archive_account' ?>" data-archive-user="<?= (int)$resident['id'] ?>" data-archive-unit="<?= htmlspecialchars($resident['unit'],ENT_QUOTES,'UTF-8') ?>"><?= $resident['can_end']?'End occupancy':'End account relationship' ?></button><?php else: ?>?<?php endif; ?></td>
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
