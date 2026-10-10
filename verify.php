<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(dashboardPathForRole());
}

$errors = [];
$email = trim($_POST['email'] ?? $_GET['email'] ?? '');
$code = trim($_POST['code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($code === '') {
        $errors[] = 'Please enter the verification code.';
    }

    if (empty($errors)) {
        $connection = connectDb();
        if (!allowAuthenticationRequest($connection, 'verify', 5)) { http_response_code(429); exit('Too many attempts. Please try again later.'); }
        $stmt = $connection->prepare('SELECT id, is_verified, verification_token, verification_expires FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ((int)$user['is_verified'] === 1) {
                setFlash('success', 'Your account is already verified. You can now sign in.');
                redirect('login.php');
            }

            $digest = hash('sha256', $code);
            if ($user['verification_token'] === null || !preg_match('/\A[0-9]{6}\z/', $code) || !hash_equals((string)$user['verification_token'], $digest) || empty($user['verification_expires']) || strtotime($user['verification_expires']) <= time()) {
                $errors[] = 'Invalid verification code.';
            } else {
                $columnCheck = $connection->query("SHOW COLUMNS FROM users LIKE 'email_verified_at'");
                $hasEmailVerifiedAt = $columnCheck && $columnCheck->num_rows > 0;

                $updateSql = $hasEmailVerifiedAt
                    ? 'UPDATE users SET is_verified = 1, email_verified_at = NOW(), verification_token = NULL, verification_expires = NULL WHERE email = ? AND verification_token = ? AND verification_expires > NOW()'
                    : 'UPDATE users SET is_verified = 1, verification_token = NULL, verification_expires = NULL WHERE email = ? AND verification_token = ? AND verification_expires > NOW()';

                $update = $connection->prepare($updateSql);
                $update->bind_param('ss', $email, $digest);
                $update->execute();
                if ($update->affected_rows !== 1) { http_response_code(422); exit('This code has expired or has already been used. Request a new code.'); }

                setFlash('success', 'Your email has been verified. You can now sign in.');
                redirect('login.php');
            }
        } else {
            $errors[] = 'No account found with that email address.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account</title>
    <link rel="stylesheet" href="styles.css">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui portal-account-page">
    <div class="card">
        <div class="form-panel">
            <h1>Verify Account</h1>
            <p class="subtitle">Enter your email and verification code to activate your account.</p>

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
                    <label class="field-label" for="email">Email address</label>
                    <input id="email" type="email" name="email" placeholder="Email address" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
                <div class="input-wrap">
                    <?php echo systemIconFromGlyph('🔑', 'icon'); ?>
                    <label class="field-label" for="code">Verification code</label>
                    <input id="code" type="text" name="code" placeholder="Verification code" required>
                </div>
                <button type="submit">Verify</button>
            </form>

            <p class="footer-link"><a href="login.php">Back to login</a></p>
        </div>
    </div>
</body>
</html>
