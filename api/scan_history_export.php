<?php
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../includes/qr_scan_report.php';
requireCapability('scan_history.export',true);
if($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit('Method not allowed.'); }
try {
    $db=connectDb(); ensureQrScanHistorySchema($db); $scanner=qrScannerIdentity($db);
    $filters=qrHistoryFilters($_GET); $report=qrReportData($db,$filters); $pdf=qrReportPdf($report,$filters,$scanner);
    header('Content-Type: application/pdf'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="qr_scan_history_'.$filters['dates']['start'].'_to_'.$filters['dates']['end'].'.pdf"');
    header('Content-Length: '.strlen($pdf)); echo $pdf;
} catch(InvalidArgumentException $e) { http_response_code(422); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
catch(Throwable $e) { error_log('QR report failed: '.get_class($e)); http_response_code(503); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Unable to generate this report. Please try again.']); }
