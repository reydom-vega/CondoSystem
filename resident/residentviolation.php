<?php
require_once '../config.php';
require_once __DIR__ . '/../includes/violation_workspace.php';

if (!isLoggedIn()) {
	redirect('../login.php');
}
if (isAdmin()) {
	redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();
requireResidentPermission('resident.violations.view');

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$userId = (int)$_SESSION['user_id'];
$connection = connectDb();
if (!ensureViolationsTable($connection)) {
	http_response_code(500);
	exit('Violation records are unavailable. Please contact management.');
}

$errors = [];
$successMessage = $_SESSION['resident_violation_success'] ?? '';
unset($_SESSION['resident_violation_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'dispute_violation') {
    requireWorkflowCsrf();
	$violationId = (int)($_POST['violation_id'] ?? 0);
	$reason = trim($_POST['dispute_reason'] ?? '');
	if ($reason === '') {
		$errors[] = "Please explain why you're disputing this fine.";
	} elseif (disputeViolation($violationId, $userId, $reason)) {
		$_SESSION['resident_violation_success'] = 'Dispute submitted. Management will review it before the due date.';
		header('Location: residentviolation.php?tab=current&filter=disputed&violation=' . $violationId . '#violationDetails');
		exit;
	} else {
		$errors[] = 'Could not submit your dispute. It may already be resolved.';
	}
}

$violations = getViolationsForUser($userId);
$statusLabels = ['warning_issued' => 'Warning', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'disputed' => 'Disputed', 'waived' => 'Waived'];
$activeFilter = $_GET['filter'] ?? 'all';
if (!in_array($activeFilter, ['all', 'unpaid', 'disputed', 'paid', 'waived', 'warnings'], true)) {
	$activeFilter = 'all';
}
$violationSearch = substr(trim((string)($_GET['search'] ?? '')), 0, 100);
$violationStats = ['total' => count($violations), 'unpaid' => 0, 'disputed' => 0, 'paid' => 0, 'waived' => 0, 'warnings' => 0, 'outstanding' => 0, 'unpaid_amount' => 0.0];
foreach ($violations as $record) {
	if (isset($violationStats[$record['status']])) {
		$violationStats[$record['status']]++;
	}
	if ($record['penalty_type'] === 'warning') {
		$violationStats['warnings']++;
	}
	if (in_array($record['status'], ['unpaid', 'disputed'], true)) {
		$violationStats['outstanding']++;
		$violationStats['unpaid_amount'] += (float)$record['fine_amount'];
	}
}
$requestedFilter = $activeFilter;
$violationGroups = ['current' => [], 'history' => []];
$selectedViolationId = (int)($_GET['violation'] ?? $_POST['violation_id'] ?? 0);
$inferredTab = in_array($requestedFilter, ['paid', 'waived', 'warnings'], true) ? 'history' : 'current';
foreach ($violations as $record) {
    $group = residentViolationIsHistory($record) ? 'history' : 'current';
    $violationGroups[$group][] = $record;
    if ((int)$record['id'] === $selectedViolationId) $inferredTab = $group;
}
$activeTab = $_POST['tab'] ?? $_GET['tab'] ?? $inferredTab;
if (!in_array($activeTab, ['current', 'history'], true)) $activeTab = $inferredTab;
$violationIcons = [
	'Unauthorized Parking' => 'car',
	'Noise Complaint' => 'volume',
	'Pet Violation' => 'paw',
	'Unauthorized Renovation' => 'maintenance',
	'Improper Waste Disposal' => 'clipboard',
	'Littering' => 'recycle',
	'Smoking in Common Area' => 'cigarette',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>The Celandine - Violations</title>
	<link rel="stylesheet" href="../resident.css?v=<?php echo (int)filemtime(__DIR__ . '/../resident.css'); ?>">
<?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="../assets/css/violation-workspace.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/css/violation-workspace.css'); ?>">
    <script src="../assets/js/violation-workspace.js?v=<?php echo (int)filemtime(__DIR__ . '/../assets/js/violation-workspace.js'); ?>" defer></script>
	<link rel="stylesheet" href="../assets/css/resident-violations.css?v=<?php echo filemtime(__DIR__.'/../assets/css/resident-violations.css'); ?>">
</head>
<body class="portal-ui dashboard-page resident-violations-page">
	<div class="dash-layout">
		<aside class="sidebar" id="sidebar">
			<a href="dashboard.php" class="sidebar-brand"><?php include '../buildingicon.php'; ?></a>
			<nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
		</aside>
		<div class="sidebar-overlay" id="sidebarOverlay"></div>

		<main class="dashboard-main violation-workspace">
			<header class="dash-header">
				<div class="dash-header-left">
					<button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
					<div><span class="dash-subtitle">THE CELANDINE</span><h1 class="dash-title">Violations</h1></div>
				</div>
				<div class="dash-header-right">
					<?php include '../notifications.php'; ?>
					<div class="profile-menu" id="profileMenu">
						<button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><?= profileAvatar('profile-avatar') ?><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
						<div class="profile-dropdown" id="profileDropdown">
							<div class="profile-dropdown-header"><?= profileAvatar('profile-dropdown-avatar') ?><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span></div></div>
							<a href="edit_profile.php" class="profile-dropdown-item"><?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile</a>
							<a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
						</div>
					</div>
				</div>
			</header>

			<?php if ($successMessage !== ''): ?><div class="alert success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
			<?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
			<section class="violation-page-intro" aria-labelledby="violationPageHeading">
				<div><span class="violation-eyebrow">YOUR COMMUNITY RECORDS</span><h2 id="violationPageHeading">Review your violations</h2><p>View incident details, evidence, fine balances and dispute updates.</p></div>
				<span class="violation-unit-chip"><?php echo systemIcon('units'); ?><?php echo $unitNumber; ?></span>
			</section>

			<section class="resident-violation-summary" aria-label="Violation summary">
				<div class="resident-violation-stat stat-total"><?php echo systemIconFromGlyph('⚠', 'stat-icon'); ?><div><small>Total Violations</small><strong><?php echo (int)$violationStats['total']; ?></strong></div></div>
				<div class="resident-violation-stat stat-unpaid"><?php echo systemIconFromGlyph('₱', 'stat-icon'); ?><div><small>Outstanding Fine Balance</small><strong>₱<?php echo number_format($violationStats['unpaid_amount'], 2); ?></strong></div></div>
				<div class="resident-violation-stat stat-paid"><?php echo systemIconFromGlyph('✓', 'stat-icon'); ?><div><small>Paid</small><strong><?php echo (int)$violationStats['paid']; ?></strong></div></div>
				<div class="resident-violation-stat stat-review"><?php echo systemIcon('clock', 'stat-icon'); ?><div><small>Disputes Under Review</small><strong><?php echo (int)$violationStats['disputed']; ?></strong></div></div>
			</section>

            <?php renderViolationWorkspaceTabs(['current' => 'Violations', 'history' => 'History'], $activeTab, array_map('count', $violationGroups)); ?>
            <?php foreach ($violationGroups as $panelKey => $panelRecords):
                $activeFilter = $panelKey === $activeTab ? $requestedFilter : 'all';
                $panelFilters = $panelKey === 'history'
                    ? ['all' => 'All', 'paid' => 'Paid', 'waived' => 'Waived', 'warnings' => 'Warnings']
                    : ['all' => 'All', 'unpaid' => 'Outstanding', 'disputed' => 'Under Review'];
                if (!isset($panelFilters[$activeFilter])) $activeFilter = 'all';
                $panelStats = ['total' => count($panelRecords), 'outstanding' => 0, 'disputed' => 0, 'paid' => 0, 'waived' => 0, 'warnings' => 0];
                foreach ($panelRecords as $record) {
                    if (isset($panelStats[$record['status']])) $panelStats[$record['status']]++;
                    if (in_array($record['status'], ['unpaid', 'disputed'], true)) $panelStats['outstanding']++;
                    if ($record['penalty_type'] === 'warning') $panelStats['warnings']++;
                }
                $visibleViolations = residentViolationFilterRecords($panelRecords, $activeFilter, $violationSearch);
                $selectedViolation = null;
                foreach ($visibleViolations as $record) {
                    if ((int)$record['id'] === $selectedViolationId) { $selectedViolation = $record; break; }
                }
                $selectedViolation ??= $visibleViolations[0] ?? null;
                $detailId = $panelKey === $activeTab ? 'violationDetails' : 'violationDetails-' . $panelKey;
            ?>
            <section <?php echo violationWorkspacePanelAttributes($panelKey, $activeTab); ?> data-filter="<?php echo $activeFilter; ?>">
                <?php if (!$panelRecords): ?>
                    <div class="resident-violation-empty"><h2><?php echo $panelKey === 'history' ? 'No history yet' : 'No current violations'; ?></h2><p><?php echo $panelKey === 'history' ? 'Paid, waived and warning records will appear here.' : 'You have no outstanding fines or disputes under review.'; ?></p></div>
                <?php else: include __DIR__ . '/../includes/resident_violation_records.php'; endif; ?>
            </section>
            <?php endforeach; ?>
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
</body>
</html>
