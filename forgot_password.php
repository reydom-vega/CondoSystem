<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(dashboardPathForRole());
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $connection = connectDb();
        $allowed = allowAuthenticationRequest($connection, 'password_reset', 5);
        
        $stmt = $connection->prepare('SELECT id, username FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($allowed && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $resetToken = issuePasswordResetToken($connection, (int)$user['id']);

            $resetLink = buildUrl('reset_password.php?token=' . urlencode($resetToken));
            $subject = 'Reset your password';
            $safeUsername = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
            $safeResetLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
            $message = '<!doctype html><html><body style="margin:0;background:#0a0f1d;color:#ffffff;font-family:Arial,sans-serif;padding:24px;">'
                . '<div style="max-width:560px;margin:0 auto;background:#111827;border:1px solid #374151;border-radius:12px;padding:32px;">'
                . '<div style="color:#f59e0b;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Celandine Residences</div>'
                . '<h1 style="font-size:24px;margin:14px 0 8px;">Reset your password</h1>'
                . '<p style="color:#cbd5e1;line-height:1.6;">Hello ' . $safeUsername . ', click the button below to create a new password for your account.</p>'
                . '<div style="text-align:center;"><a href="' . $safeResetLink . '" style="display:inline-block;background:#d97706;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 22px;font-weight:bold;margin:16px 0;">Reset Password</a></div>'
                . '<p style="color:#9ca3af;font-size:12px;line-height:1.5;">This link expires in 1 hour. If you did not request a password reset, you can ignore this email.</p>'
                . '</div></body></html>';
            $sent = sendMail($email, $subject, $message);

            if (!$sent) {
                error_log('Password reset delivery unavailable; review configured mail transport.');
            }
        }

        if (empty($errors)) {
            setFlash('success', 'If the account is eligible, a reset link will be emailed. If it does not arrive, contact management.');
            redirect('login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="card">
        <div class="panel-hero">
            <div class="brand">
                <div class="logo">LM</div>
                <h1>Password reset</h1>
            </div>
            <p class="subtitle">We will send a secure reset link to your email.</p>
        </div>

        <div class="form-panel">
            <h1>Reset Password</h1>
            <p class="subtitle">Enter your email and we will send a reset link.</p>

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
                    <input type="email" id="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                </div>
                <button type="submit">Send Reset Link</button>
            </form>

            <p class="footer-link"><a href="login.php">Back to login</a></p>
        </div>
    </div>
</body>
</html>
