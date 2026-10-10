<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if (isAdmin()) {
    redirect(isSecurity() ? 'security/security_dashboard.php' : (isMaintenance() ? 'maintenance/maintenance_dashboard.php' : (isTreasurer() ? 'treasurer/treasurer_dashboard.php' : 'admin/admin_dashboard.php')));
}

if (isApproved()) {
    redirect('resident/dashboard.php');
}

$connection = connectDb();
$userId = (int)$_SESSION['user_id'];
$accountStmt = $connection->prepare('SELECT full_name, username, email, contact_number, unit_number, status, rejection_reason, account_type, session_version FROM users WHERE id = ? AND role = \'resident\' LIMIT 1');
$accountStmt->bind_param('i', $userId);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();

if (!$account) {
    redirect('logout.php');
}

$resubmitErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireWorkflowCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account['status'] === 'rejected' && isset($_POST['resubmit_application'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $unitNumber = normalizeUnitNumber($_POST['unit_number'] ?? '');

    if ($fullName === '' || $username === '' || $email === '' || $contactNumber === '' || $unitNumber === '') {
        $resubmitErrors[] = 'Please complete all fields before resubmitting.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $resubmitErrors[] = 'Username must be 3-20 characters and contain only letters, numbers, or underscores.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $resubmitErrors[] = 'Please enter a valid email address.';
    }
    if (!preg_match('/^[0-9]{11}$/', $contactNumber)) {
        $resubmitErrors[] = 'Contact number must be exactly 11 digits.';
    }
    if (strlen($fullName) > 100 || strlen($email) > 100) $resubmitErrors[] = 'Name and email must be 100 characters or shorter.';
    $inventoryUnits = array_map(static fn(array $unit): string => normalizeUnitNumber((string)($unit['unit_number'] ?? '')), loadUnitInventory());
    if (!in_array($unitNumber, $inventoryUnits, true)) $resubmitErrors[] = 'Please enter a unit number from the condominium inventory.';

    if (empty($resubmitErrors)) {
        $duplicateStmt = $connection->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
        $duplicateStmt->bind_param('ssi', $username, $email, $userId);
        $duplicateStmt->execute();
        $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
        $duplicateStmt->close();

        if ($duplicateExists) {
            $resubmitErrors[] = 'That username or email is already registered to another account.';
        } else {
            $expectedVersion = (int)$account['session_version'];
            $resubmitStmt = $connection->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, contact_number = ?, unit_number = ?, status = 'pending', rejection_reason = NULL, unit_owner_id = NULL, phone_verified = 0, phone_otp = NULL, phone_otp_expires = NULL, phone_otp_attempts = 0, phone_otp_sent_at = NULL, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role = 'resident' AND status = 'rejected' AND session_version = ?");
            $resubmitStmt->bind_param('sssssii', $fullName, $username, $email, $contactNumber, $unitNumber, $userId, $expectedVersion);
            $resubmitted = $resubmitStmt->execute() && $resubmitStmt->affected_rows === 1;
            $resubmitStmt->close();

            if ($resubmitted) {
                $_SESSION['username'] = $username;
                $_SESSION['unit_number'] = $unitNumber;
                $_SESSION['unit_owner_id'] = null;
                $_SESSION['session_version'] = $expectedVersion + 1;
                logAudit('resubmit', 'user', $userId, 'Resident resubmitted registration for admin review');
                setFlash('success', 'Your updated application has been resubmitted for admin review.');
                redirect('signuppending.php');
            }

            $resubmitErrors[] = 'Your application could not be resubmitted. Please try again.';
        }
    }

    $account['full_name'] = $fullName;
    $account['username'] = $username;
    $account['email'] = $email;
    $account['contact_number'] = $contactNumber;
    $account['unit_number'] = $unitNumber;
}

$isRejected = $account['status'] === 'rejected';
$pageFlash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isRejected ? 'Application Rejected' : 'Account Pending Approval'; ?> | The Celandine Homes</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="rejection.css?v=<?php echo filemtime(__DIR__ . '/rejection.css'); ?>">

    <?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="assets/css/signup-status.css?v=<?php echo filemtime(__DIR__ . '/assets/css/signup-status.css'); ?>">
</head>
<body class="portal-ui portal-account-page signup-status-page">
    <div class="status-shell">
        <main class="pending-card<?php echo $isRejected ? ' rejected-card' : ''; ?>" aria-labelledby="signupStatusTitle">
            <div class="signup-status-brand">
                <?php echo systemIcon('dashboard'); ?>
                <span>The Celandine Homes<small>Resident registration</small></span>
            </div>
            <?php if ($isRejected): ?>
                <header class="signup-status-header">
                    <span class="signup-status-badge signup-status-badge--rejected"><?php echo systemIcon('close'); ?>Application rejected</span>
                    <h1 id="signupStatusTitle" class="status-heading">Update your application</h1>
                    <p class="rejected-subtitle">Please review the reason and update your information.</p>
                </header>
                <p class="status-message">Your registration was reviewed and could not be approved.</p>
                <div class="rejection-reason"><strong>Reason provided by Admin:</strong><br><?php echo nl2br(htmlspecialchars($account['rejection_reason'] ?: 'No reason was provided. Please contact your Property Manager.')); ?></div>
                <p class="resubmit-intro">Correct any information below, then send your application back for review.</p>

                <?php if ($pageFlash): ?>
                    <div class="resubmit-notice" role="status"><?php echo htmlspecialchars($pageFlash['message']); ?></div>
                <?php endif; ?>
                <?php if (!empty($resubmitErrors)): ?>
                    <div class="resubmit-errors" role="alert">
                        <?php foreach ($resubmitErrors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="signuppending.php" class="resubmit-form">
                    <?php echo workflowCsrfField(); ?>
                    <div class="resubmit-field">
                        <label for="full_name">Full name</label>
                        <div class="input-wrap">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                            <input id="full_name" name="full_name" placeholder="Full Name" value="<?php echo htmlspecialchars($account['full_name']); ?>" required>
                        </div>
                    </div>
                    <div class="resubmit-field">
                        <label for="username">Username</label>
                        <div class="input-wrap">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
                            <input id="username" name="username" placeholder="Username" value="<?php echo htmlspecialchars($account['username']); ?>" required>
                        </div>
                    </div>
                    <div class="resubmit-field">
                        <label for="email">Email</label>
                        <div class="input-wrap">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 6-10 7L2 6"></path></svg></span>
                            <input id="email" type="email" name="email" placeholder="Email address" value="<?php echo htmlspecialchars($account['email']); ?>" required>
                        </div>
                    </div>
                    <div class="resubmit-field">
                        <label for="contact_number">Contact number</label>
                        <div class="input-wrap">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg></span>
                            <input id="contact_number" type="tel" name="contact_number" placeholder="Contact Number (ex. 09171234567)" inputmode="numeric" maxlength="11" value="<?php echo htmlspecialchars($account['contact_number']); ?>" required>
                        </div>
                    </div>
                    <div class="resubmit-field resubmit-field-wide">
                        <label for="unit_number">Unit number</label>
                        <div class="input-wrap">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9z"></path><path d="M8 9h8M8 13h8"></path></svg></span>
                            <input id="unit_number" name="unit_number" placeholder="Unit Number (ex. 1001)" value="<?php echo htmlspecialchars($account['unit_number'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="resubmit-actions resubmit-field-wide">
                        <button type="submit" name="resubmit_application" value="1" class="resubmit-submit">Resubmit application</button>
                        <a class="resubmit-logout service-btn service-btn-secondary" href="logout.php">Log out</a>
                    </div>
                </form>
            <?php else: ?>
                <header class="signup-status-header">
                    <span class="signup-status-badge"><?php echo systemIcon('clock'); ?>Pending approval</span>
                    <h1 id="signupStatusTitle" class="status-heading">Registration submitted</h1>
                    <p class="status-message">Thank you for registering. Management will review your application before you can access your account.</p>
                </header>
                <?php if ($pageFlash): ?>
                    <div class="signup-status-notice" role="status"><?php echo systemIcon('check-circle'); ?><p><?php echo htmlspecialchars($pageFlash['message']); ?></p></div>
                <?php endif; ?>
                <section class="signup-review" aria-labelledby="reviewStepsTitle">
                    <h2 id="reviewStepsTitle">What happens next</h2>
                    <ol class="signup-review-steps">
                        <li class="signup-review-step signup-review-step--complete">
                            <?php echo systemIcon('check', 'signup-review-marker'); ?>
                            <div><h3>Application received</h3></div>
                        </li>
                        <li class="signup-review-step signup-review-step--current" aria-current="step">
                            <?php echo systemIcon('clock', 'signup-review-marker'); ?>
                            <div><h3>Management review</h3></div>
                        </li>
                        <li class="signup-review-step">
                            <?php echo systemIcon('lock', 'signup-review-marker'); ?>
                            <div><h3>Account access</h3></div>
                        </li>
                    </ol>
                </section>
                <?php if (in_array(residentAccountKind($account['account_type']), ['tenant', 'occupant'], true)): ?>
                <aside class="signup-status-info">
                    <?php echo systemIcon('users'); ?>
                    <div><h2>Occupancy confirmation</h2><p>Management must confirm your occupancy and link your account to an approved unit owner. You can view unit bills after approval; the unit owner handles payment.</p></div>
                </aside>
                <?php endif; ?>
                <aside class="signup-status-info">
                    <?php echo systemIcon('mail'); ?>
                    <div><h2>Watch your inbox</h2><p>You'll receive an email confirmation once your account is approved. Check your inbox regularly for updates.</p></div>
                </aside>
                <div class="signup-status-help"><?php echo systemIcon('help'); ?><p>Need an update? Contact your Property Manager for your application status.</p></div>
                <div class="status-button-wrap">
                    <a class="service-btn status-button" href="logout.php">Understood<?php echo systemIcon('arrow-right'); ?></a>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
