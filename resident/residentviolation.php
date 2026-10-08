<?php
require_once '../config.php';

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
		header('Location: residentviolation.php');
		exit;
	} else {
		$errors[] = 'Could not submit your dispute. It may already be resolved.';
	}
}

$violations = getViolationsForUser($userId);
$statusLabels = ['warning_issued' => 'Warning', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'disputed' => 'Disputed', 'waived' => 'Waived'];
$activeFilter = $_GET['filter'] ?? 'all';
if (!in_array($activeFilter, ['all', 'unpaid', 'paid', 'waived'], true)) {
	$activeFilter = 'all';
}
$violationStats = ['total' => count($violations), 'unpaid' => 0, 'paid' => 0, 'waived' => 0, 'warnings' => 0, 'unpaid_amount' => 0.0];
foreach ($violations as $record) {
	if (isset($violationStats[$record['status']])) {
		$violationStats[$record['status']]++;
	}
	if ($record['penalty_type'] === 'warning') {
		$violationStats['warnings']++;
	}
	if (in_array($record['status'], ['unpaid', 'disputed'], true)) {
		$violationStats['unpaid_amount'] += (float)$record['fine_amount'];
	}
}
$visibleViolations = array_values(array_filter($violations, static function (array $record) use ($activeFilter): bool {
	return match ($activeFilter) {
		'unpaid' => in_array($record['status'], ['unpaid', 'disputed'], true),
		'paid' => $record['status'] === 'paid',
		'waived' => $record['status'] === 'waived',
		default => true,
	};
}));
$selectedViolationId = (int)($_GET['violation'] ?? 0);
$selectedViolation = null;
foreach ($visibleViolations as $record) {
	if ((int)$record['id'] === $selectedViolationId) {
		$selectedViolation = $record;
		break;
	}
}
$selectedViolation ??= $visibleViolations[0] ?? null;
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
	<title>Celandine Residences - Violations</title>
	<link rel="stylesheet" href="../resident.css?v=<?php echo (int)filemtime(__DIR__ . '/../resident.css'); ?>">
