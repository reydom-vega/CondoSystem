<?php
require_once __DIR__.'/../config.php';require_once __DIR__.'/../includes/security_dashboard.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
requireCapability('security.dashboard',true);
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['error'=>'Use GET.']);exit;}
try{echo json_encode(['success'=>true,'data'=>securityDashboardData(connectDb(),(int)$_SESSION['user_id'])],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
catch(DomainException $error){http_response_code(403);echo json_encode(['error'=>'Access denied.']);}
catch(Throwable $error){http_response_code(503);error_log('Security dashboard: '.get_class($error));echo json_encode(['error'=>'Dashboard updates are temporarily unavailable. Please try again.']);}
