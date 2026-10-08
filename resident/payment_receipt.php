<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect('../resident/dashboard.php');
}
requireApproval();

$userId = (int)$_SESSION['user_id'];
$paymentId = (int)($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit('Receipt not found.');
}

$connection = connectDb();
$actorContext=residentContext($connection,$userId);
if (!$actorContext || !$actorContext['approved'] || !in_array($actorContext['account_kind'],['owner','tenant'],true)) {
    http_response_code(403); exit('Only the approved unit owner can download payment receipts.');
}
ensurePaymongoColumns($connection);
ensureBillingTables($connection);
if ($actorContext['account_kind']==='tenant') {
    $check=$connection->prepare('SELECT user_id FROM payments WHERE id=?');
    $check->bind_param('i',$paymentId); $check->execute(); $tenantBill=$check->get_result()->fetch_assoc();
    if (!$tenantBill || !residentCanPayBill($connection,$userId,(int)$tenantBill['user_id'],$paymentId)) { http_response_code(403); exit('You can download receipts only for your own parking bills.'); }
}
$stmt = $connection->prepare("SELECT p.*, u.full_name, u.email, u.unit_number FROM payments p INNER JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.status = 'paid' LIMIT 1");
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
if (!$receipt || !residentCanPayBill($connection,$userId,(int)$receipt['user_id'],$paymentId)) {
    http_response_code(404);
    exit('Receipt not found.');
}

$receipt['items'] = getBillItems($connection, $paymentId);
if (!$receipt['items']) {
    $receipt['items'] = [['category' => 'Payment', 'description' => '', 'amount' => $receipt['amount']]];
}
$paidAt = $receipt['paid_at'] ?: $receipt['created_at'];
$paymentMethod = paymentChannelLabel($receipt['payment_channel'] ?? null, $receipt['payment_method'] ?? null);
$billingPeriod = !empty($receipt['billing_period_start'])
    ? date('F Y', strtotime($receipt['billing_period_start']))
    : date('F Y', strtotime($paidAt));
$receiptReference = 'DUES-' . $paymentId;
$brandImagePath = __DIR__ . '/../assets/building-icon.jpg';
$brandImage = is_file($brandImagePath)
    ? 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($brandImagePath))
    : '';

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; color: #192333; font: 12px/1.5 "DejaVu Sans", sans-serif; }
        .receipt { padding: 32px; }
        .brand { width: 100%; padding-bottom: 16px; border-bottom: 2px solid #e6a440; }
        .brand td { vertical-align: middle; }
        .brand img { width: 42px; height: 42px; margin-right: 12px; }
        .brand-name { font-size: 15px; font-weight: bold; }
        .muted { color: #657186; font-size: 10px; }
        h1 { margin: 22px 0 4px; font-size: 24px; }
        .meta { width: 100%; margin: 20px 0 24px; border-collapse: collapse; background: #f6f8fb; }
        .meta td { width: 50%; padding: 10px 12px; border: 1px solid #e3e8ef; vertical-align: top; }
        .label { display: block; color: #657186; font-size: 9px; text-transform: uppercase; }
        .value { display: block; margin-top: 3px; font-weight: bold; overflow-wrap: anywhere; }
        .items { width: 100%; border-collapse: collapse; }
        .items th, .items td { padding: 10px 8px; border-bottom: 1px solid #e5e9ef; text-align: left; }
        .items th { color: #657186; font-size: 9px; text-transform: uppercase; }
        .amount { text-align: right !important; white-space: nowrap; }
        .total td { padding-top: 14px; border-bottom: 0; font-size: 16px; font-weight: bold; }
        .total .amount { color: #a96800; }
        .footer { margin-top: 28px; padding-top: 14px; border-top: 1px solid #e5e9ef; color: #657186; font-size: 9px; }
    </style>
</head>
<body>
    <div class="receipt">
        <table class="brand"><tr>
            <?php if ($brandImage !== ''): ?><td style="width:54px"><img src="<?php echo $brandImage; ?>" alt=""></td><?php endif; ?>
            <td><div class="brand-name">CELANDINE RESIDENCES</div><div class="muted">Official payment receipt</div></td>
            <td style="text-align:right;color:#187344;font-weight:bold">PAID</td>
        </tr></table>
        <h1>Payment Receipt</h1>
        <div class="muted">Receipt <?php echo htmlspecialchars($receiptReference); ?></div>
        <table class="meta">
            <tr><td><span class="label">Resident</span><span class="value"><?php echo htmlspecialchars($receipt['full_name']); ?></span></td><td><span class="label">Unit</span><span class="value"><?php echo htmlspecialchars($receipt['unit_number'] ?: 'Not assigned'); ?></span></td></tr>
            <tr><td><span class="label">Payment date</span><span class="value"><?php echo htmlspecialchars(date('F j, Y g:i A', strtotime($paidAt))); ?></span></td><td><span class="label">Paid via</span><span class="value"><?php echo htmlspecialchars($paymentMethod); ?></span></td></tr>
            <tr><td><span class="label">Billing period</span><span class="value"><?php echo htmlspecialchars($billingPeriod); ?></span></td><td><span class="label">Due date</span><span class="value"><?php echo !empty($receipt['due_date']) ? htmlspecialchars(date('F j, Y', strtotime($receipt['due_date']))) : 'Not recorded'; ?></span></td></tr>
            <tr><td><span class="label">Payment reference</span><span class="value"><?php echo htmlspecialchars($receiptReference); ?></span></td><td><span class="label">Payment ID</span><span class="value"><?php echo htmlspecialchars($receipt['paymongo_payment_id'] ?: 'Not applicable'); ?></span></td></tr>
        </table>
        <table class="items">
            <thead><tr><th>Charge</th><th class="amount">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($receipt['items'] as $item): ?>
                    <tr><td><?php echo htmlspecialchars($item['category']); ?><?php if (!empty($item['description'])): ?> — <?php echo htmlspecialchars($item['description']); ?><?php endif; ?></td><td class="amount">₱<?php echo number_format((float)$item['amount'], 2); ?></td></tr>
                <?php endforeach; ?>
                <tr class="total"><td>Total Paid</td><td class="amount">₱<?php echo number_format((float)$receipt['amount'], 2); ?></td></tr>
            </tbody>
        </table>
        <div class="footer">This receipt confirms payment recorded by Celandine Residences. Keep the reference number for your records.</div>
    </div>
</body>
</html>
<?php
$html = (string)ob_get_clean();
$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('receipt-' . $receiptReference . '.pdf', ['Attachment' => true]);
exit;