</head>
<body class="dashboard-page resident-violations-page">
	<div class="dash-layout">
		<aside class="sidebar" id="sidebar">
			<a href="dashboard.php" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
			<nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
		</aside>
		<div class="sidebar-overlay" id="sidebarOverlay"></div>

		<main class="dashboard-main">
			<header class="dash-header">
				<div class="dash-header-left">
					<button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
					<div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Violations</h1></div>
				</div>
				<div class="dash-header-right">
					<?php include '../notifications.php'; ?>
					<div class="profile-menu" id="profileMenu">
						<button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
						<div class="profile-dropdown" id="profileDropdown">
							<div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span></div></div>
							<a href="edit_profile.php" class="profile-dropdown-item"><?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile</a>
							<a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
						</div>
					</div>
				</div>
			</header>

			<?php if ($successMessage !== ''): ?><div class="alert success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
			<?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

			<section class="resident-violation-summary" aria-label="Violation summary">
				<div class="resident-violation-stat stat-total"><?php echo systemIconFromGlyph('⚠', 'stat-icon'); ?><div><small>Total Violations</small><strong><?php echo (int)$violationStats['total']; ?></strong></div></div>
				<div class="resident-violation-stat stat-unpaid"><?php echo systemIconFromGlyph('₱', 'stat-icon'); ?><div><small>Outstanding Fine Balance</small><strong>₱<?php echo number_format($violationStats['unpaid_amount'], 2); ?></strong></div></div>
				<div class="resident-violation-stat stat-paid"><?php echo systemIconFromGlyph('✓', 'stat-icon'); ?><div><small>Paid</small><strong><?php echo (int)$violationStats['paid']; ?></strong></div></div>
			</section>

			<?php if (!$violations): ?>
				<section class="resident-violation-empty"><span>✓</span><h2>No violations recorded</h2><p>Your account has no violation records at this time.</p></section>
			<?php else: ?>
				<div class="resident-violation-workspace">
					<section class="resident-violation-list-panel" aria-label="Your violations">
						<nav class="resident-violation-filters" aria-label="Filter violations">
							<?php foreach (['all' => 'All', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'waived' => 'Waived'] as $filterKey => $filterLabel): ?>
								<a class="resident-violation-filter<?php echo $activeFilter === $filterKey ? ' active' : ''; ?>" href="residentviolation.php?filter=<?php echo htmlspecialchars($filterKey); ?>" <?php echo $activeFilter === $filterKey ? 'aria-current="page"' : ''; ?>>
									<?php echo htmlspecialchars($filterLabel); ?><span><?php echo $filterKey === 'all' ? (int)$violationStats['total'] : (int)$violationStats[$filterKey]; ?></span>
								</a>
							<?php endforeach; ?>
						</nav>

						<?php if (!$visibleViolations): ?>
							<div class="resident-violation-filter-empty">No violations in this status.</div>
						<?php else: foreach ($visibleViolations as $violation):
							$violationIcon = $violationIcons[$violation['violation_type']] ?? 'violations';
							$isSelected = (int)$selectedViolation['id'] === (int)$violation['id'];
							$detailUrl = 'residentviolation.php?filter=' . rawurlencode($activeFilter) . '&violation=' . (int)$violation['id'] . '#violationDetails';
						?>
							<article class="resident-violation-card<?php echo $isSelected ? ' selected' : ''; ?>">
								<a class="resident-violation-card-main" href="<?php echo htmlspecialchars($detailUrl); ?>" aria-current="<?php echo $isSelected ? 'true' : 'false'; ?>">
									<span class="resident-violation-type-icon"><?php echo systemIcon($violationIcon, 'resident-violation-type-svg'); ?></span>
									<span class="resident-violation-card-copy">
										<strong><?php echo htmlspecialchars($violation['violation_type']); ?></strong>
										<span class="resident-violation-badges"><em class="penalty-badge penalty-<?php echo htmlspecialchars($violation['penalty_type']); ?>"><?php echo htmlspecialchars($violation['penalty_type'] === 'fine' ? 'Fine' : 'Warning'); ?></em><small><?php echo htmlspecialchars(date('M j, Y · g:i A', strtotime($violation['created_at']))); ?></small></span>
										<?php if (!empty($violation['location'])): ?><span class="resident-violation-location">⌖ <?php echo htmlspecialchars($violation['location']); ?></span><?php endif; ?>
										<?php if (!empty($violation['description'])): ?><span class="resident-violation-description"><?php echo htmlspecialchars($violation['description']); ?></span><?php endif; ?>
									</span>
									<span class="resident-violation-card-end">
										<?php if ($violation['penalty_type'] === 'fine'): ?><strong class="resident-violation-fine">₱<?php echo number_format((float)$violation['fine_amount'], 2); ?><small>Fine</small></strong><?php endif; ?>
										<em class="violation-status status-<?php echo htmlspecialchars($violation['status']); ?>"><?php echo htmlspecialchars($statusLabels[$violation['status']] ?? $violation['status']); ?></em>
									</span>
								</a>
								<?php if (!empty($violation['evidence_path'])): ?>
									<div class="resident-violation-card-evidence">
										<a href="../violation_evidence.php?id=<?php echo (int)$violation['id']; ?>&amp;inline=1" target="_blank" rel="noopener" class="resident-violation-thumb"><img src="../violation_evidence.php?id=<?php echo (int)$violation['id']; ?>&amp;inline=1" alt="Evidence for <?php echo htmlspecialchars($violation['violation_type']); ?>"></a>
										<a class="resident-violation-evidence-link" href="../violation_evidence.php?id=<?php echo (int)$violation['id']; ?>&amp;inline=1" target="_blank" rel="noopener">View Evidence</a>
									</div>
								<?php endif; ?>
							</article>
						<?php endforeach; endif; ?>
					</section>

					<?php if ($selectedViolation):
						$selectedIcon = $violationIcons[$selectedViolation['violation_type']] ?? 'violations';
					?>
						<aside class="resident-violation-detail" id="violationDetails" aria-label="Violation details">
							<div class="resident-violation-detail-head">
								<span class="resident-violation-type-icon"><?php echo systemIcon($selectedIcon, 'resident-violation-type-svg'); ?></span>
								<div><h2><?php echo htmlspecialchars($selectedViolation['violation_type']); ?></h2><div class="resident-violation-badges"><em class="penalty-badge penalty-<?php echo htmlspecialchars($selectedViolation['penalty_type']); ?>"><?php echo htmlspecialchars($selectedViolation['penalty_type'] === 'fine' ? 'Fine' : 'Warning'); ?></em><em class="violation-status status-<?php echo htmlspecialchars($selectedViolation['status']); ?>"><?php echo htmlspecialchars($statusLabels[$selectedViolation['status']] ?? $selectedViolation['status']); ?></em></div></div>
								<?php if ($selectedViolation['penalty_type'] === 'fine'): ?><strong class="resident-violation-detail-amount">₱<?php echo number_format((float)$selectedViolation['fine_amount'], 2); ?><small>Fine amount</small></strong><?php endif; ?>
							</div>
							<div class="resident-violation-detail-grid">
								<div><small>Date</small><strong><?php echo htmlspecialchars(date('F j, Y', strtotime($selectedViolation['created_at']))); ?></strong></div>
								<div><small>Time</small><strong><?php echo htmlspecialchars(date('g:i A', strtotime($selectedViolation['created_at']))); ?></strong></div>
								<div><small>Violation type</small><strong><?php echo htmlspecialchars($selectedViolation['violation_type']); ?></strong></div>
								<div><small>Reported by</small><strong><?php echo htmlspecialchars($selectedViolation['issued_by_name'] ?? 'Management'); ?></strong></div>
								<div><small>Unit number</small><strong><?php echo htmlspecialchars($_SESSION['unit_number'] ?? 'Not assigned'); ?></strong></div>
								<?php if (!empty($selectedViolation['location'])): ?><div><small>Location</small><strong><?php echo htmlspecialchars($selectedViolation['location']); ?></strong></div><?php endif; ?>
								<?php if (!empty($selectedViolation['due_date'])): ?><div><small>Fine due date</small><strong><?php echo htmlspecialchars(date('F j, Y', strtotime($selectedViolation['due_date']))); ?></strong></div><?php endif; ?>
							</div>
							<?php if (!empty($selectedViolation['description'])): ?><section class="resident-violation-detail-section"><h3>Description</h3><p><?php echo nl2br(htmlspecialchars($selectedViolation['description'])); ?></p></section><?php endif; ?>
							<?php if (!empty($selectedViolation['evidence_path'])): ?>
								<section class="resident-violation-detail-section"><h3>Evidence</h3><a class="resident-violation-detail-image" href="../violation_evidence.php?id=<?php echo (int)$selectedViolation['id']; ?>&amp;inline=1" target="_blank" rel="noopener"><img src="../violation_evidence.php?id=<?php echo (int)$selectedViolation['id']; ?>&amp;inline=1" alt="Evidence for <?php echo htmlspecialchars($selectedViolation['violation_type']); ?>"></a><a class="resident-violation-download" href="../violation_evidence.php?id=<?php echo (int)$selectedViolation['id']; ?>&amp;download=1" download>Download Evidence</a></section>
							<?php endif; ?>
							<?php if ($selectedViolation['status'] === 'unpaid' && $selectedViolation['penalty_type'] === 'fine'): ?>
								<a class="resident-violation-primary-action" href="payments.php">Pay Fine <span>→</span></a>
								<form method="post" action="residentviolation.php" class="resident-violation-dispute-form"><?php echo workflowCsrfField(); ?><input type="hidden" name="form_action" value="dispute_violation"><input type="hidden" name="violation_id" value="<?php echo (int)$selectedViolation['id']; ?>"><label class="field-label" for="dispute_reason_<?php echo (int)$selectedViolation['id']; ?>">Dispute this fine</label><input id="dispute_reason_<?php echo (int)$selectedViolation['id']; ?>" type="text" name="dispute_reason" maxlength="1000" placeholder="Explain your reason..." required><button type="submit">Submit Dispute</button></form>
							<?php elseif ($selectedViolation['status'] === 'disputed' && !empty($selectedViolation['dispute_reason'])): ?>
								<section class="resident-violation-dispute-note"><strong>Dispute submitted</strong><p><?php echo htmlspecialchars($selectedViolation['dispute_reason']); ?></p><small>Management is reviewing your dispute.</small></section>
							<?php elseif ($selectedViolation['status'] === 'paid' && !empty($selectedViolation['payment_id'])): ?>
								<a class="resident-violation-primary-action" href="payment_receipt.php?id=<?php echo (int)$selectedViolation['payment_id']; ?>">View Receipt <span>↗</span></a>
							<?php endif; ?>
						</aside>
					<?php endif; ?>
				</div>
			<?php endif; ?>
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
