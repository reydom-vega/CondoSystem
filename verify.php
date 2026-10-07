<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(isSecurity() ? 'security/security_dashboard.php' : (isMaintenance() ? 'maintenance/maintenance_dashboard.php' : (isTreasurer() ? 'treasurer/treasurer_dashboard.php' : (isAdmin() ? 'admin/admin_dashboard.php' : 'resident/dashboard.php'))));
}

$errors = [];
$email = trim($_POST['email'] ?? $_GET['email'] ?? '');
$code = trim($_POST['code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($code === '') {
        $errors[] = 'Please enter the verification code.';
    }

    if (empty($errors)) {
        $connection = connectDb();
        $stmt = $connection->prepare('SELECT id, is_verified, verification_token FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ((int)$user['is_verified'] === 1) {
                setFlash('success', 'Your account is already verified. You can now sign in.');
                redirect('login.php');
            }

            if ($user['verification_token'] === null || $code !== $user['verification_token']) {
                $errors[] = 'Invalid verification code.';
            } else {
                $columnCheck = $connection->query("SHOW COLUMNS FROM users LIKE 'email_verified_at'");
                $hasEmailVerifiedAt = $columnCheck && $columnCheck->num_rows > 0;

                $updateSql = $hasEmailVerifiedAt
                    ? 'UPDATE users SET is_verified = 1, email_verified_at = NOW(), verification_token = ? WHERE email = ?'
                    : 'UPDATE users SET is_verified = 1, verification_token = ? WHERE email = ?';

                $update = $connection->prepare($updateSql);
                $update->bind_param('ss', $code, $email);
                $update->execute();

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
</head>
<body>
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
                <div class="input-wrap">
                    <?php echo systemIconFromGlyph('✉️', 'icon'); ?>
                    <input type="email" name="email" placeholder="Email address" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
                <div class="input-wrap">
                    <?php echo systemIconFromGlyph('🔑', 'icon'); ?>
                    <input type="text" name="code" placeholder="Verification code" required>
                </div>
                <button type="submit">Verify</button>
            </form>

            <p class="footer-link"><a href="login.php">Back to login</a></p>
        </div>
    </div>
</body>
</html>
