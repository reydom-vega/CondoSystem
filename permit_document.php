<?php
require_once __DIR__.'/config.php';
if (!isLoggedIn()) { http_response_code(403); exit('Access denied.'); }
$db=connectDb(); ensureResidentServicesTables($db);
$id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT);
$stmt=$db->prepare('SELECT * FROM permit_documents WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $doc=$stmt->get_result()->fetch_assoc();
$row=$doc ? gateRequest($db,(int)$doc['request_id']) : null;
if (!$row || !gateCanView($db,$row) || !preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D',$doc['stored_name'])) { http_response_code(404); exit('Document not found.'); }
$root=realpath(__DIR__.'/private_uploads/permit_documents'); $path=$root?realpath($root.'/'.$doc['stored_name']):false;
if (!$path || !is_file($path) || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/')) { http_response_code(404); exit('Document not found.'); }
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime,['image/jpeg','image/png','application/pdf'],true)) { http_response_code(404); exit('Document not found.'); }
header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
header("Content-Security-Policy: sandbox");
header('Content-Disposition: inline; filename="permit-document-'.$id.'.'.pathinfo($doc['stored_name'],PATHINFO_EXTENSION).'"'); readfile($path);
