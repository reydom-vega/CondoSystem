<?php
require_once __DIR__ . '/config.php';
requireCapability('visitors.identity.review');
$db = connectDb();
ensureResidentServicesTables($db);
$id = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT);
$stmt = $db->prepare("SELECT * FROM resident_service_requests WHERE id=? AND request_kind='visitor'");
$stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
if(isset($_GET['version'])&&(!$row||!is_string($_GET['version'])||!hash_equals(visitorIdentityVersion($row),$_GET['version']))){header('Cache-Control: private, no-store');http_response_code(409);exit('The ID document changed. Reload the review.');}
$path = $row && empty($row['id_deleted_at']) && !visitorIdExpired($row) ? visitorIdFilePath($row['id_document_name'] ?? '') : null;
if (!$path) { http_response_code(404); exit('Identification document is unavailable or its retention period has ended.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { http_response_code(404); exit('Document unavailable.'); }
logAudit('view', 'visitor_identity', (int)$id, 'Authorized identification document access.');
header('Content-Type: ' . $mime);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="visitor-identification.' . ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] . '"');
readfile($path);
