<?php
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('security.gate');

$scannerRoleLabel = isSuperAdmin() ? 'Superadmin' : 'Security';
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
    <link rel="stylesheet" href="../<?php echo isSuperAdmin() ? 'styles.css' : 'security.css'; ?>">
<link rel="stylesheet" href="../scanner.css">
<link rel="stylesheet" href="../services.css?v=<?php echo filemtime(__DIR__ . '/../services.css'); ?>"></head>
<body class="dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
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

            <section class="scanner-layout">
                <div class="admin-panel scanner-panel">
                    <h2>Scan QR Code</h2>
                    <div id="reader"></div>
                    <div class="scanner-actions">
                        <button type="button" id="startScanner">Start Camera</button>
                        <button type="button" id="stopScanner">Stop Camera</button>
                    </div>
                    <p class="scanner-status" id="scannerStatus">Camera is stopped. Press Start Camera and allow camera access.</p>
                </div>
                <div class="admin-panel scanner-panel">
                    <h2>Scan Result</h2>
                    <div class="scanner-result"><strong>Detected content</strong><span id="scanResult">No QR code scanned yet.</span></div>
                    <a id="openResult" class="scanner-link" target="_blank" rel="noopener noreferrer">View verified pass details</a>
                    <form id="manualScanForm" class="service-actions"><label class="field-label" for="manualScan">Scan or paste a pass URL</label><input id="manualScan" type="text" maxlength="4096" required><button class="service-btn" type="submit">Verify pass</button></form>
                </div>
            </section>
            <section class="admin-panel scanner-history-panel">
                <div class="scanner-history-heading">
                    <div>
                        <h2>Scan History</h2>
                        <p>Recent QR scans with the scanned content, scanner, type, and time.</p>
                    </div>
                    <button type="button" id="refreshHistory">Refresh</button>
                </div>
                <div class="scanner-history-wrap">
                    <table class="scanner-history-table">
                        <thead>
                            <tr><th>Scanned Content</th><th>Type</th><th>Scanned By</th><th>Date &amp; Time</th></tr>
                        </thead>
                        <tbody id="scanHistoryBody">
                            <tr><td colspan="4">Loading scan history...</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script src="../js/profile-menu.js"></script>
    <script src="../js/notification-menu.js"></script>
    <script src="https://unpkg.com/html5-qrcode" defer></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });

        let scanner;
        let scannerRunning = false;
        let lastSavedContent = '';
        let lastSavedAt = 0;
        let scanBusy = false;
        const status = document.getElementById('scannerStatus');
        const result = document.getElementById('scanResult');
        const openResult = document.getElementById('openResult');
        const historyBody = document.getElementById('scanHistoryBody');
        const historyEndpoint = '../api/scan_history.php';

        function setStatus(message) { status.textContent = message; }
        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
        }
        async function loadScanHistory() {
            historyBody.innerHTML = '<tr><td colspan="4">Loading scan history...</td></tr>';
            try {
                const response = await fetch(historyEndpoint, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Unable to load history.');
                if (!data.records.length) {
                    historyBody.innerHTML = '<tr><td colspan="4">No scans have been recorded yet.</td></tr>';
                    return;
                }
                historyBody.innerHTML = data.records.map(record => {
                    const scannedAt = new Date(record.scanned_at.replace(' ', 'T'));
                    const formattedDate = Number.isNaN(scannedAt.getTime()) ? record.scanned_at : scannedAt.toLocaleString();
                    return `<tr><td class="scanner-history-content">${escapeHtml(record.scanned_content)}</td><td>${escapeHtml(record.content_type)}</td><td>${escapeHtml(record.username)}</td><td>${escapeHtml(formattedDate)}</td></tr>`;
                }).join('');
            } catch (error) {
                historyBody.innerHTML = '<tr><td colspan="4">Could not load scan history. Use Refresh to try again.</td></tr>';
            }
        }
        async function saveScan(decodedText) {
            const now = Date.now();
            if (decodedText === lastSavedContent && now - lastSavedAt < 5000) return null;
            lastSavedContent = decodedText;
            lastSavedAt = now;
            try {
                const response = await fetch(historyEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ content: decodedText, csrf_token: <?php echo json_encode(workflowCsrfToken()); ?> })
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Unable to save this scan.');
                loadScanHistory();
                return data.verification;
            } catch (error) {
                lastSavedContent = '';
                setStatus('QR detected, but scan history could not be saved. Check the connection and try again.');
                return null;
            }
        }
        async function handleScan(decodedText) {
            if (scanBusy || (decodedText === lastSavedContent && Date.now() - lastSavedAt < 5000)) return;
            scanBusy = true;
            openResult.style.display = 'none';
            openResult.removeAttribute('href');
            result.textContent = decodedText;
            try {
                const verification = await saveScan(decodedText);
                if (!verification) return;
                setStatus(verification.message);
                if (verification.recognized && verification.pass_url) {
                    openResult.href = verification.pass_url;
                    openResult.style.display = 'block';
                }
            } finally { scanBusy = false; }
        }
        async function startScanner() {
            if (scannerRunning) return;
            if (typeof Html5Qrcode === 'undefined') {
                setStatus('Scanner library is still loading. Check your internet connection and try again.');
                return;
            }
            scanner = scanner || new Html5Qrcode('reader');
            try {
                await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 250 } }, handleScan, () => {});
                scannerRunning = true;
                setStatus('Camera active. Point the camera at a QR code.');
            } catch (error) {
                setStatus('Camera could not start. Allow camera permission and use HTTPS or localhost.');
            }
        }
        async function stopScanner() {
            if (!scanner || !scannerRunning) return;
            try { await scanner.stop(); scannerRunning = false; setStatus('Camera stopped.'); } catch (error) { setStatus('Unable to stop the camera cleanly.'); }
        }
        document.getElementById('startScanner').addEventListener('click', startScanner);
        document.getElementById('stopScanner').addEventListener('click', stopScanner);
        document.getElementById('refreshHistory').addEventListener('click', loadScanHistory);
        document.getElementById('manualScanForm').addEventListener('submit', event => { event.preventDefault(); lastSavedContent = ''; handleScan(document.getElementById('manualScan').value.trim()); });
        loadScanHistory();
    </script>
</body>
</html>
