<?php
require_once 'config.php';
if (isLoggedIn()) {
    redirect(dashboardPathForRole());
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = !empty($_POST['remember_me']);
    
    if ($identifier === '' || $password === '') {
        $errors[] = getLoginErrorMessage('empty');
    } else {
        $connection = connectDb();
        $user = authenticateCredentials($connection, $identifier, $password);
        if ($user) {
                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['unit_number'] = $user['unit_number']; 
                    $_SESSION['role'] = $user['role'] ?? 'resident';
                    $_SESSION['session_version'] = (int)$user['session_version'];
                    refreshSession();
                    
                    trackEvent('user_login', $user['role'] ?? 'resident', (int)$user['id']);
                    logAudit('login', 'authentication', (int)$user['id'], 'Successful login');

                    if ($rememberMe) {
                        issueRememberToken((int)$user['id']);
                    } else {
                        forgetRememberToken();
                    }

                    if (($user['role'] ?? 'resident') === 'resident' && !isApproved()) {
                        redirect('signuppending.php');
                    }

                    redirect(dashboardPathForRole());
        } else {
            $errors[] = 'Unable to sign in. Check your credentials, or try again in 15 minutes. Contact management if your account is unavailable.';
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
    <link rel="stylesheet" href="styles.css?v=2">
    <script src="js/page-transition.js?v=2" defer></script>
</head>
<body>
    <div class="page-transition" aria-hidden="true"></div>
    <div class="card split-card login-screen">
        <div class="form-panel">
            <div class="brand">
                <?php include 'buildingicon.php'; ?>
                <div class="brand-title">The Celandine<br>Residences</div>
            </div>
            <a class="login-back-button" href="homepage.php" data-screen-transition>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
                <span>Back to Home</span>
            </a>
            <h1>Welcome</h1>
            <p class="subtitle">Enter your credentials to access your portal</p>
            
            <?php $flash = getFlash(); if ($flash): ?>
                <div class="alert <?php echo htmlspecialchars($flash['type'] === 'error' ? 'error' : 'success'); ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php">
                <?php echo workflowCsrfField(); ?>
                <div class="input-wrap">
                    <label class="field-label" for="identifier">Username or Email</label>
                    <input type="text" id="identifier" name="identifier" placeholder="Username or email" value="<?php echo htmlspecialchars($identifier ?? ''); ?>" required>
                </div>
                
                <label class="field-label" for="password">Password</label>
                <div class="input-wrap password-container">
                    <input type="password" id="password" name="password" placeholder="Password" required>
                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                </div>

                <div class="form-options">
                    <label class="checkbox">
                        <input type="checkbox" name="remember_me" checked>
                        <span>Remember Me</span>
                    </label>
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>
                <button type="submit">Log In</button>
            </form>
            <p class="footer-link">Don't have an account? <a href="signup.php">Sign up</a></p>
            <p class="footer-link"><a href="resend_verification.php">Resend verification email</a></p>
        </div>

        <div class="panel-image">
            <img src="IMAGES/images.jpg" alt="The Celandine Building" class="slide">
            <img src="IMAGES/ThePatio.webp" alt="The Patio" class="slide">
            <img src="IMAGES/TheLobby.webp" alt="The Lobby" class="slide">
            <img src="IMAGES/TheLounge.webp" alt="The Lounge" class="slide">
            <img src="IMAGES/TheGardens.webp" alt="The Gardens" class="slide">
            <img src="IMAGES/ThePool.webp" alt="The Pool" class="slide">
            <img src="IMAGES/TheEntranceGate.webp" alt="The Entrance Gate" class="slide">
            <img src="IMAGES/TheCourt.webp" alt="The Court" class="slide">
            <div class="image-caption">
                <h2>The Celandine</h2>
                <p>Quezon City</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const REMEMBERED_IDENTIFIER_KEY = 'celandine_remembered_identifier';
            const identifierInput = document.getElementById('identifier');
            const rememberCheckbox = document.querySelector('input[name="remember_me"]');
            const loginForm = identifierInput ? identifierInput.closest('form') : null;

            if (identifierInput && rememberCheckbox) {
                if (!identifierInput.value) {
                    const saved = localStorage.getItem(REMEMBERED_IDENTIFIER_KEY);
                    if (saved) {
                        identifierInput.value = saved;
                        rememberCheckbox.checked = true;
                    }
                }

                if (loginForm) {
                    loginForm.addEventListener('submit', function() {
                        if (rememberCheckbox.checked && identifierInput.value.trim() !== '') {
                            localStorage.setItem(REMEMBERED_IDENTIFIER_KEY, identifierInput.value.trim());
                        } else {
                            localStorage.removeItem(REMEMBERED_IDENTIFIER_KEY);
                        }
                    });
                }
            }

            const loginPassword = document.getElementById('password');
            const loginToggle = document.querySelector('.toggle-password[data-target="password"]');
            if (loginPassword && loginToggle) {
                const loginIcon = loginToggle.querySelector('svg');
                const eyeOpen = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                const eyeClosed = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                loginPassword.addEventListener('input', () => loginToggle.classList.toggle('is-visible', loginPassword.value.length > 0));
                loginToggle.addEventListener('click', (event) => {
                    event.preventDefault();
                    const isHidden = loginPassword.type === 'password';
                    loginPassword.type = isHidden ? 'text' : 'password';
                    loginToggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                    loginIcon.innerHTML = isHidden ? eyeOpen : eyeClosed;
                    loginPassword.focus();
                });
            }
        });
    </script>
</body>
</html>
