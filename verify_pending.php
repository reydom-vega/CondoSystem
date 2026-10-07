<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(isSecurity() ? 'security/security_dashboard.php' : (isMaintenance() ? 'maintenance/maintenance_dashboard.php' : (isTreasurer() ? 'treasurer/treasurer_dashboard.php' : (isAdmin() ? 'admin/admin_dashboard.php' : 'resident/dashboard.php'))));
}

$email = $_GET['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="card">
        <div class="form-panel">
            <h1>Verify Your Email</h1>
            <p class="subtitle">Check your inbox for a verification link.</p>

            <div class="alert success">
                <strong>Account created successfully!</strong>
                <p>We've sent a verification code to your email. Enter it on the verification page to activate your account.</p>
            </div>

            <p style="text-align: center; color: var(--text-muted); margin: 20px 0; font-size: 14px;">
                Click below to enter your verification code.
            </p>

            <a class="button-link" href="verify.php?email=<?php echo urlencode($email); ?>">
                Enter Verification Code
            </a>

            <p class="footer-link"><a href="login.php">Back to login</a></p>
        </div>
    </div>
</body>
</html>