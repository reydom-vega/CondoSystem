<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}

requireResidentPermission('resident.profile.edit');
$userId = $_SESSION['user_id'];
$db = connectDb();
ensurePhoneVerificationColumns($db);

// Load current user data fresh from the DB
$stmt = $db->prepare('SELECT full_name, username, email, contact_number, unit_number, phone_verified FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    redirect('../login.php');
}

$fullName = $user['full_name'];
$usernameField = $user['username'];
$email = $user['email'];
$contactNumber = $user['contact_number'];
$originalContactNumber = $user['contact_number'];
$phoneVerified = (int)$user['phone_verified'] === 1;
$unitRaw = $user['unit_number'];

// Header display values
$username = $fullName;
$unitNumber = $unitRaw ? 'Unit ' . htmlspecialchars($unitRaw) : 'Not yet assigned';

// Initials for the default avatar circle
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$errors = [];
$success = false;
$otpMessage = '';
$otpError = '';
$openPw = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $formAction = $_POST['action'] ?? 'update_profile';

    if ($formAction === 'send_phone_otp') {
        $otpResult = generateAndSendPhoneOtp($userId);
        if ($otpResult['success']) {
            $otpMessage = 'A verification code was sent to your phone.';
        } else {
            $otpError = 'Could not send code: ' . $otpResult['error'];
        }
    } elseif ($formAction === 'verify_phone_otp') {
        $code = trim($_POST['otp_code'] ?? '');
        if (verifyPhoneOtp($userId, $code)) {
            $phoneVerified = true;
            $otpMessage = 'Phone number verified!';
        } else {
            $otpError = 'Invalid or expired code. Please try again.';
        }
    } elseif ($formAction === 'update_profile') {
        $fullName      = trim($_POST['full_name'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword      = $_POST['new_password'] ?? '';
        $confirmPassword  = $_POST['confirm_password'] ?? '';
        $wantsPasswordChange = $newPassword !== '' || $confirmPassword !== '';
        $emailChanged = strcasecmp($email, (string)$user['email']) !== 0;

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if ($contactNumber === '') {
            $errors[] = 'Contact number is required.';
        }
        if (!preg_match('/\A09[0-9]{9}\z/', $contactNumber)) $errors[] = 'Enter an 11 digit Philippine mobile number starting with 09.';
        if (strlen($fullName) > 100 || strlen($email) > 100) $errors[] = 'Name and email must be 100 characters or shorter.';
        if ($emailChanged && !$wantsPasswordChange) {
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->bind_param('i', $userId); $stmt->execute();
            $credentials = $stmt->get_result()->fetch_assoc();
            if (!$credentials || !password_verify($currentPassword, $credentials['password_hash'])) $errors[] = 'Enter your current password to change your email address.';
        }

        // Email must stay unique (excluding this user's own row)
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $stmt->bind_param('si', $email, $userId);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $errors[] = 'That email address is already in use by another account.';
            }
            $stmt->close();
        }

        if ($wantsPasswordChange) {
            if ($currentPassword === '') {
                $errors[] = 'Enter your current password to set a new one.';
            }
            if (!isPasswordStrong($newPassword)) {
                $errors[] = 'New password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.';
            }
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'Password confirmation does not match.';
            }

            if (empty($errors) || $currentPassword !== '') {
                $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                    $errors[] = 'Current password is incorrect.';
                }
            }
        }

        $openPw = !empty($errors) && ($wantsPasswordChange || $currentPassword !== '' || $emailChanged);

        if (empty($errors)) {
            ensureRememberTokensTable($db);
            $db->begin_transaction();
            try {
            $contactNumberChanged = $contactNumber !== $originalContactNumber;
            if ($contactNumberChanged) {
                $stmt = $db->prepare('UPDATE users SET full_name = ?, email = ?, contact_number = ?, phone_verified = 0, phone_otp = NULL, phone_otp_expires = NULL WHERE id = ?');
                $stmt->bind_param('sssi', $fullName, $email, $contactNumber, $userId);
            } else {
                $stmt = $db->prepare('UPDATE users SET full_name = ?, email = ?, contact_number = ? WHERE id = ?');
                $stmt->bind_param('sssi', $fullName, $email, $contactNumber, $userId);
            }
            $stmt->execute();
            $stmt->close();

            if ($contactNumberChanged) {
                $phoneVerified = false;
            }

            if ($wantsPasswordChange) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->bind_param('si', $newHash, $userId);
                $stmt->execute();
                $stmt->close();
            }

            $newSessionVersion = (int)$_SESSION['session_version'];
            if ($wantsPasswordChange || $emailChanged) {
                $stmt = $db->prepare('UPDATE users SET session_version = session_version + 1, reset_token = NULL, reset_expires = NULL WHERE id = ?');
                $stmt->bind_param('i', $userId); $stmt->execute();
                $stmt = $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
                $stmt->bind_param('i', $userId); $stmt->execute();
                $stmt = $db->prepare('SELECT session_version FROM users WHERE id = ?');
                $stmt->bind_param('i', $userId); $stmt->execute();
                $newSessionVersion = (int)$stmt->get_result()->fetch_assoc()['session_version'];
            }
            $db->commit();
            $_SESSION['session_version'] = $newSessionVersion;
            if ($wantsPasswordChange || $emailChanged) { forgetRememberToken(); session_regenerate_id(true); }
            } catch (Throwable $error) { $db->rollback(); throw $error; }

            $_SESSION['username'] = $fullName;
            $username = $fullName;
            $nameParts = preg_split('/\s+/', trim($username));
            $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

            $success = true;
        }
    }
}

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Edit Profile</title>
    <link rel="stylesheet" href="../resident.css?v=<?php echo filemtime(__DIR__ . '/../resident.css'); ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><h1 class="dash-title">Edit Profile</h1></div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span>
                                </div>
                            </div>
                            <a href="edit_profile.php" class="profile-dropdown-item"><?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile</a>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <section class="profile-form-section">
                <div class="profile-form-card">
                    <?php if ($success): ?>
                        <div class="alert success"><strong>Profile updated</strong> Your changes have been saved successfully.</div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert error">
                            <strong>Please fix the following:</strong>
                            <ul>
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($otpMessage): ?><div class="alert success"><?php echo htmlspecialchars($otpMessage); ?></div><?php endif; ?>
                    <?php if ($otpError): ?><div class="alert error"><?php echo htmlspecialchars($otpError); ?></div><?php endif; ?>

                    <div class="phone-verify">
                        <div class="phone-verify-head">
                            <h3 class="section-title">Phone Verification</h3>
                            <?php if ($phoneVerified): ?><span class="pv-badge ok">✓ Verified</span><?php endif; ?>
                        </div>
                        <?php if ($phoneVerified): ?>
                            <p class="pv-note">You'll receive SMS notifications for dues and approvals.</p>
                        <?php else: ?>
                            <p class="pv-note">Verify your number to get SMS reminders for dues and parking approvals.</p>
                            <div class="pv-row">
                                <form method="POST" action="edit_profile.php">
                                    <?php echo workflowCsrfField(); ?>
                                    <input type="hidden" name="action" value="send_phone_otp">
                                    <button type="submit">Send Code</button>
                                </form>
                                <form method="POST" action="edit_profile.php" class="pv-code-form">
                                    <?php echo workflowCsrfField(); ?>
                                    <input type="hidden" name="action" value="verify_phone_otp">
                                    <input type="text" id="otp_code" name="otp_code" maxlength="6" placeholder="123456" inputmode="numeric" autocomplete="one-time-code" aria-label="Verification code">
                                    <button type="submit">Verify</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="edit_profile.php" class="profile-form">
                        <?php echo workflowCsrfField(); ?>
                        <input type="hidden" name="action" value="update_profile">
                        <h3 class="section-title">Personal Information</h3>

                        <div class="form-grid-2">
                            <div class="input-wrap float-field">
                                <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" placeholder="Juan Dela Cruz" autocomplete="name" required>
                                <label class="field-label" for="full_name">Full Name</label>
                            </div>
                            <div class="input-wrap float-field">
                                <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
                                <input type="text" id="username_display" value="<?php echo htmlspecialchars($usernameField); ?>" readonly tabindex="-1" aria-readonly="true">
                                <label class="field-label" for="username_display">Username</label>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="input-wrap float-field">
                                <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg></span>
                                <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($contactNumber); ?>" placeholder="09XX XXX XXXX" inputmode="tel" autocomplete="tel" required>
                                <label class="field-label" for="contact_number">Contact Number</label>
                            </div>
                            <div class="input-wrap float-field">
                                <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9.5 12 3l9 6.5"></path><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"></path></svg></span>
                                <input type="text" id="unit_number_display" value="<?php echo htmlspecialchars($unitRaw ? $unitRaw : 'Not yet assigned by admin'); ?>" readonly disabled style="background-color: #1f293d; color: #9ca3af;">
                                <label class="field-label" for="unit_number_display">Unit Number</label>
                            </div>
                        </div>

                        <div class="input-wrap float-field">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 6-10 7L2 6"></path></svg></span>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" autocomplete="email" required>
                            <label class="field-label" for="email">Email Address</label>
                        </div>

                        <details class="pw-section"<?php echo $openPw ? ' open' : ''; ?>>
                            <summary>
                                <span class="pw-summary-text">
                                    <span class="section-title">Change Password</span>
                                    <small>Leave blank if you don't want to change your password.</small>
                                </span>
                                <span class="pw-chevron" aria-hidden="true">▾</span>
                            </summary>
                            <div class="pw-body">
                                <div class="input-wrap float-field">
                                    <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></span>
                                    <input type="password" id="current_password" name="current_password" placeholder="••••••••" autocomplete="current-password">
                                    <button type="button" class="toggle-password" data-target="current_password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                                    <label class="field-label" for="current_password">Current Password</label>
                                </div>
                                <div class="input-wrap float-field">
                                    <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></span>
                                    <input type="password" id="new_password" name="new_password" placeholder="••••••••" autocomplete="new-password">
                                    <button type="button" class="toggle-password" data-target="new_password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                                    <label class="field-label" for="new_password">New Password</label>
                                </div>
                                <div class="input-wrap float-field">
                                    <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></span>
                                    <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" autocomplete="new-password">
                                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                                    <label class="field-label" for="confirm_password">Confirm New Password</label>
                                </div>
                                <div class="password-rules" aria-live="polite">
                                    <ul>
                                        <li data-rule="len"><?php echo systemIconFromGlyph('✕', 'icon'); ?>8 Chars</li>
                                        <li data-rule="case"><?php echo systemIconFromGlyph('✕', 'icon'); ?>A-Z, a-z</li>
                                        <li data-rule="num"><?php echo systemIconFromGlyph('✕', 'icon'); ?>123</li>
                                        <li data-rule="sym"><?php echo systemIconFromGlyph('✕', 'icon'); ?>@#$</li>
                                        <li data-rule="match"><?php echo systemIconFromGlyph('✕', 'icon'); ?>Match</li>
                                    </ul>
                                </div>
                            </div>
                        </details>

                        <div class="profile-form-actions">
                            <a href="dashboard.php" class="button-link profile-form-cancel">Cancel</a>
                            <button type="submit">Save Changes</button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        /* Navigation is handled by the shared UI module. */

        /* Navigation is handled by the shared UI module. */

        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');

        profileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = profileMenu.classList.toggle('open');
            profileToggle.setAttribute('aria-expanded', isOpen);
        });

        document.addEventListener('click', (e) => {
            if (!profileMenu.contains(e.target)) {
                profileMenu.classList.remove('open');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });

        (function () {
            const newPw = document.getElementById('new_password');
            const confirmPw = document.getElementById('confirm_password');
            const rules = {};
            document.querySelectorAll('.password-rules li[data-rule]').forEach((li) => {
                rules[li.dataset.rule] = li;
            });

            function setRule(li, ok) {
                li.classList.toggle('valid', ok);
                li.querySelector('.icon').innerHTML = ok
                    ? '<svg class="system-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>'
                    : '<svg class="system-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
            }

            function checkRules() {
                const v = newPw.value;
                setRule(rules.len, v.length >= 8);
                setRule(rules.case, /[a-z]/.test(v) && /[A-Z]/.test(v));
                setRule(rules.num, /\d/.test(v));
                setRule(rules.sym, /[^A-Za-z0-9]/.test(v));
                setRule(rules.match, v !== '' && v === confirmPw.value);
            }

            if(newPw && confirmPw) {
                newPw.addEventListener('input', checkRules);
                confirmPw.addEventListener('input', checkRules);
                checkRules();
            }
        })();

        document.querySelectorAll('.toggle-password').forEach((toggle) => {
            const input = document.getElementById(toggle.dataset.target);
            if (!input) return;
            input.addEventListener('input', () => toggle.classList.toggle('is-visible', input.value.length > 0));
            toggle.addEventListener('click', () => {
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                input.focus();
            });
        });
    </script>
</body>
</html>
