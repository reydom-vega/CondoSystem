<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(dashboardPathForRole());
}

$errors = [];
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $connection = connectDb();
        $allowed = allowAuthenticationRequest($connection, 'resend_verification', 5);
        $stmt = $connection->prepare('SELECT id, username, is_verified FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($allowed && $result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ((int)$user['is_verified'] === 1) {
                $success = 'This account is already verified. You can log in.';
            } else {
                $verificationToken = generateVerificationCode();
                $digest = hash('sha256', $verificationToken);
                $update = $connection->prepare('UPDATE users SET verification_token = ?, verification_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?');
                $update->bind_param('si', $digest, $user['id']);

                if ($update->execute()) {
                    $verificationLink = buildUrl('verify.php?email=' . urlencode($email));
                    $subject = 'Your verification code';
                    $safeUsername = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
                    $safeCode = htmlspecialchars($verificationToken, ENT_QUOTES, 'UTF-8');
                    $safeVerificationLink = htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8');
                    $message = '<!doctype html><html><body style="margin:0;background:#0a0f1d;color:#ffffff;font-family:Arial,sans-serif;padding:24px;">'
                        . '<div style="max-width:560px;margin:0 auto;background:#111827;border:1px solid #374151;border-radius:12px;padding:32px;">'
                        . '<div style="color:#f59e0b;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Celandine Residences</div>'
                        . '<h1 style="font-size:24px;margin:14px 0 8px;">Verify your account</h1>'
                        . '<p style="color:#cbd5e1;line-height:1.6;">Hello ' . $safeUsername . ', use the verification code below to activate your account.</p>'
                        . '<div style="background:#1f293d;border:1px solid #4b5563;border-radius:8px;color:#fbbf24;font-size:30px;font-weight:bold;letter-spacing:8px;text-align:center;padding:18px;margin:24px 0;">' . $safeCode . '</div>'
                        . '<div style="text-align:center;"><a href="' . $safeVerificationLink . '" style="display:inline-block;background:#d97706;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 22px;font-weight:bold;">Enter Verification Code</a></div>'
                        . '<p style="color:#9ca3af;font-size:12px;line-height:1.5;margin-top:24px;">If you did not request this code, you can ignore this email.</p>'
                        . '</div></body></html>';
                    $sent = sendMail($email, $subject, $message);

                    if ($sent) {
                        $success = 'Verification email resent. Check your inbox.';
                    } else {
                        error_log('Verification delivery unavailable; review configured mail transport.');
                    }
                } else {
                    $errors[] = 'Unable to set verification code. Please try again later.';
                }
            }
        } else {
            $success = 'If that email exists in our system, a verification email has been sent.';
        }
        $success = 'If the account requires verification, a code will be emailed. If it does not arrive, contact management.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification Email</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="card glass-card">
        <div class="form-panel">
            <h1>Resend Verification Email</h1>
            <p class="subtitle">Enter your email and we'll resend the account verification link.</p>

            <?php if ($success): ?>
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

            <form method="post">
                <?php echo workflowCsrfField(); ?>
                <div class="input-wrap">
                    <?php echo systemIconFromGlyph('✉️', 'icon'); ?>
                    <input type="email" id="email" name="email" placeholder="Email address" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
                <button type="submit">Resend Email</button>
            </form>

            <p class="footer-link"><a href="login.php">Back to login</a></p>
            <p class="footer-link"><a href="signup.php">Create an Account</a></p>
        </div>
    </div>
</body>
</html>
