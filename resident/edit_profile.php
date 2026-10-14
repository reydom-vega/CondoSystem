<?php
require_once __DIR__.'/../config.php';

if (!isLoggedIn()) {
    redirect(buildUrl('login.php'));
}
try { profileRequireSelf(); } catch (InvalidArgumentException $error) { http_response_code(403); renderPortalError($error->getMessage()); exit; }
$profileIsResident = ($_SESSION['role'] ?? '') === 'resident';
$profileHome = buildUrl(dashboardPathForRole($_SESSION['role']));
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
    redirect(buildUrl('login.php'));
}

$fullName = $user['full_name'];
$usernameField = $user['username'];
$email = $user['email'];
$contactNumber = $user['contact_number'];
$originalContactNumber = $user['contact_number'];
$phoneVerified = (int)$user['phone_verified'] === 1;
$unitRaw = $user['unit_number'];
$profileAccount = profileCurrent($db);
$accountRoleLabel = profileRoleLabel($profileAccount);

// Header display values
$username = $fullName;
$unitNumber = $unitRaw ? 'Unit ' . htmlspecialchars($unitRaw) : 'Not yet assigned';

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
    } elseif (in_array($formAction, ['update_profile', 'update_password'], true)) {
        $passwordOnly = $formAction === 'update_password';
        // A security-only submission must never read or overwrite personal fields.
        $fullName      = $passwordOnly ? $user['full_name'] : trim($_POST['full_name'] ?? '');
        $email         = $passwordOnly ? $user['email'] : trim($_POST['email'] ?? '');
        $contactNumber = $passwordOnly ? $user['contact_number'] : trim($_POST['contact_number'] ?? '');

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword      = $_POST['new_password'] ?? '';
        $confirmPassword  = $_POST['confirm_password'] ?? '';
        $wantsPasswordChange = $passwordOnly || $newPassword !== '' || $confirmPassword !== '';
        $emailChanged = strcasecmp($email, (string)$user['email']) !== 0;

        if (!$passwordOnly && $fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if (!$passwordOnly && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'A valid email address is required.';
        }
        if (!$passwordOnly && $contactNumber === '') {
            $errors[] = 'Contact number is required.';
        }
        if (!$passwordOnly && $profileIsResident && !preg_match('/\A09[0-9]{9}\z/', $contactNumber)) $errors[] = 'Enter an 11 digit Philippine mobile number starting with 09.';
        if (!$passwordOnly && strlen($contactNumber) > 20) $errors[] = 'Contact number must be 20 characters or shorter.';
        if (!$passwordOnly && (strlen($fullName) > 100 || strlen($email) > 100)) $errors[] = 'Name and email must be 100 characters or shorter.';
        if ($emailChanged && !$wantsPasswordChange) {
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->bind_param('i', $userId); $stmt->execute();
            $credentials = $stmt->get_result()->fetch_assoc();
            if (!$credentials || !password_verify($currentPassword, $credentials['password_hash'])) $errors[] = 'Enter your current password to change your email address.';
        }

        // Email must stay unique (excluding this user's own row)
        if (!$passwordOnly && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

        $openPw = !empty($errors) && $wantsPasswordChange;

        if (empty($errors)) {
            ensureRememberTokensTable($db);
            $db->begin_transaction();
            try {
            $contactNumberChanged = $contactNumber !== $originalContactNumber;
            if (!$passwordOnly) {
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

            if (!$passwordOnly) $_SESSION['username'] = $fullName;
            $username = $fullName;

            $success = true;
            $user['full_name'] = $fullName; $user['email'] = $email; $user['contact_number'] = $contactNumber;
        }
    }
}

$profileAccount = profileCurrent($db, true);
$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - Edit Profile</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl($profileIsResident ? 'resident.css' : (isSecurity() ? 'security.css' : 'styles.css')), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('assets/css/edit-profile.css'), ENT_QUOTES, 'UTF-8') ?>">
    <script defer src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/edit-profile.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body class="portal-ui dashboard-page edit-profile-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?= htmlspecialchars($profileHome, ENT_QUOTES, 'UTF-8') ?>" class="sidebar-brand">
                <?php include __DIR__.'/../buildingicon.php'; ?>
            </a>
            <nav class="sidebar-nav"><?php if ($profileIsResident) renderResidentSidebarNavigation(); else renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><h1 class="dash-title">Edit Profile</h1><p class="profile-page-subtitle">Manage your personal information and account security.</p></div>
                </div>
                <div class="dash-header-right">
                    <?php include __DIR__.'/../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <?= profileAvatar('profile-avatar') ?>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <?= profileAvatar('profile-dropdown-avatar') ?>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit"><?= $profileIsResident ? $unitNumber : htmlspecialchars($accountRoleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <a href="<?= htmlspecialchars(profileEditUrl(), ENT_QUOTES, 'UTF-8') ?>" class="profile-dropdown-item"><?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile</a>
                            <a href="<?= htmlspecialchars(buildUrl('logout.php'), ENT_QUOTES, 'UTF-8') ?>" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="profile-settings" aria-label="Profile settings">
                <?php if ($success): ?><div class="alert success" role="status"><strong><?= !empty($passwordOnly) ? 'Password updated.' : 'Profile updated.' ?></strong> Your changes have been saved successfully.</div><?php endif; ?>
                <?php if ($errors): ?><div class="alert error" role="alert"><strong>Please fix the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>

                <section class="profile-section profile-overview" aria-labelledby="profileOverviewTitle">
                    <button type="button" id="changeProfilePhoto" class="profile-photo-button" aria-label="Change profile picture"><?= profileAvatar('profile-overview-avatar') ?><span class="profile-photo-camera" aria-hidden="true"><?= systemIcon('camera') ?></span></button>
                    <div class="profile-overview-identity">
                        <p class="profile-eyebrow" id="profileOverviewTitle">Profile Overview</p>
                        <h2><?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="profile-overview-username">@<?= htmlspecialchars($usernameField, ENT_QUOTES, 'UTF-8') ?></p>
                        <span class="profile-role-badge"><?= systemIcon('check-circle') ?><?= htmlspecialchars($accountRoleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php if ($profileIsResident): ?><div class="profile-unit-summary"><?= systemIcon('dashboard') ?><div><span>Assigned unit</span><strong><?= htmlspecialchars($unitRaw ?: 'Not yet assigned', ENT_QUOTES, 'UTF-8') ?></strong></div></div><?php endif; ?>
                    <div class="profile-photo-options"><p>Tap your picture to change it. JPG, PNG or WebP, up to 5 MB.</p><button type="button" id="removeProfilePhoto"<?= profilePhotoUrl($profileAccount) ? '' : ' hidden' ?>>Remove Photo</button></div><p id="profilePhotoFeedback" class="profile-photo-feedback" role="status" aria-live="polite"></p>
                    <input type="file" id="profilePhotoInput" accept="image/jpeg,image/png,image/webp" hidden>
                </section>

                <section class="profile-section profile-personal-card" aria-labelledby="personalInformationTitle">
                    <header class="profile-card-heading"><span class="profile-card-icon"><?= systemIcon('users') ?></span><div><h2 id="personalInformationTitle">Personal Information</h2><p>Update your contact details.</p></div></header>
                    <form method="post" action="<?= htmlspecialchars(profileEditUrl(), ENT_QUOTES, 'UTF-8') ?>" id="personalInformationForm" data-profile-form data-original-email="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= workflowCsrfField() ?><input type="hidden" name="action" value="update_profile">
                        <div class="profile-fields form-grid-2">
                            <div class="profile-field"><label class="field-label" for="full_name">Full Name <span class="portal-required" aria-hidden="true">*</span></label><input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>" autocomplete="name" maxlength="100" required></div>
                            <div class="profile-field"><label class="field-label" for="username_display">Username</label><input type="text" id="username_display" value="<?= htmlspecialchars($usernameField, ENT_QUOTES, 'UTF-8') ?>" readonly aria-describedby="usernameHint"><small id="usernameHint"><?= systemIcon('lock') ?>Your username cannot be changed.</small></div>
                            <div class="profile-field"><label class="field-label" for="contact_number">Contact Number <span class="portal-required" aria-hidden="true">*</span></label><input type="tel" id="contact_number" name="contact_number" value="<?= htmlspecialchars((string)$contactNumber, ENT_QUOTES, 'UTF-8') ?>" <?= $profileIsResident ? 'placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" maxlength="11"' : 'maxlength="20"' ?> inputmode="tel" autocomplete="tel" aria-describedby="contactHint" required><small id="contactHint"><?= $profileIsResident ? '11-digit mobile number starting with 09.' : 'Your existing contact number.' ?></small></div>
                            <?php if ($profileIsResident): ?><div class="profile-field"><label class="field-label" for="unit_number_display">Unit Number</label><input type="text" id="unit_number_display" value="<?= htmlspecialchars($unitRaw ?: 'Not yet assigned', ENT_QUOTES, 'UTF-8') ?>" readonly aria-describedby="unitHint"><small id="unitHint"><?= systemIcon('lock') ?>Unit assignments are managed by the office.</small></div><?php elseif (!empty($profileAccount['staff_id'])): ?><div class="profile-field"><label class="field-label" for="staff_id_display">Staff ID</label><input id="staff_id_display" value="<?= htmlspecialchars($profileAccount['staff_id'], ENT_QUOTES, 'UTF-8') ?>" readonly></div><?php endif; ?>
                            <div class="profile-field profile-field-wide"><label class="field-label" for="email">Email Address <span class="portal-required" aria-hidden="true">*</span></label><input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" maxlength="100" aria-describedby="emailHint" required><small id="emailHint">Changing your email requires your current password.</small></div>
                            <div class="profile-field profile-field-wide profile-email-confirmation" id="emailPasswordConfirmation"><label class="field-label" for="email_current_password">Current Password <span class="portal-required" aria-hidden="true">*</span></label><div class="profile-password-input"><input type="password" id="email_current_password" name="current_password" autocomplete="current-password" aria-describedby="emailPasswordHint"><button type="button" class="profile-password-toggle" data-password-target="email_current_password" aria-label="Show current password" aria-pressed="false"><?= systemIcon('eye') ?></button></div><small id="emailPasswordHint">Enter only when changing your email address. This does not change your password.</small></div>
                        </div>
                        <div class="profile-actions"><a href="<?= htmlspecialchars($profileHome, ENT_QUOTES, 'UTF-8') ?>" class="profile-back">Cancel</a><button type="submit" class="profile-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>Save Changes</button></div>
                    </form>
                </section>

                <section class="profile-section profile-security-card" aria-labelledby="accountSecurityTitle">
                    <header class="profile-card-heading"><span class="profile-card-icon"><?= systemIcon('shield') ?></span><div><h2 id="accountSecurityTitle">Account Security</h2><p>Manage your password and account security.</p></div></header>
                    <details class="profile-password-details pw-section"<?= $openPw ? ' open' : '' ?>>
                        <summary><span><?= systemIcon('lock') ?>Change Password</span><svg class="profile-password-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
                        <form method="post" action="<?= htmlspecialchars(profileEditUrl(), ENT_QUOTES, 'UTF-8') ?>" id="passwordChangeForm" class="pw-body" data-profile-form>
                            <?= workflowCsrfField() ?><input type="hidden" name="action" value="update_password">
                            <div class="profile-fields">
                                <div class="profile-field profile-field-wide"><label class="field-label" for="current_password">Current Password <span class="portal-required" aria-hidden="true">*</span></label><div class="profile-password-input"><input type="password" id="current_password" name="current_password" autocomplete="current-password" required><button type="button" class="profile-password-toggle" data-password-target="current_password" aria-label="Show current password" aria-pressed="false"><?= systemIcon('eye') ?></button></div></div>
                                <div class="profile-password-grid">
                                    <div class="profile-field"><label class="field-label" for="new_password">New Password <span class="portal-required" aria-hidden="true">*</span></label><div class="profile-password-input"><input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" aria-describedby="passwordRules" required><button type="button" class="profile-password-toggle" data-password-target="new_password" aria-label="Show new password" aria-pressed="false"><?= systemIcon('eye') ?></button></div></div>
                                    <div class="profile-field"><label class="field-label" for="confirm_password">Confirm New Password <span class="portal-required" aria-hidden="true">*</span></label><div class="profile-password-input"><input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required><button type="button" class="profile-password-toggle" data-password-target="confirm_password" aria-label="Show password confirmation" aria-pressed="false"><?= systemIcon('eye') ?></button></div></div>
                                </div>
                            </div>
                            <div class="password-rules profile-password-rules" id="passwordRules"><p>Your new password must include:</p><ul><li data-rule="len"><?= systemIcon('close','icon') ?>At least 8 characters</li><li data-rule="case"><?= systemIcon('close','icon') ?>Uppercase and lowercase letters</li><li data-rule="num"><?= systemIcon('close','icon') ?>A number</li><li data-rule="sym"><?= systemIcon('close','icon') ?>A special character</li><li data-rule="match"><?= systemIcon('close','icon') ?>Matching confirmation</li></ul></div>
                            <div class="profile-actions"><button type="submit" class="profile-primary"><?= systemIcon('lock') ?>Update Password</button></div>
                        </form>
                    </details>
                </section>
            </div>
            <?php require __DIR__.'/../includes/profile_photo_editor.php'; ?>
        </main>
    </div>


</body>
</html>
