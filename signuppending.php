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
$accountStmt = $connection->prepare('SELECT full_name, username, email, contact_number, unit_number, status, rejection_reason FROM users WHERE id = ? AND role = \'resident\' LIMIT 1');
$accountStmt->bind_param('i', $userId);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();

if (!$account) {
    redirect('logout.php');
}

$resubmitErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account['status'] === 'rejected' && isset($_POST['resubmit_application'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $unitNumber = trim($_POST['unit_number'] ?? '');

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

    if (empty($resubmitErrors)) {
        $duplicateStmt = $connection->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
        $duplicateStmt->bind_param('ssi', $username, $email, $userId);
        $duplicateStmt->execute();
        $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
        $duplicateStmt->close();

        if ($duplicateExists) {
            $resubmitErrors[] = 'That username or email is already registered to another account.';
        } else {
            $resubmitStmt = $connection->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, contact_number = ?, unit_number = ?, status = 'pending', rejection_reason = NULL WHERE id = ? AND status = 'rejected'");
            $resubmitStmt->bind_param('sssssi', $fullName, $username, $email, $contactNumber, $unitNumber, $userId);
            $resubmitted = $resubmitStmt->execute() && $resubmitStmt->affected_rows === 1;
            $resubmitStmt->close();

            if ($resubmitted) {
                $_SESSION['username'] = $username;
                $_SESSION['unit_number'] = $unitNumber;
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
    <title>Account Pending Approval</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="rejection.css?v=<?php echo filemtime(__DIR__ . '/rejection.css'); ?>">
    <style>
        :root {
            --status-blue: #0d4d8d;
            --status-blue-dark: #0a3d73;
            --status-orange: #f59e0b;
            --status-text: #0f172a;
            --status-muted: #334155;
            --status-light: #edf1f5;
            --status-button: #0b5ea8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, rgba(10,15,29,0.88), rgba(15,23,42,0.9));
            color: var(--status-text);
        }

        .status-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 36px 0;
        }

        .pending-card {
            width: min(92vw, 620px);
            padding: 30px 34px 24px;
            border-radius: 16px;
            background: rgba(10, 27, 40, 0.88);
            border: 1px solid rgba(255,255,255,0.05);
            box-shadow: 0 28px 55px rgba(0,0,0,0.38);
            text-align: center;
        }

        .pending-icon-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 144px;
            height: 144px;
            margin: 0 auto 20px;
        }

        .pending-clock {
            width: 116px;
            height: 116px;
            color: var(--status-orange);
            stroke: currentColor;
            stroke-width: 5;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .pending-clock .clock-hand {
            stroke-width: 6;
            stroke-linecap: round;
        }

        .status-heading {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            letter-spacing: -0.02em;
            font-weight: 700;
            color: #f8fafc;
        }

        .status-message {
            margin: 16px auto 0;
            color: #dbe7f6;
            font-size: 14px;
            line-height: 1.5;
            font-weight: 400;
            max-width: 520px;
        }

        .status-update {
            margin: 20px auto 16px;
            max-width: 560px;
            font-size: 14px;
            line-height: 1.5;
            color: #e2e8f0;
            font-weight: 700;
            font-style: italic;
        }

        .status-button-wrap {
            margin-top: 12px;
        }

        .status-button {
            display: block;
            width: auto;
            min-width: 150px;
            margin: 0 auto;
            border: none;
            border-radius: 8px;
            background: var(--btn-orange, #d97706);
            color: #fff;
            padding: 11px 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.2s ease;
            box-shadow: 0 4px 10px rgba(217, 119, 6, 0.2);
        }

        .status-button:hover {
            background: var(--btn-orange-hover, #b45309);
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(217, 119, 6, 0.25);
        }

        @media (max-width: 560px) {
            .pending-card {
                width: min(92vw, 560px);
                padding: 28px 18px 20px;
            }

            .pending-icon-wrap {
                width: 120px;
                height: 120px;
            }

            .pending-clock {
                width: 96px;
                height: 96px;
            }
        }
    </style>
</head>
<body>
    <div class="status-shell">
        <div class="pending-card<?php echo $isRejected ? ' rejected-card' : ''; ?>">
            <?php if (!$isRejected): ?>
            <div class="pending-icon-wrap" aria-hidden="true">
                <svg class="pending-clock" viewBox="0 0 64 64" role="img" aria-label="Pending approval clock icon">
                    <circle cx="32" cy="32" r="23"></circle>
                    <path class="clock-hand" d="M32 32 L32 18"></path>
                    <path class="clock-hand" d="M32 32 L40 37"></path>
                </svg>
            </div>
            <?php endif; ?>

            <?php if ($isRejected): ?>
                <div class="rejected-heading-row">
                    <span class="rejected-mark" aria-hidden="true">!</span>
                    <div>
                        <h1 class="status-heading">Application Rejected</h1>
                        <p class="rejected-subtitle">Please review the reason and update your information.</p>
                    </div>
                </div>
                <p class="status-message">Your registration was reviewed and could not be approved.</p>
                <div class="rejection-reason"><strong>Reason provided by Admin:</strong><br><?php echo nl2br(htmlspecialchars($account['rejection_reason'] ?: 'No reason was provided. Please contact your Property Manager.')); ?></div>
                <p class="resubmit-intro">Correct any information below, then send your application back for review.</p>

                <?php if ($pageFlash): ?>
                    <div class="resubmit-notice"><?php echo htmlspecialchars($pageFlash['message']); ?></div>
                <?php endif; ?>
                <?php if (!empty($resubmitErrors)): ?>
                    <div class="resubmit-errors" role="alert">
                        <?php foreach ($resubmitErrors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="signuppending.php" class="resubmit-form">
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
                        <a class="resubmit-logout" href="logout.php">Log out</a>
                    </div>
                </form>
            <?php else: ?>
                <h1 class="status-heading">Registration Submitted</h1>
                <p class="status-message">Thank you for registering. Your application is now under review by Admin.</p>
                <p class="status-message">You'll receive an email confirmation once your account is approved. Please check your inbox regularly for updates.</p>
                <p class="status-update">For updates on your account status,<br>Contact your Property Manager</p>
                <?php if ($pageFlash): ?>
                    <p class="status-message"><?php echo htmlspecialchars($pageFlash['message']); ?></p>
                <?php endif; ?>
                <div class="status-button-wrap">
                    <button type="button" class="status-button" onclick="window.location.href='logout.php'">UNDERSTOOD</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
