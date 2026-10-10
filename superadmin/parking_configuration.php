<?php
require_once '../config.php';
if (!isLoggedIn()) redirect('../login.php');
if (!isSuperAdmin()) { http_response_code(403); exit('Access denied.'); }
$db = connectDb();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    if (saveParkingPolicy($db, $_POST)) { logAudit('configure','parking_policy',1,'Updated parking sticker price, order limit, and visitor duration'); setFlash('success','Parking configuration saved.'); redirect('parking_configuration.php'); }
    $error = 'Price must be 1–100,000, quantity 1–100, and visitor duration 1–30 days.';
}
$policy = getParkingPolicy($db); $flash = getFlash();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Parking Configuration</title><link rel="stylesheet" href="../styles.css"><link rel="stylesheet" href="../services.css"><?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page"><div class="dash-layout">
<aside class="sidebar" id="sidebar"><a class="sidebar-brand" href="<?= htmlspecialchars(buildUrl(dashboardPathForRole()),ENT_QUOTES,'UTF-8') ?>"><?php include __DIR__.'/../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a><nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav></aside><div class="sidebar-overlay" id="sidebarOverlay"></div>
<main class="dashboard-main"><?php renderPortalWorkspaceHeader('Parking Configuration'); ?>
<section class="service-panel parking-policy-panel"><h2>Parking rules &amp; pricing</h2><p class="panel-subtext">Changes apply to new sticker bills and parking requests. Existing bill amounts stay as issued.</p>
<?php if ($error): ?><div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?><?php if ($flash): ?><div class="alert success" role="status"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>
<form method="post" class="service-form"><?= workflowCsrfField() ?><label class="field-label">Sticker price (PHP)<input type="number" name="sticker_price" min="1" max="100000" step="0.01" value="<?= htmlspecialchars($policy['sticker_price']) ?>" required></label><label class="field-label">Maximum stickers per order<input type="number" name="sticker_max_quantity" min="1" max="100" value="<?= (int)$policy['sticker_max_quantity'] ?>" required></label><label class="field-label">Maximum visitor parking days<input type="number" name="visitor_max_days" min="1" max="30" value="<?= (int)$policy['visitor_max_days'] ?>" required></label><div class="service-wide service-submit"><button type="submit">Save configuration</button><a class="service-btn service-btn-secondary" href="parking.php">Parking requests</a><a class="service-btn service-btn-secondary" href="parkinginventory.php">Parking inventory</a></div></form></section></main></div><script src="../js/services-menu.js"></script></body></html>
