<?php
require_once 'config.php';

$errors = [];

$success_message = '';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$user = null;

if ($token !== '') {
    $connection = connectDb();
    $user = findPasswordResetUser($connection, $token);
}


if ($token === '') {
    $errors[] = 'Invalid or missing password reset token.';
} elseif (!$user) {
    $errors[] = 'This password reset link is invalid or has expired.';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    requireWorkflowCsrf();
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";

    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain both uppercase and lowercase letters.";
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    if (!preg_match('/[\W_]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    if (empty($errors)) {
        if (allowAuthenticationRequest($connection, 'reset_submit', 10) && completePasswordReset($connection, $token, $password)) {
            $success_message = 'Your password has been reset successfully! You can now log in with your new password.';
        } else {
            $errors[] = 'Unable to reset your password. Please request a new reset link.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="card">
        <h2>Reset Password</h2>
        <p class="subtitle">Enter your new password below to secure your account.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert error">
                <strong>Error</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
            <div class="alert success">
                <strong>Success!</strong>
                <p><?php echo htmlspecialchars($success_message); ?></p>
            </div>
            <a href="login.php" class="button-link">Go to Login</a>
        <?php elseif (!empty($token)): ?>

            <form method="POST" action="reset_password.php" id="resetForm">
                <?php echo workflowCsrfField(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label class="field-label" for="password">New Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" required placeholder="••••••••" autocomplete="new-password">
                        <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="field-label" for="confirm_password">Confirm Password</label>
                    <div class="input-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="••••••••" autocomplete="new-password">
                        <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1 2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                    </div>
                </div>
                <div class="password-rules">
                    <ul>
                        <li id="rule-length"><?php echo systemIconFromGlyph('✖', 'icon'); ?> 8 Chars</li>
                        <li id="rule-casing"><?php echo systemIconFromGlyph('✖', 'icon'); ?> A-Z, a-z</li>
                        <li id="rule-number"><?php echo systemIconFromGlyph('✖', 'icon'); ?> 123</li>
                        <li id="rule-special"><?php echo systemIconFromGlyph('✖', 'icon'); ?> @#$</li>
                        <li id="rule-match"><?php echo systemIconFromGlyph('✖', 'icon'); ?> Match</li>
                    </ul>
                </div>
                <button type="submit" id="submitBtn" disabled style="opacity: 0.6; cursor: not-allowed;">Reset Password</button>
            </form>
        <?php endif; ?>
        <div class="footer-link">
            Remembered your password? <a href="login.php">Back to Login</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const eyeOpenSVG = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
            const eyeOffSVG = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`;
            const passwordInputs = [document.getElementById('password'), document.getElementById('confirm_password')].filter(Boolean);
            passwordInputs.forEach(input => {
                const wrap = input.closest('.input-wrap');
                const toggleBtn = wrap ? wrap.querySelector('.toggle-password') : null;
                if (!toggleBtn) return;

                function checkToggleVisibility() {
                    if (input.value.trim().length > 0) {
                        toggleBtn.classList.add('is-visible');
                    } else {
                        toggleBtn.classList.remove('is-visible');
                    }
                }
                input.addEventListener('input', checkToggleVisibility);
                input.addEventListener('focus', checkToggleVisibility);

                toggleBtn.addEventListener('click', function () {
                    const svg = this.querySelector('svg');
                    if (input.type === 'password') {
                        input.type = 'text';
                        svg.innerHTML = eyeOpenSVG;
                    } else {
                        input.type = 'password';
                        svg.innerHTML = eyeOffSVG;
                    }
                    input.focus();
                });
            });

            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
            const submitBtn = document.getElementById('submitBtn');
            if (!passwordInput || !confirmInput) return;
            const rules = {
                length: document.getElementById('rule-length'),
                casing: document.getElementById('rule-casing'),
                number: document.getElementById('rule-number'),
                special: document.getElementById('rule-special'),
                match: document.getElementById('rule-match')

            };
            function updateRule(element, isValid) {
                const icon = element.querySelector('.icon');
                if (isValid) {
                    element.classList.add('valid');
                    icon.innerHTML = '<svg class="system-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';
                } else {
                    element.classList.remove('valid');
                    icon.innerHTML = '<svg class="system-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
                }
            }

            function validateForm() {
                const pass = passwordInput.value;
                const confirmPass = confirmInput.value;
                const isLength = pass.length >= 8;
                const isCasing = /[A-Z]/.test(pass) && /[a-z]/.test(pass);
                const isNumber = /[0-9]/.test(pass);
                const isSpecial = /[\W_]/.test(pass);
                const isMatch = pass.length > 0 && pass === confirmPass;

                updateRule(rules.length, isLength);
                updateRule(rules.casing, isCasing);
                updateRule(rules.number, isNumber);
                updateRule(rules.special, isSpecial);
                updateRule(rules.match, isMatch);
                const allValid = isLength && isCasing && isNumber && isSpecial && isMatch;
                if (allValid) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                } else {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.6';
                    submitBtn.style.cursor = 'not-allowed';
                }
            }
            passwordInput.addEventListener('input', validateForm);
            confirmInput.addEventListener('input', validateForm);
        });
    </script>
</body>
</html>
