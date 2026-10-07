<?php
require_once __DIR__ . '/../config.php';

$testsPassed = 0;

function assertTrue(bool $condition, string $message): void {
    global $testsPassed;
    if ($condition) {
        $testsPassed++;
        echo "PASS: $message\n";
    } else {
        echo "FAIL: $message\n";
        exit(1);
    }
}

assertTrue(function_exists('isPasswordStrong'), 'Password strength helper exists');
assertTrue(isPasswordStrong('Hrm@2026!') === true, 'Strong password is accepted');
assertTrue(isPasswordStrong('password123') === false, 'Weak password is rejected');
assertTrue(isPasswordStrong('Short1!') === false, 'Password below minimum length is rejected');

$_SESSION = [];
assertTrue(isSessionExpired() === false, 'New session is not considered expired');

$_SESSION['last_activity'] = time() - 60 * 60;
assertTrue(isSessionExpired(15) === true, 'Idle session beyond timeout is expired');

$_SESSION = [];
assertTrue(getLoginErrorMessage('empty') === 'Please enter both username and password.', 'Empty form error is returned');
assertTrue(getLoginErrorMessage('locked') === 'Your account has been locked due to multiple failed login attempts.', 'Locked account error is returned');
assertTrue(getLoginErrorMessage('invalid') === 'Invalid username or password.', 'Invalid credentials error is returned');

echo "All auth tests passed ($testsPassed).\n";
