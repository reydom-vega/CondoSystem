<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('violations.manage');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireWorkflowCsrf();
ensureViolationsTable($connection);
$fineRates = getViolationFineRates();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'issue') {
        requireCapability('violations.issue');
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $violationType = trim($_POST['violation_type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $penaltyType = 'fine';
        $fineAmount = (float)($_POST['fine_amount'] ?? 0);
        if (isset($fineRates[$violationType])) {
            $fineAmount = (float)$fineRates[$violationType];
        }
        $dueDate = trim($_POST['due_date'] ?? '') ?: null;
        $evidenceUpload = $_FILES['evidence_photo'] ?? null;
        $evidenceError = '';
        $evidenceExtension = null;

        if ($evidenceUpload && $evidenceUpload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($evidenceUpload['error'] !== UPLOAD_ERR_OK) {
                $evidenceError = 'The evidence photo could not be uploaded.';
            } elseif ($evidenceUpload['size'] > 5 * 1024 * 1024) {
                $evidenceError = 'The evidence photo must be 5 MB or smaller.';
            } else {
                $imageInfo = @getimagesize($evidenceUpload['tmp_name']);
                $extensionByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $mimeType = $imageInfo['mime'] ?? '';
                if (!$imageInfo || !isset($extensionByMime[$mimeType])) {
                    $evidenceError = 'Upload evidence as a JPG, PNG, or WebP image.';
                } else {
                    $evidenceExtension = $extensionByMime[$mimeType];
                }
            }
        }

        if ($targetUserId <= 0 || $violationType === '') {
            $errors[] = 'Please choose a resident and a violation type.';
        } elseif ($fineAmount <= 0) {
            $errors[] = 'Enter a fine amount greater than zero.';
        } elseif ($evidenceError !== '') {
            $errors[] = $evidenceError;
        } else {
            $evidencePath = null;
            if ($evidenceExtension !== null) {
                $evidenceDirectory = __DIR__ . '/../private_uploads/violation_evidence';
                if (!is_dir($evidenceDirectory) && !mkdir($evidenceDirectory, 0750, true) && !is_dir($evidenceDirectory)) {
                    $errors[] = 'Could not prepare secure evidence storage.';
                } else {
                    $evidencePath = bin2hex(random_bytes(20)) . '.' . $evidenceExtension;
                    if (!move_uploaded_file($evidenceUpload['tmp_name'], $evidenceDirectory . '/' . $evidencePath)) {
                        $errors[] = 'Could not save the evidence photo.';
                        $evidencePath = null;
                    }
                }
            }

            if (empty($errors)) {
                $violationId = issueViolation($targetUserId, $violationType, $description, $penaltyType, $fineAmount, $dueDate, (int)$_SESSION['user_id'], $location ?: null, $evidencePath);
                if ($violationId) {
                    logAudit('issue', 'violation', $violationId, $violationType . ' issued to user #' . $targetUserId . ' with fine ₱' . number_format($fineAmount, 2));
                    $notifyResult = notifyResidentOfViolation($violationId);
                    $success = 'Violation fine recorded and added to the resident\'s bill.';
                    $success .= ' Email notice: ' . ($notifyResult['email_queued'] ? 'queued' : 'unavailable')
                        . '. SMS notice: ' . ($notifyResult['sms_queued'] ? 'queued' : 'unavailable') . '.';
                } else {
                    if ($evidencePath !== null) {
                        @unlink($evidenceDirectory . '/' . $evidencePath);
                    }
                    $errors[] = 'Could not record the violation.';
                }
            } else {
                if ($evidencePath !== null) {
                    @unlink($evidenceDirectory . '/' . $evidencePath);
                }
            }
        }
    } elseif ($action === 'resolve') {
        requireCapability('violations.review');
        $violationId = (int)($_POST['violation_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        if ($violationId > 0 && in_array($decision, ['waive', 'reject_dispute'], true) && resolveViolation($violationId, $decision)) {
            logAudit($decision === 'waive' ? 'waive' : 'reject', 'violation', $violationId, $decision === 'waive' ? 'Fine waived' : 'Dispute rejected, fine upheld');
            $success = 'Violation updated.';
        } else {
            $errors[] = 'Could not update that violation.';
        }
    }
}

$residentsResult = $connection->query("SELECT id, full_name, unit_number FROM users WHERE role = 'resident' AND is_verified = 1 ORDER BY unit_number ASC");
$residents = $residentsResult ? $residentsResult->fetch_all(MYSQLI_ASSOC) : [];
$violations = getAllViolations($connection);
$statusLabels = ['warning_issued' => 'Warning', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'disputed' => 'Disputed', 'waived' => 'Waived'];
$allViolationCount = count($violations);
$violationSummary = [
    'open_count' => 0,
    'open_amount' => 0.0,
    'disputed_count' => 0,
    'paid_amount' => 0.0,
];
foreach ($violations as $violation) {
    if (in_array($violation['status'], ['unpaid', 'disputed'], true)) {
        $violationSummary['open_count']++;
        $violationSummary['open_amount'] += (float)$violation['fine_amount'];
    }
    if ($violation['status'] === 'disputed') {
        $violationSummary['disputed_count']++;
    } elseif ($violation['status'] === 'paid') {
        $violationSummary['paid_amount'] += (float)$violation['fine_amount'];
    }
}
$violationSearch = trim($_GET['search'] ?? '');
$violationStatus = $_GET['status'] ?? 'all';
if (!isset($statusLabels[$violationStatus])) {
    $violationStatus = 'all';
}
if ($violationSearch !== '' || $violationStatus !== 'all') {
    $violations = array_values(array_filter($violations, static function (array $violation) use ($violationSearch, $violationStatus, $statusLabels): bool {
        if ($violationStatus !== 'all' && $violation['status'] !== $violationStatus) {
            return false;
        }
        if ($violationSearch === '') {
            return true;
        }

        $searchableText = implode(' ', [
            (string)($violation['id'] ?? ''),
            (string)($violation['full_name'] ?? ''),
            (string)($violation['unit_number'] ?? ''),
            (string)($violation['violation_type'] ?? ''),
            (string)($violation['description'] ?? ''),
            (string)($violation['location'] ?? ''),
            (string)($violation['admin_remarks'] ?? ''),
            (string)($violation['dispute_reason'] ?? ''),
            (string)($violation['payment_id'] ?? ''),
            !empty($violation['payment_id']) ? 'DUES-' . (int)$violation['payment_id'] : '',
            (string)($statusLabels[$violation['status']] ?? $violation['status'] ?? ''),
        ]);

        return stripos($searchableText, $violationSearch) !== false;
    }));
}
$commonTypes = array_keys($fineRates);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo filemtime(__DIR__ . '/../styles.css'); ?>">
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
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Violation Management</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <section class="unit-management-panel violation-create-panel">
                <h3 class="section-title">Issue a Violation</h3>
                <form method="post" id="violationForm" enctype="multipart/form-data" data-confirm="Issue this violation and add its fine to the resident's bill?" data-confirm-title="Issue violation" data-confirm-action="Issue violation"><?php echo workflowCsrfField(); ?>
                    <input type="hidden" name="action" value="issue">
                    <div class="violation-form-grid">
                        <div class="violation-field">
                            <label>Resident</label>
                            <select name="user_id" required>
                                <option value="">Select resident…</option>
                                <?php foreach ($residents as $resident): ?>
                                    <option value="<?php echo (int)$resident['id']; ?>">Unit <?php echo htmlspecialchars($resident['unit_number']); ?> — <?php echo htmlspecialchars($resident['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="violation-field">
                            <label>Violation Type</label>
                            <input type="text" name="violation_type" list="commonTypes" placeholder="e.g. Unauthorized Parking" required>
                            <datalist id="commonTypes"><?php foreach ($commonTypes as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php endforeach; ?></datalist>
                        </div>
                        <div class="violation-field" id="dueDateField">
                            <label>Fine Due Date</label>
                            <input type="date" name="due_date" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div class="violation-field violation-description">
                        <label>Description / Evidence Notes</label>
                        <textarea name="description" rows="2" placeholder="What happened, when, any evidence notes..."></textarea>
                    </div>

                    <div class="violation-form-grid violation-evidence-grid">
                        <div class="violation-field">
                            <label>Location</label>
                            <input type="text" name="location" maxlength="255" placeholder="e.g. Lobby, parking level, unit floor">
                        </div>
                        <div class="violation-field">
                            <label>Evidence Photo</label>
                            <div class="violation-evidence-control">
                                <input type="file" name="evidence_photo" accept="image/jpeg,image/png,image/webp">
                                <button type="submit">Issue Violation</button>
                            </div>
                            <small>JPG, PNG, or WebP, up to 5 MB</small>
                        </div>
                    </div>

                    <input type="hidden" name="penalty_type" value="fine">
                    <div class="violation-field fine-field" id="fineAmountField">
                        <label>Fine Amount (₱)</label>
                        <input type="number" step="0.01" min="0.01" name="fine_amount" placeholder="0.00" required>
                        <small>Configured violation types use their listed rate. Enter an amount for a custom type.</small>
                    </div>

                    <div class="violation-rate-wrap">
                        <h4>Fine Rates</h4>
                        <table class="unit-table violation-rate-table">
                            <thead><tr><th>Violation</th><th>Fine</th></tr></thead>
                            <tbody><?php foreach ($fineRates as $type => $rate): ?>
                                <tr><td><?php echo htmlspecialchars($type); ?></td><td>₱<?php echo number_format($rate, 2); ?></td></tr>
                            <?php endforeach; ?></tbody>
                        </table>
                    </div>

                </form>
            </section>

            <section class="unit-management-panel violation-history-panel">
                <div class="violation-history-heading">
                    <h3 class="section-title">Violation History</h3>
                    <span class="violation-search-count"><?php echo count($violations); ?> of <?php echo $allViolationCount; ?> records</span>
                </div>
                <div class="violation-history-summary" aria-label="Violation history summary">
                    <div class="violation-history-stat"><span>All records</span><strong><?php echo $allViolationCount; ?></strong></div>
                    <div class="violation-history-stat violation-history-stat-open"><span>Outstanding fines</span><strong><?php echo $violationSummary['open_count']; ?></strong><small>₱<?php echo number_format($violationSummary['open_amount'], 2); ?> due</small></div>
                    <div class="violation-history-stat violation-history-stat-disputed"><span>Disputed</span><strong><?php echo $violationSummary['disputed_count']; ?></strong></div>
                    <div class="violation-history-stat violation-history-stat-paid"><span>Paid fines</span><strong>₱<?php echo number_format($violationSummary['paid_amount'], 2); ?></strong></div>
                </div>
                <form class="violation-search" method="get" action="violations.php">
                    <div class="violation-search-field">
                        <label for="violationSearch">Search records</label>
                        <input id="violationSearch" type="search" name="search" value="<?php echo htmlspecialchars($violationSearch); ?>" placeholder="Resident, unit, type, location, ID, or payment reference">
                    </div>
                    <div class="violation-search-field">
                        <label for="violationStatus">Status</label>
                        <select id="violationStatus" name="status">
                            <option value="all" <?php echo $violationStatus === 'all' ? 'selected' : ''; ?>>All statuses</option>
                            <?php foreach ($statusLabels as $status => $label): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $violationStatus === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Search</button>
                    <?php if ($violationSearch !== '' || $violationStatus !== 'all'): ?><a href="violations.php" class="violation-search-reset">Clear</a><?php endif; ?>
                </form>
                <?php if (empty($violations)): ?>
                    <p class="bookings-empty"><?php echo $violationSearch !== '' ? 'No violations match your search.' : 'No violations recorded yet.'; ?></p>
                <?php else: ?>
                    <div class="unit-table-wrap violation-table-wrap">
                        <table class="unit-table violation-table">
                            <thead><tr>
                                <th>Violation ID</th><th>Resident</th><th>Unit Number</th><th>Violation Type</th><th>Description</th><th>Date/Time</th><th>Location</th><th>Evidence/Photo</th><th>Fine Amount</th><th>Due Date</th><th>Status</th><th>Payment Reference</th><th>Admin Remarks</th><th>Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($violations as $violation): ?>
                                <tr>
                                    <td>#<?php echo (int)$violation['id']; ?></td>
                                    <td><?php echo htmlspecialchars($violation['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($violation['unit_number']); ?></td>
                                    <td><?php echo htmlspecialchars($violation['violation_type']); ?></td>
                                    <td><?php echo !empty($violation['description']) ? nl2br(htmlspecialchars($violation['description'])) : '—'; ?><?php if ($violation['status'] === 'disputed' && !empty($violation['dispute_reason'])): ?><small class="violation-table-note">Dispute: <?php echo htmlspecialchars($violation['dispute_reason']); ?></small><?php endif; ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($violation['created_at']))); ?></td>
                                    <td><?php echo htmlspecialchars($violation['location'] ?? '—'); ?></td>
                                    <td><?php if (!empty($violation['evidence_path'])): ?><a href="../violation_evidence.php?id=<?php echo (int)$violation['id']; ?>" target="_blank" rel="noopener">View photo</a><?php else: ?>—<?php endif; ?></td>
                                    <td><?php echo $violation['penalty_type'] === 'fine' ? '₱' . number_format((float)$violation['fine_amount'], 2) : '—'; ?></td>
                                    <td><?php echo !empty($violation['due_date']) ? htmlspecialchars(date('M j, Y', strtotime($violation['due_date']))) : '—'; ?></td>
                                    <td><span class="status-pill status-<?php echo htmlspecialchars($violation['status']); ?>"><?php echo htmlspecialchars($statusLabels[$violation['status']] ?? $violation['status']); ?></span></td>
                                    <td><?php echo !empty($violation['payment_id']) ? 'DUES-' . (int)$violation['payment_id'] : '—'; ?></td>
                                    <td><?php echo !empty($violation['admin_remarks']) ? nl2br(htmlspecialchars($violation['admin_remarks'])) : '—'; ?></td>
                                    <td>
                                        <?php if (canAccess('violations.review') && in_array($violation['status'], ['unpaid', 'disputed'], true)): ?>
                                            <div class="violation-actions">
                                            <?php if ($violation['status'] === 'disputed'): ?>
                                                <form method="post" class="violation-action-form" data-confirm="Uphold the fine for this disputed violation? The fine will remain due." data-confirm-title="Uphold violation" data-confirm-action="Uphold"><?php echo workflowCsrfField(); ?>
                                                <input type="hidden" name="action" value="resolve">
                                                <input type="hidden" name="violation_id" value="<?php echo (int)$violation['id']; ?>">
                                                    <button type="submit" name="decision" value="reject_dispute" class="btn-small btn-reject">Uphold</button>
                                                </form>
                                            <?php endif; ?>
                                                <form method="post" class="violation-action-form" data-confirm="Waive this violation fine? The charge will be removed from the resident's bill." data-confirm-title="Waive violation fine" data-confirm-action="Waive fine"><?php echo workflowCsrfField(); ?>
                                                    <input type="hidden" name="action" value="resolve">
                                                    <input type="hidden" name="violation_id" value="<?php echo (int)$violation['id']; ?>">
                                                    <button type="submit" name="decision" value="waive" class="btn-small btn-approve">Waive</button>
                                                </form>
                                            </div>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="../js/confirmation-ui.js"></script>
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

        const violationTypeInput = document.querySelector('input[name="violation_type"]');
        const fineAmountInput = document.querySelector('input[name="fine_amount"]');
        const violationFineRates = <?php echo json_encode($fineRates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function syncFineRate() {
            const rate = violationFineRates[violationTypeInput.value.trim()];
            if (rate !== undefined) {
                fineAmountInput.value = Number(rate).toFixed(2);
                fineAmountInput.readOnly = true;
                fineAmountInput.dataset.configuredRate = 'true';
            } else {
                if (fineAmountInput.dataset.configuredRate === 'true') {
                    fineAmountInput.value = '';
                }
                fineAmountInput.readOnly = false;
                delete fineAmountInput.dataset.configuredRate;
            }
        }
        violationTypeInput.addEventListener('input', syncFineRate);
        violationTypeInput.addEventListener('change', syncFineRate);
        syncFineRate();
    </script>
</body>
</html>
