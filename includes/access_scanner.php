<?php
/** QR content never grants entry by itself: check the current database state. */
function verifyAccessScan(mysqli $db, string $content): array {
    $parts = parse_url($content);
    $base = parse_url(buildUrl(''));
    $unrecognized = ['recognized'=>false, 'valid'=>false, 'message'=>'Unrecognized QR code. No access authorization.'];
    if (!is_array($parts) || !isset($parts['host'], $parts['scheme'], $parts['path']) || isset($parts['user']) || isset($parts['pass']) || ($parts['host'] !== ($base['host'] ?? '')) || ($parts['scheme'] !== ($base['scheme'] ?? '')) || (($parts['port'] ?? null) !== ($base['port'] ?? null))) return $unrecognized;
    $root = rtrim($base['path'] ?? '', '/');
    parse_str($parts['query'] ?? '', $query);
    $pass = null;
    if ($parts['path'] === $root . '/parking_pass.php') {
        $id = filter_var($query['request_id'] ?? null, FILTER_VALIDATE_INT);
        $signature = $query['signature'] ?? '';
        if ($id && is_string($signature)) $pass = getParkingPass($id, $signature);
        $message = 'Approved visitor parking pass';
    } elseif ($parts['path'] === $root . '/resident_service_pass.php') {
        $id = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT);
        $token = $query['token'] ?? '';
        if ($id && is_string($token)) { ensureResidentServicesTables($db); $pass = getResidentServicePass($db, $id, $token); }
        $message = 'Resident access pass';
    } else { return $unrecognized; }
    if (!$pass) return ['recognized'=>true, 'valid'=>false, 'message'=>'Invalid or revoked access pass.'];
    $status = $pass['status'];
    $valid = $status === 'approved' && $pass['start_date'] <= date('Y-m-d') && ($pass['end_date'] ?: $pass['start_date']) >= date('Y-m-d');
    return ['recognized'=>true, 'valid'=>$valid, 'message'=>$valid ? $message . ' is valid. Verify identity before admitting.' : 'Access refused: pass is outside its dates or its status is ' . str_replace('_',' ',$status) . '.', 'pass_url'=>$content];
}
