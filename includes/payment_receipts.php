<?php
/** One paid-bill data source for email receipts and the existing protected PDF. */
function paymentReceiptData(mysqli $db, int $paymentId): ?array {
    $find=$db->prepare("SELECT p.*,u.full_name,u.email,u.unit_number FROM payments p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status='paid' LIMIT 1");
    $find->bind_param('i',$paymentId);$find->execute();$receipt=$find->get_result()->fetch_assoc();
    if (!$receipt) return null;
    // No schema helpers here: enqueue runs inside the settlement transaction.
    $items=$db->prepare('SELECT category,description,amount FROM bill_items WHERE payment_id=? ORDER BY id');
    $items->bind_param('i',$paymentId);$items->execute();
    $receipt['items']=$items->get_result()->fetch_all(MYSQLI_ASSOC) ?: [['category'=>'Payment','description'=>'','amount'=>$receipt['amount']]];
    $receipt['visitor_parking']=vpBillDetails($db,$paymentId);
    if($receipt['visitor_parking'])$receipt['unit_number']=$receipt['visitor_parking']['sponsor_unit_snapshot'];
    $receipt['reference']='DUES-'.$paymentId;
    $receipt['paid_date_label']=date('F j, Y g:i A',strtotime($receipt['paid_at'] ?: $receipt['created_at']));
    $receipt['channel_label']=paymentChannelLabel($receipt['payment_channel'] ?? null,$receipt['payment_method'] ?? null);
    $receipt['period_label']=date('F Y',strtotime($receipt['billing_period_start'] ?: ($receipt['paid_at'] ?: $receipt['created_at'])));
    return $receipt;
}

/** PayMongo-style receipt information with Celandine branding; no provider impersonation. */
function paymentReceiptEmail(array $receipt, array $recipient): string {
    $e=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    $money=static fn($value):string=>'PHP '.number_format((float)$value,2);
    $rows='';
    foreach ($receipt['items'] as $item) {
        $description=!empty($item['description']) ? '<br><span style="color:#64748b;font-size:12px">'.$e($item['description']).'</span>' : '';
        $rows.='<tr><td style="padding:14px 0;border-bottom:1px solid #e2e8f0">'.$e($item['category']).$description.'</td><td align="right" style="padding:14px 0;border-bottom:1px solid #e2e8f0;white-space:nowrap">'.$money($item['amount']).'</td></tr>';
    }
    $details=['Reference'=>$receipt['reference'],'Resident'=>$receipt['full_name'],'Unit'=>$receipt['unit_number'] ?: 'Not assigned','Payment date'=>$receipt['paid_date_label'],'Payment method'=>$receipt['channel_label'],'Billing period'=>$receipt['period_label']];
    if (!empty($receipt['paymongo_payment_id'])) $details['PayMongo payment ID']=$receipt['paymongo_payment_id'];
    if(!empty($receipt['visitor_parking']))$details+=vpReceiptFields($receipt['visitor_parking']);
    $metadata='';
    foreach ($details as $title=>$value) $metadata.='<tr><td style="padding:6px 12px 6px 0;color:#64748b;vertical-align:top">'.$e($title).'</td><td style="padding:6px 0;word-break:break-word">'.$e($value).'</td></tr>';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment receipt '.$e($receipt['reference']).'</title></head>'
        .'<body style="margin:0;background:#f1f5f9;color:#192333;font-family:Arial,sans-serif;line-height:1.6"><div style="display:none;max-height:0;overflow:hidden">Payment received: '.$money($receipt['amount']).' · '.$e($receipt['reference']).'</div>'
        .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #e2e8f0;border-radius:12px"><tr><td style="padding:28px;border-bottom:3px solid #d97706">'.brandEmailHtml('light').brandPropertyAddressHtml(true).'</td></tr>'
        .'<tr><td style="padding:28px"><p>Hi '.$e($recipient['full_name']).', thank you. Your payment has been received.</p>'
        .'<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:20px;margin:22px 0"><span style="color:#166534;font-size:12px;font-weight:bold">PAID</span><br><span style="font-size:30px;font-weight:bold;color:#b45309">'.$money($receipt['amount']).'</span></div>'
        .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px">'.$metadata.'</table>'
        .'<h2 style="font-size:18px;margin:28px 0 8px">Payment breakdown</h2><table width="100%" cellspacing="0" cellpadding="0" style="font-size:14px"><thead><tr><th align="left" style="color:#64748b;padding:8px 0">Charge</th><th align="right" style="color:#64748b;padding:8px 0">Amount</th></tr></thead><tbody>'.$rows.'<tr><td style="padding:18px 0;font-weight:bold">Total paid</td><td align="right" style="padding:18px 0;font-weight:bold;white-space:nowrap">'.$money($receipt['amount']).'</td></tr></tbody></table>'
        .'<p style="color:#64748b;font-size:12px">'.(!empty($receipt['paymongo_payment_id'])?'Payment processed through PayMongo. This receipt is issued by The Celandine using the confirmed payment record.':'This receipt confirms payment recorded by The Celandine.').'</p>'
        .'<p style="border-top:1px solid #e2e8f0;padding-top:16px;margin-top:24px;color:#64748b;font-size:11px">Automated payment receipt. For questions, contact property management through your resident portal.</p></td></tr></table></td></tr></table></body></html>';
}
