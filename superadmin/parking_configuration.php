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
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Parking Configuration</title><link rel="stylesheet" href="../styles.css"><link rel="stylesheet" href="../services.css"></head>
<body class="dashboard-page"><main class="service-pass service-panel"><h1>Parking Configuration</h1><p>Changes apply to new sticker bills and parking requests. Existing bill amounts stay as issued.</p>
<?php if ($error): ?><p role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?><?php if ($flash): ?><p role="status"><?= htmlspecialchars($flash['message']) ?></p><?php endif; ?>
<form method="post" class="service-form"><?= workflowCsrfField() ?><label class="field-label">Sticker price (PHP)<input type="number" name="sticker_price" min="1" max="100000" step="0.01" value="<?= htmlspecialchars($policy['sticker_price']) ?>" required></label><label class="field-label">Maximum stickers per order<input type="number" name="sticker_max_quantity" min="1" max="100" value="<?= (int)$policy['sticker_max_quantity'] ?>" required></label><label class="field-label">Maximum visitor parking days<input type="number" name="visitor_max_days" min="1" max="30" value="<?= (int)$policy['visitor_max_days'] ?>" required></label><button>Save configuration</button></form><p><a href="parking.php">Parking requests</a> · <a href="parkinginventory.php">Parking inventory</a></p></main></body></html>
