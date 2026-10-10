<?php
require_once 'config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    forgetRememberToken();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>$params['path'], 'secure'=>$params['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
    }
    session_destroy();
    redirect('login.php');
}
$returnPath = 'login.php';
$returnLabel = 'Back to sign in';
if (isLoggedIn()) {
    if (($_SESSION['role'] ?? '') === 'resident' && !isApproved()) {
        $returnPath = 'signuppending.php';
        $returnLabel = 'Return to application status';
    } else {
        $returnPath = dashboardPathForRole();
        $returnLabel = 'Return to dashboard';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign out</title><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="services.css"><?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui portal-account-page logout-confirmation"><main class="card"><h1>Sign out of your account?</h1><form method="post"><?php echo workflowCsrfField(); ?><button class="button-link" type="submit">Sign out</button></form><a class="button-link button-link-secondary" href="<?php echo htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8'); ?></a></main></body></html>
