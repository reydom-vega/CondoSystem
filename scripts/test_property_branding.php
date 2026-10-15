<?php
/** Presentation-only fixtures: no database connection and no email/provider calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/permit_pass_export.php';
require_once dirname(__DIR__).'/includes/qr_scan_report.php';
$checks = 0;
function propertyCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: '.$label);
    $checks++;
}
$property = getPropertyInformation();
propertyCheck($property['name'] === 'THE CELANDINE', 'Official property name');
propertyCheck($property['address'] === '1115-A A. Bonifacio Ave., Brgy. Balintawak, Quezon City', 'Complete official address');
propertyCheck(is_file(dirname(__DIR__).'/'.$property['logo']) && is_file(dirname(__DIR__).'/'.$property['logo_dark']), 'Original logo assets exist');
propertyCheck(str_contains(brandDocumentHtml(), $property['address']), 'White paper header contains full address');
propertyCheck(!str_contains(brandHtml(), $property['address']), 'Navigation branding is not enlarged by document address');
foreach (['Bill available', 'Visitor approved', 'Maintenance update', 'Booking approved', 'Violation notice', 'Account verification'] as $notice) {
    $html = emailLayout('Notice', $notice, '<p>Existing message and protected portal link.</p>');
    propertyCheck(substr_count($html, $property['address']) === 1 && str_contains($html, 'Existing message and protected portal link.'), $notice.' has one footer and retains message');
}
$receipt = ['items'=>[['category'=>'Visitor parking', 'description'=>'Verified charge', 'amount'=>'50.00']], 'reference'=>'DUES-TEST', 'full_name'=>'Test Payer', 'unit_number'=>'0101', 'paid_date_label'=>'October 15, 2026 10:00 AM', 'channel_label'=>'Cash', 'period_label'=>'October 2026', 'amount'=>'50.00', 'paymongo_payment_id'=>null];
$html = paymentReceiptEmail($receipt, ['full_name'=>'Test Payer']);
propertyCheck(substr_count($html, $property['address']) === 1 && str_contains($html, 'PHP 50.00') && str_contains($html, 'DUES-TEST'), 'Receipt email preserves verified amount and reference with one address');
propertyCheck(!str_contains($html, 'Download PDF receipt') && !str_contains($html, 'Your receipt from'), 'Previously removed receipt controls stay absent');
// A real bounded JPEG is required by the existing PDF renderer.
$image = imagecreatetruecolor(256,256); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
ob_start(); imagejpeg($image); $qr = 'data:image/jpeg;base64,'.base64_encode(ob_get_clean()); imagedestroy($image);
$document = ['title'=>'Property Gate Pass', 'reference'=>'PGP-TEST', 'fields'=>[['Requester','Test Resident'],['Unit','0101'],['Authorized schedule','2026-10-15 08:00 - 17:00']], 'items'=>[], 'notice'=>'Security must verify the current permit status.'];
$directory = sys_get_temp_dir().'/condo-property-branding-qa';
if (!is_dir($directory)) mkdir($directory,0700);
$pdf = permitPassPdf($document,$qr); propertyCheck(str_starts_with($pdf,'%PDF-'), 'Real approved permit PDF generated');
file_put_contents($directory.'/permit.pdf',$pdf);
$filters = ['dates'=>['start'=>'2026-10-15','end'=>'2026-10-15'], 'qr_type'=>'', 'status'=>'', 'role'=>'', 'q'=>''];
$row = ['scan_reference'=>'QR-TEST', 'qr_type'=>'visitor', 'subject_name'=>'Test Visitor', 'requester_name'=>'Test Resident', 'unit_number'=>'0101', 'pass_number'=>'VIS-TEST', 'local_time'=>'2026-10-15 08:00:00', 'scan_action'=>'verification', 'verification_result'=>'valid', 'scanner_full_name'=>'Test Security', 'scanner_role'=>'security'];
$report = ['summary'=>['total'=>90,'valid'=>90,'invalid'=>0,'expired_cancelled'=>0,'visitor'=>90,'property'=>0], 'rows'=>array_fill(0,90,$row)];
$html = qrReportHtml($report,$filters,['name'=>'Test Security','role'=>'security'],'QRR-TEST','2026-10-15 10:00:00');
propertyCheck(substr_count($html,$property['address']) === 1, 'Scan report address appears per document rather than per record');
$pdf = qrReportPdf($report,$filters,['name'=>'Test Security','role'=>'security']);
propertyCheck(str_starts_with($pdf,'%PDF-') && preg_match_all('~/Type\s*/Page\b~',$pdf)>1, 'Real multipage scan report generated');
file_put_contents($directory.'/scan-history.pdf',$pdf);
$font = dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
$bounds = imagettfbbox(14,0,$font,$property['address']);
propertyCheck($bounds[2]-$bounds[0]<=1080, 'Full image address fits within document margins');
file_put_contents($directory.'/notification.html',emailLayout('Notification','Example notice','<p>Presentation fixture only.</p>'));
file_put_contents($directory.'/receipt-email.html',paymentReceiptEmail($receipt,['full_name'=>'Test Payer']));
// Build complete MIME without a transport call, including the existing CID logo.
foreach (['dark'=>emailLayout('Notice','Test notification','<p>Existing notice.</p>'), 'light'=>paymentReceiptEmail($receipt,['full_name'=>'Test Payer'])] as $theme=>$body) {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8'; $mail->setFrom('noreply@example.invalid', $property['name']);
    $mail->addAddress('recipient@example.invalid'); $mail->Subject = 'Branding fixture';
    $cid = $theme === 'light' ? 'celandine-brand-light' : 'celandine-brand';
    $mail->addEmbeddedImage(dirname(__DIR__).'/assets/branding/email-brand-'.$theme.'.png', $cid, 'the-celandine.png', 'base64', 'image/png');
    $mail->isHTML(true); $mail->Body = $body; $mail->AltBody = strip_tags($body);
    propertyCheck($mail->preSend(), 'Email MIME prepares without sending: '.$theme);
    $mime = $mail->getSentMIMEMessage();
    propertyCheck(str_contains($mime, 'Content-ID: <'.$cid.'>') && str_contains(quoted_printable_decode($mime), $property['address']), 'Email retains embedded branding and address: '.$theme);
}
echo 'Passed '.$checks.' property branding, email and document checks. No database or provider contacted.'.PHP_EOL;
echo 'QA files: '.$directory.PHP_EOL;
