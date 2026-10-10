<?php
require_once 'config.php';
$loginJsonRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$finishLogin = static function (string $target, bool $saveCredentials = false) use ($loginJsonRequest): void {
    if ($loginJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['redirect' => $target, 'save_credentials' => $saveCredentials], JSON_THROW_ON_ERROR);
        exit;
    }
    redirect($target);
};
if (isLoggedIn()) {
    $finishLogin(dashboardPathForRole());
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
                $finishLogin('signuppending.php', $rememberMe);
            }

            $finishLogin(dashboardPathForRole(), $rememberMe);
        } else {
            $errors[] = 'Unable to sign in. Check your credentials, or try again in 15 minutes. Contact management if your account is unavailable.';
        }
    }
    if ($loginJsonRequest) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['errors' => $errors], JSON_THROW_ON_ERROR);
        exit;
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
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui portal-account-page">
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

                <div id="loginFeedback" class="alert <?= empty($errors) ? '' : 'error' ?>" role="alert" tabindex="-1" <?= empty($errors) ? 'hidden' : '' ?>>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

            <form id="loginForm" method="post" action="login.php" autocomplete="on" data-submitted="<?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'true' : 'false' ?>">
                <?php echo workflowCsrfField(); ?>
                <div class="input-wrap">
                    <label class="field-label" for="identifier">Username or Email</label>
                    <input type="text" id="identifier" name="identifier" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Username or email" value="<?php echo htmlspecialchars($identifier ?? ''); ?>" required>
                </div>

                <label class="field-label" for="password">Password</label>
                <div class="input-wrap password-container">
                    <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Password" required>
                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg></button>
                </div>

                <div class="form-options">
                    <label class="checkbox">
                        <input type="checkbox" id="rememberMe" name="remember_me" value="1" aria-describedby="rememberMeHelp" <?= $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($rememberMe) ? 'checked' : '' ?>>
                        <span>Remember Me</span>
                    </label>
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>
                <button type="submit">Log In</button>
            </form>
            <p class="footer-link">Don't have an account? <a href="signup.php">Sign up</a></p>
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

    <script src="js/login.js?v=<?= filemtime(__DIR__ . '/js/login.js') ?>" defer></script>
</body>
</html>
