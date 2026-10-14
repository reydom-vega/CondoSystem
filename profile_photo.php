<?php
require_once __DIR__.'/config.php';
header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
if (!isLoggedIn()) { http_response_code(401); exit; }
$id = filter_var($_GET['user_id'] ?? '', FILTER_VALIDATE_INT);
if (!$id || ($id !== (int)$_SESSION['user_id'] && !digitalIdCanReview())) { http_response_code(404); exit; }
$db = connectDb();
if (!profilePhotoSchemaReady($db)) { http_response_code(404); exit; }
$stmt=$db->prepare('SELECT profile_photo FROM users WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute();
$filename=$stmt->get_result()->fetch_assoc()['profile_photo'] ?? null;
if (!profilePhotoFilename($filename) || !is_string($_GET['v'] ?? null) || !hash_equals(hash('sha256',$filename),$_GET['v'])) { http_response_code(404); exit; }
$file=profilePhotoDirectory().'/'.$filename;
if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg'); header('Content-Length: '.filesize($file)); header('Content-Disposition: inline; filename="profile.jpg"');
readfile($file);
