<?php
require_once __DIR__ . '/config.php';
if (!isLoggedIn()) { http_response_code(403); exit('Access denied.'); }
$db=connectDb(); ensureVehiclesTable($db);
$id=(int)($_GET['vehicle_id']??0);
$stmt=$db->prepare('SELECT * FROM vehicles WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $vehicle=$stmt->get_result()->fetch_assoc();
if (!$vehicle || ((int)$vehicle['user_id']!==(int)$_SESSION['user_id'] && !canReviewPermits())) { http_response_code(404); exit('Document not found.'); }
if ((int)$vehicle['user_id']===(int)$_SESSION['user_id'] && !isApproved()) { http_response_code(403); exit('Access denied.'); }
if ((int)$vehicle['user_id']===(int)$_SESSION['user_id']) requireResidentPermission('resident.vehicles.register');
$kind=$_GET['document']??'or_cr';
if (!in_array($kind,['or_cr','photo'],true)) { http_response_code(404); exit('Document not found.'); }
$stored=$vehicle[$kind==='photo'?'vehicle_photo_path':'or_cr_path']??'';
$root=realpath(__DIR__ . '/private_uploads');
$path=$stored ? realpath(__DIR__ . '/' . $stored) : false;
if (!$root || !$path || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/') || !is_file($path)) { http_response_code(404); exit('Document not found.'); }
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) { http_response_code(404); exit('Document not found.'); }
header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="vehicle-'.$id.'-'.($kind==='photo'?'photo':'or-cr').($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg')).'"');
readfile($path);
