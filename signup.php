<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(isSecurity() ? 'security/security_dashboard.php' : (isMaintenance() ? 'maintenance/maintenance_dashboard.php' : (isTreasurer() ? 'treasurer/treasurer_dashboard.php' : (isAdmin() ? 'admin/admin_dashboard.php' : 'resident/dashboard.php'))));
}

$errors = [];
$fullName = '';
$username = '';
$email = '';
$contactNumber = '';
$unitNumber = '';
$accountType = '';
$allowedAccountTypes = ['Resident Owner', 'Family/Relative of the Owner', 'Friend of Owner', 'Tenant'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountType = trim($_POST['account_type'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $unitNumber = trim($_POST['unit_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!in_array($accountType, $allowedAccountTypes, true)) {
        $errors[] = 'Please choose an account type from step 1.';
    }

    if ($fullName === '' || $username === '' || $email === '' || $contactNumber === '' || $unitNumber === '' || $password === '' || $confirmPassword === '') {
        $errors[] = 'Please fill in all fields.';
    }

    if ($contactNumber !== '' && !preg_match('/^[0-9]{11}$/', $contactNumber)) {
        $errors[] = 'Contact number must be exactly 11 digits (e.g. 09171234567).';
    }

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors[] = 'Username must be 3-20 characters and contain only letters, numbers, or underscores.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (!isPasswordStrong($password)) {
        $errors[] = 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.';
    }

    if (empty($errors)) {
        $connection = connectDb();
        $stmt = $connection->prepare('SELECT id, status FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $existing = $result->fetch_assoc();
            if (($existing['status'] ?? 'approved') === 'pending') {
                $errors[] = 'This account is already pending approval.';
            } else {
                $errors[] = 'This account is already registered.';
            }
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $isVerified = 1;
            $status = 'pending';

            $insert = $connection->prepare('INSERT INTO users (full_name, username, email, contact_number, unit_number, account_type, password_hash, is_verified, status, verification_token) VALUES (?, ?, ?, ?, ?, ?, ?, 1, "pending", NULL)');
            $insert->bind_param('sssssss', $fullName, $username, $email, $contactNumber, $unitNumber, $accountType, $passwordHash);

            if ($insert->execute()) {
                $userId = (int)$connection->insert_id;
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['unit_number'] = $unitNumber;
                $_SESSION['role'] = 'resident';
                $_SESSION['last_activity'] = time();
                refreshSession();

                setFlash('success', 'Account created successfully. Your account is now pending admin approval.');
                redirect('signuppending.php');
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}
$showStepTwo = $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accountType, $allowedAccountTypes, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Celandine Residences</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__ . '/styles.css'); ?>">
    <style>
        .signup-step {
            display: none;
        }

        .signup-step.active {
            display: block;
        }

        .step-progress {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .step-progress .step-dot {
            width: 80px;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
        }

        .step-progress .step-dot.active {
            background: var(--btn-orange);
        }

        .relationship-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 10px 0 18px;
        }

        .relationship-option {
            display: block !important;
            width: 100%;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.02);
            padding: 14px 16px 14px 40px;
            position: relative;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .relationship-option:hover {
            border-color: rgba(245, 158, 11, 0.7);
        }

        .relationship-option.selected {
            border-color: var(--btn-orange);
            background: rgba(245, 158, 11, 0.08);
        }

        .relationship-option input {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            accent-color: var(--btn-orange);
        }

        .relationship-option strong {
            display: block;
            color: var(--text-primary);
            font-size: 15px;
            line-height: 1.35;
            margin-bottom: 4px;
        }

        .relationship-option small {
            display: block;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .step-message {
            display: none;
            margin: 0 0 14px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.45);
            color: #fca5a5;
            font-size: 0.92rem;
            font-weight: 600;
        }

        .step-message.show {
            display: block;
        }

        .step-button {
            display: block;
            width: 100%;
            margin-top: 8px;
        }

        .secondary-step-button {
            background: transparent;
            border: 1px solid var(--input-border);
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .step-back {
            display: inline-block;
            margin-bottom: 12px;
            color: var(--link-orange);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="card glass-card">
        <div class="form-panel">
            <div class="brand">
                <?php include 'buildingicon.php'; ?>
                <div class="brand-title">The Celandine<br>Residences</div>
            </div>

            <div class="step-progress" aria-label="Signup progress">
                <span class="step-dot<?php echo $showStepTwo ? '' : ' active'; ?>"></span>
                <span class="step-dot<?php echo $showStepTwo ? ' active' : ''; ?>"></span>
            </div>

            <div id="step-1" class="signup-step<?php echo $showStepTwo ? '' : ' active'; ?>">
                <h1>Create an Account</h1>
                <p class="subtitle">Kindly choose the option that fits you:</p>
                <div id="step1-error" class="step-message" role="alert" aria-live="polite"></div>

                <div class="relationship-list" role="radiogroup" aria-label="Account type">
                    <label class="relationship-option">
                        <input type="radio" name="account_type_step" value="Resident Owner" <?php echo $accountType === 'Resident Owner' ? 'checked' : ''; ?>>
                        <strong>Resident Owner</strong>
                        <small>Someone who owns the property</small>
                    </label>

                    <label class="relationship-option">
                        <input type="radio" name="account_type_step" value="Family/Relative of the Owner" <?php echo $accountType === 'Family/Relative of the Owner' ? 'checked' : ''; ?>>
                        <strong>Family/Relative of the Owner</strong>
                        <small>A family member or relative of the person who owns the property</small>
                    </label>

                    <label class="relationship-option">
                        <input type="radio" name="account_type_step" value="Friend of Owner" <?php echo $accountType === 'Friend of Owner' ? 'checked' : ''; ?>>
                        <strong>Friend of Owner</strong>
                        <small>A friend of the person who owns the property</small>
                    </label>

                    <label class="relationship-option">
                        <input type="radio" name="account_type_step" value="Tenant" <?php echo $accountType === 'Tenant' ? 'checked' : ''; ?>>
                        <strong>Tenant</strong>
                        <small>Someone who rents the property</small>
                    </label>

                </div>

                <button type="button" id="backToLogin" class="step-button secondary-step-button">Back to Login</button>
                <button type="button" id="goToStep2" class="step-button" disabled>Next</button>
            </div>

            <div id="step-2" class="signup-step<?php echo $showStepTwo ? ' active' : ''; ?>">
                <a href="#" id="backToStep1" class="step-back">← Back</a>
                <h1>Create an Account</h1>
                <p class="subtitle">Fill in the details below to register. Please go to the admin after approval to assign your unit number and present a valid ID to confirm you are the unit owner.</p>

                <?php $flash = getFlash(); if ($flash): ?>
                    <div class="alert <?php echo htmlspecialchars($flash['type'] === 'error' ? 'error' : 'success'); ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
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

                <form method="post" action="">
                    <input type="hidden" name="account_type" id="selected_account_type" value="<?php echo htmlspecialchars($accountType); ?>">
                    <div class="form-grid">
                        <div class="input-wrap">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                            <input type="text" name="full_name" placeholder="Full Name" value="<?php echo htmlspecialchars($fullName); ?>" required>
                        </div>
                        <div class="input-wrap">
                            <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
                            <input type="text" id="username" name="username" placeholder="Username" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                    </div>

                    <div class="input-wrap" id="contact_number_wrap">
                        <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg></span>
                        <input type="tel" id="contact_number" name="contact_number" placeholder="Contact Number (ex. 09171234567)" inputmode="numeric" maxlength="11" value="<?php echo htmlspecialchars($contactNumber); ?>" required>
                    </div>

                    <div class="input-wrap">
                        <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9z"></path><path d="M8 9h8M8 13h8"></path></svg></span>
                        <input type="text" id="unit_number" name="unit_number" placeholder="Unit Number (ex. 1001)" value="<?php echo htmlspecialchars($unitNumber ?? ''); ?>" required>
                    </div>

                    <div class="input-wrap">
                        <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 6-10 7L2 6"></path></svg></span>
                        <input type="email" id="email" name="email" placeholder="Email address" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>

                    <div class="input-wrap password-container">
                        <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></span>
                        <input type="password" id="password" name="password" placeholder="Password" required>
                        <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                    </div>

                    <div class="input-wrap password-container">
                        <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path><path d="M12 15v3"></path></svg></span>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password" required>
                        <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                    </div>

                    <div class="password-requirements" id="pwd_rules">
                        <ul>
                            <li id="req_length" class="invalid"><?php echo systemIcon('close', 'signup-rule-icon'); ?> 8 Chars</li>
                            <li id="req_case" class="invalid"><?php echo systemIcon('close', 'signup-rule-icon'); ?> A-Z, a-z</li>
                            <li id="req_num" class="invalid"><?php echo systemIcon('close', 'signup-rule-icon'); ?> 123</li>
                            <li id="req_spec" class="invalid"><?php echo systemIcon('close', 'signup-rule-icon'); ?> @#$</li>
                            <li id="req_match" class="invalid"><?php echo systemIcon('close', 'signup-rule-icon'); ?> Match</li>
                        </ul>
                    </div>

                    <button type="submit" class="button-primary">Sign Up</button>
                </form>

                <p class="footer-link">Already have an account? <a href="login.php">Sign In</a></p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const step1 = document.getElementById('step-1');
            const step2 = document.getElementById('step-2');
            const nextBtn = document.getElementById('goToStep2');
            const backBtn = document.getElementById('backToStep1');
            const loginBackBtn = document.getElementById('backToLogin');
            const selectedAccountInput = document.getElementById('selected_account_type');
            const stepDots = document.querySelectorAll('.step-dot');
            const radios = document.querySelectorAll('input[name="account_type_step"]');
            const step1Error = document.getElementById('step1-error');

            function showStep1Error(message) {
                if (!step1Error) return;
                step1Error.textContent = message;
                step1Error.classList.add('show');
                step1Error.hidden = false;
            }

            function clearStep1Error() {
                if (!step1Error) return;
                step1Error.textContent = '';
                step1Error.classList.remove('show');
                step1Error.hidden = true;
            }

            function goToStep(stepNumber) {
                if (stepNumber === 1) {
                    step2.classList.remove('active');
                    step1.classList.add('active');
                    stepDots[1].classList.remove('active');
                    stepDots[0].classList.add('active');
                    return;
                }

                step1.classList.remove('active');
                step2.classList.add('active');
                stepDots[0].classList.remove('active');
                stepDots[1].classList.add('active');
            }

            function updateSelection() {
                const selected = [...radios].find(radio => radio.checked);
                nextBtn.disabled = !selected;
                if (selected) {
                    selectedAccountInput.value = selected.value;
                    clearStep1Error();
                }
                document.querySelectorAll('.relationship-option').forEach(option => {
                    option.classList.toggle('selected', option.querySelector('input').checked);
                });
            }

            radios.forEach(radio => radio.addEventListener('change', updateSelection));
            updateSelection();

            nextBtn.addEventListener('click', function() {
                const selected = [...radios].find(radio => radio.checked);
                if (!selected) {
                    showStep1Error('Please choose 1 option before continuing.');
                    return;
                }
                clearStep1Error();
                selectedAccountInput.value = selected.value;
                goToStep(2);
            });

            if (loginBackBtn) {
                loginBackBtn.addEventListener('click', function() {
                    window.location.href = 'login.php';
                });
            }

            if (backBtn) {
                backBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    goToStep(1);
                });
            }

            const contactNumber = document.getElementById('contact_number');
            if (contactNumber) {
                function checkContactNumber() {
                    const digitsOnly = contactNumber.value.replace(/\D/g, '').slice(0, 11);
                    if (digitsOnly !== contactNumber.value) {
                        contactNumber.value = digitsOnly;
                    }
                    const isIncomplete = digitsOnly.length > 0 && digitsOnly.length < 11;
                    contactNumber.classList.toggle('input-incomplete', isIncomplete);
                }

                contactNumber.addEventListener('input', checkContactNumber);
                contactNumber.addEventListener('blur', checkContactNumber);
            }

            const pwd = document.getElementById('password');
            const confPwd = document.getElementById('confirm_password');

            if (pwd && confPwd) {
                const reqLength = document.getElementById('req_length');
                const reqCase = document.getElementById('req_case');
                const reqNum = document.getElementById('req_num');
                const reqSpec = document.getElementById('req_spec');
                const reqMatch = document.getElementById('req_match');
                const eyeOpenSVG = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                const eyeOffSVG = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';

                [pwd, confPwd].forEach(function(input) {
                    const toggle = document.querySelector('.toggle-password[data-target="' + input.id + '"]');
                    if (!toggle) return;

                    function updateVisibility() {
                        toggle.classList.toggle('is-visible', input.value.trim().length > 0);
                    }

                    input.addEventListener('input', updateVisibility);
                    input.addEventListener('focus', updateVisibility);
                    toggle.addEventListener('click', function() {
                        const svg = toggle.querySelector('svg');
                        const isHidden = input.type === 'password';
                        input.type = isHidden ? 'text' : 'password';
                        toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                        svg.innerHTML = isHidden ? eyeOpenSVG : eyeOffSVG;
                        input.focus();
                    });
                });

                function toggleValid(element, isValid) {
                    const icon = element.querySelector('span');
                    if (isValid) {
                        element.classList.remove('invalid');
                        element.classList.add('valid');
                        icon.innerHTML = '<svg class="system-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';
                    } else {
                        element.classList.remove('valid');
                        element.classList.add('invalid');
                        icon.innerHTML = '<svg class="system-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
                    }
                }

                function checkPassword() {
                    const val = pwd.value;
                    const confVal = confPwd.value;

                    toggleValid(reqLength, val.length >= 8);
                    toggleValid(reqCase, /(?=.*[a-z])(?=.*[A-Z])/.test(val));
                    toggleValid(reqNum, /(?=.*\d)/.test(val));
                    toggleValid(reqSpec, /(?=.*[\W_])/.test(val));
                    toggleValid(reqMatch, val === confVal && val !== '');
                }

                pwd.addEventListener('input', checkPassword);
                confPwd.addEventListener('input', checkPassword);
            }
        });
    </script>
</body>
</html>