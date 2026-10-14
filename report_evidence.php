<?php
require_once __DIR__.'/config.php';
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");
try{
    $db=connectDb();$reference=is_string($_GET['reference']??null)?$_GET['reference']:'';$management=($_GET['management']??'0')==='1';$anonymous=($_GET['anonymous']??'0')==='1';$case=reportLoad($db,$reference,$management,$anonymous);
    $id=filter_var($_GET['id']??'',FILTER_VALIDATE_INT);if(!$id)throw new InvalidArgumentException();
    $visibility=$management?'1=1':"e.visibility='reporter'";
    $s=$db->prepare("SELECT a.* FROM report_attachments a JOIN report_events e ON e.id=a.event_id WHERE a.id=? AND a.report_id=? AND a.deleted_at IS NULL AND ($visibility)");$s->bind_param('ii',$id,$case['id']);$s->execute();$file=$s->get_result()->fetch_assoc();
    if(!$file||!($path=reportEvidencePath($file['stored_name']))||!hash_equals($file['content_hash'],hash_file('sha256',$path)))throw new InvalidArgumentException();
    $download=$file['mime']==='application/pdf'||isset($_GET['download']);header('Content-Type: '.$file['mime']);header('Content-Length: '.filesize($path));
    header('Content-Disposition: '.($download?'attachment':'inline').'; filename="evidence'.($file['mime']==='application/pdf'?'.pdf':'.jpg').'"');readfile($path);
}catch(Throwable $error){http_response_code(404);echo 'Evidence not found or access denied.';}
