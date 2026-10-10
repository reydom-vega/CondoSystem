<?php
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('security.gate');

$scannerRoleLabel = ucfirst((string)$_SESSION['role']);
$username = $_SESSION['username'] ?? $scannerRoleLabel;
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - QR Scanner</title>
    <link rel="stylesheet" href="../<?php echo isSecurity() ? 'security.css' : 'styles.css'; ?>">
    <link rel="stylesheet" href="../services.css?v=<?php echo filemtime(__DIR__ . '/../services.css'); ?>">
    <link rel="stylesheet" href="../scanner.css?v=<?php echo filemtime(__DIR__ . '/../scanner.css'); ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page scanner-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main" id="qrScannerApp" data-csrf="<?php echo htmlspecialchars(workflowCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">QR Scanner</h1></div>
                </div>
                <div class="dash-header-right">
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit"><?php echo htmlspecialchars($scannerRoleLabel, ENT_QUOTES, 'UTF-8'); ?></span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <div class="scanner-intro">
                <div><p class="scanner-eyebrow">GATE OPERATIONS</p><h2>Verify visitor and permit passes</h2><p>Scan a visitor or permit QR code, review its status, then open the pass to confirm the appropriate gate action.</p></div>
                <span class="scanner-context">History timezone <strong>Asia/Manila</strong></span>
            </div>
            <div class="qr-tabs" role="tablist" aria-label="QR scanner sections"><button type="button" id="scanTab" role="tab" aria-selected="true" aria-controls="scanPanel" data-scanner-tab="scan">Scan a pass</button><button type="button" id="historyTab" role="tab" aria-selected="false" aria-controls="historyPanel" tabindex="-1" data-scanner-tab="history">Scan history</button></div>
            <section class="scanner-layout" id="scanPanel" role="tabpanel" aria-labelledby="scanTab">
                <div class="admin-panel scanner-panel scanner-camera-panel">
                    <div class="scanner-card-heading"><div><p class="scanner-eyebrow">STEP 01</p><h2>Scan QR code</h2></div><span class="scanner-camera-state" id="cameraState">Camera off</span></div>
                    <p class="scanner-description">Position the entire QR code inside the camera frame.</p>
                    <div class="scanner-viewport">
                        <div id="reader"></div>
                        <div class="scanner-camera-placeholder" id="cameraPlaceholder">
                            <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 8H8v9m23-9h9v9M8 31v9h9m23-9v9h-9"/><rect x="17" y="17" width="14" height="14" rx="2"/><path d="M24 17v14m-7-7h14"/></svg>
                            <strong>Ready when you are</strong><span>Start the camera to scan a visitor or permit QR code.</span>
                        </div>
                    </div>
                    <div class="scanner-actions">
                        <button class="service-btn" type="button" id="startScanner">Start camera</button>
                        <button class="service-btn service-btn-secondary" type="button" id="stopScanner" disabled>Stop camera</button>
                    </div>
                    <p class="scanner-status" id="scannerStatus" role="status" aria-live="polite">Camera is off. Select Start camera and allow camera access.</p>
                    <div class="scanner-supported"><span>Supported passes</span><p>Visitor access &middot; Property gate pass &middot; Visitor parking &middot; Other permits</p></div>
                </div>
                <div class="scanner-review-column">
                    <div class="admin-panel scanner-panel">
                        <div class="scanner-card-heading"><div><p class="scanner-eyebrow">STEP 02</p><h2>Review verification</h2></div></div>
                        <p class="scanner-description">The latest scan result appears here.</p>
                        <div class="scanner-result" id="scanResultCard" data-state="idle" role="status" aria-live="polite" aria-atomic="true">
                            <span class="scanner-result-label" id="scanResultLabel">AWAITING A SCAN</span>
                            <h3 id="scanResult">No pass scanned yet</h3>
                            <p id="scanResultMessage">Use the camera or paste a pass URL below to verify its current status.</p>
                            <dl id="scanResultFields" class="scanner-result-fields" hidden></dl>
                        </div>
                        <a id="openResult" class="scanner-link service-btn" target="_blank" rel="noopener noreferrer">Open pass details <span aria-hidden="true">&nearr;</span></a>
                        <p class="scanner-workflow-note">Scanning records a verification attempt. Confirm entry, exit or permit completion from the pass details after checking the visitor or property.</p>
                    </div>
                    <div class="admin-panel scanner-panel scanner-manual-panel">
                        <h2>Enter a pass manually</h2><p class="scanner-description" id="manualScanHelp">Use a QR reader that pastes text, or copy the full pass URL.</p>
                        <form id="manualScanForm"><label class="field-label" for="manualScan">Pass URL</label><input id="manualScan" type="text" maxlength="4096" placeholder="Paste the complete pass URL" aria-describedby="manualScanHelp" autocomplete="off" spellcheck="false" required><button class="service-btn" type="submit" id="manualScanSubmit">Verify pass</button></form>
                    </div>
                </div>
            </section>
            <?php include __DIR__.'/qr_scan_history_content.php'; ?>
        </main>
    </div>
    <script src="../js/profile-menu.js"></script>
    <script src="../js/notification-menu.js"></script>
    <script src="https://unpkg.com/html5-qrcode" defer></script>
    <script src="../js/services-menu.js"></script><script src="../js/qr-scanner.js?v=<?php echo filemtime(__DIR__.'/../js/qr-scanner.js'); ?>"></script>
</body>
</html>
