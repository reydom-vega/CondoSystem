<?php
/** Download presentation for the existing authenticated permit pass route. */
function permitPassDownloadAllowed(array $row): bool {
    if ($row['request_kind'] !== 'permit' || $row['status'] !== 'approved') return false;
    if (!empty($row['gate_data'])) return gateEffectiveStatus($row) === 'approved' && !empty(gateData($row)['authorized']);
    return $row['end_date'] >= date('Y-m-d');
}

function permitPassDownloadData(mysqli $db, array $row): array {
    $property = !empty($row['gate_data']);
    $data = $property ? gateData($row) : [];
    $fields = [['Requester', $row['full_name']], ['Unit', $data['unit_number'] ?? $row['unit_number']], ['Permit type', $row['permit_type']]];
    if ($property) {
        $schedule = $data['authorized'];
        $fields[] = ['Movement', $data['direction'] === 'entry' ? 'Property Entry (Bring In)' : 'Property Exit (Pull-Out)'];
        $fields[] = ['Purpose', $data['purpose']];
        $fields[] = ['Description', $data['description']];
        $fields[] = ['Authorized schedule', $schedule['date'].' | '.$schedule['start'].' - '.$schedule['end'].' (Asia/Manila)'];
        foreach (['transporter'=>'Transporter','transporter_contact'=>'Transporter contact','vehicle_plate'=>'Vehicle plate','personnel'=>'Authorized personnel'] as $key=>$label) {
            if (!empty($data[$key])) $fields[] = [$label, $data[$key]];
        }
    } else {
        $fields[] = ['Details', $row['details']];
        $fields[] = ['Authorized dates', $row['start_date'].' to '.$row['end_date'].' (Asia/Manila)'];
    }
    if (!empty($row['admin_notes'])) $fields[] = ['Management remarks', $row['admin_notes']];
    if (!empty($row['decided_at'])) $fields[] = ['Reviewed', ($row['approver_name'] ?? 'Management').' | '.$row['decided_at']];
    return [
        'reference'=>$property ? gateNumber((int)$row['id']) : 'PERMIT-'.(int)$row['id'],
        'title'=>$property ? 'Property Gate Pass' : 'Resident Permit',
        'fields'=>array_values(array_filter($fields, static fn(array $field): bool => trim((string)$field[1]) !== '')),
        'items'=>$property ? array_map(static fn(array $item): array => [
            'name'=>$item['item_name'], 'category'=>$item['category'], 'quantity'=>(int)$item['quantity'], 'description'=>$item['description']
        ], gateChildren($db, (int)$row['id'], 'permit_items')) : [],
        'qr_url'=>residentServicePassUrl($row),
        'notice'=>'Valid only during the authorized schedule. Security must verify the current status and physically check all items. This download does not confirm movement.',
        'brand_image'=>buildUrl('assets/branding/email-brand-light.png'),
        'property'=>getPropertyInformation(),
    ];
}

function permitPassPdf(array $document, string $qrImage): string {
    // The QR is a bounded JPEG from the local renderer. No arbitrary URLs/SVGs
    // or uploaded documents enter the PDF; all permit details come from storage.
    if (strlen($qrImage) > 150000 || !preg_match('~\Adata:image/jpeg;base64,([A-Za-z0-9+/=]+)\z~D', $qrImage, $match)) throw new InvalidArgumentException('Unable to read the pass QR code. Please reopen the pass.');
    $bytes = base64_decode($match[1], true);
    $size = $bytes !== false ? @getimagesizefromstring($bytes) : false;
    if (!$size || $size[2] !== IMAGETYPE_JPEG || $size[0] < 80 || $size[0] > 512 || $size[0] !== $size[1]) throw new InvalidArgumentException('Invalid pass QR image.');
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:32px 32px 48px}body{font:11px DejaVu Sans,sans-serif;color:#172033}h1{font-size:23px;margin:22px 0 8px}.reference{color:#657186;margin-bottom:20px}.approved{color:#187344;font-weight:bold}.fields,.items{width:100%;border-collapse:collapse;table-layout:fixed;margin:18px 0}.fields td,.items td,.items th{border:1px solid #d9e0e9;padding:9px;vertical-align:top;word-wrap:break-word}.fields td:first-child{width:28%;color:#526078;background:#f6f8fb}.items th{text-align:left;background:#f6f8fb}tr{page-break-inside:avoid}thead{display:table-header-group}.verification{text-align:center;page-break-inside:avoid;margin-top:24px}.verification img{width:180px;height:180px}.notice{font-size:10px;line-height:1.6;color:#526078}'.brandDocumentStyles().'</style></head><body>'.brandDocumentHtml().'<h1>'.$e($document['title']).'</h1><div class="reference">'.$e($document['reference']).' | <span class="approved">APPROVED</span></div><table class="fields">';
    foreach ($document['fields'] as [$label,$value]) $html .= '<tr><td>'.$e($label).'</td><td>'.nl2br($e($value)).'</td></tr>';
    $html .= '</table>';
    if ($document['items']) {
        $html .= '<h2>Authorized items</h2><table class="items"><thead><tr><th>Item</th><th>Category</th><th>Quantity</th><th>Description</th></tr></thead><tbody>';
        foreach ($document['items'] as $item) $html .= '<tr><td>'.$e($item['name']).'</td><td>'.$e($item['category']).'</td><td>'.$item['quantity'].'</td><td>'.nl2br($e($item['description'])).'</td></tr>';
        $html .= '</tbody></table>';
    }
    $html .= '<div class="verification"><img src="'.$qrImage.'" alt="Verification QR code"><p>Scan to verify the current permit status</p><p class="notice">'.$e($document['notice']).'</p></div></body></html>';
    $options = new \Dompdf\Options(); $options->set('isRemoteEnabled', false); $options->set('isJavascriptEnabled', false);
    $pdf = new \Dompdf\Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4','portrait'); $pdf->render();
    brandPdfFooter($pdf, 'Approved permit');
    return $pdf->output();
}
