<?php
/** Read-only readiness checks: no business/schema writes, no provider calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$production = false; $testing = false; $httpUrl = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--production') $production = true;
    elseif ($argument === '--testing') $testing = true;
    elseif (str_starts_with($argument, '--http=')) $httpUrl = rtrim(substr($argument, 7), '/');
    elseif ($argument === '--help') {
        echo "Usage: php scripts/preflight.php [--production|--testing] [--http=https://host/app]\nRead-only checks. --testing requires hardened hosting settings and PayMongo test keys. --production requires live keys. --http also verifies public routes and forbidden artifact URLs.\n"; exit;
    } else { fwrite(STDERR, "Unknown option. Use --help.\n"); exit(2); }
}
if ($production && $testing) { fwrite(STDERR,"Choose either --testing or --production, never both.\n");exit(2); }
$root = dirname(__DIR__);
require_once $root . '/includes/environment.php';
require_once $root . '/includes/deployment_schema.php';
$failures = 0; $warnings = 0;
function preflightCheck(bool $ok, string $label, bool $warning = false): void {
    global $failures, $warnings;
    if (!$ok) { if ($warning) $warnings++; else $failures++; }
    echo ($ok ? 'PASS' : ($warning ? 'WARN' : 'FAIL')) . ' ' . $label . PHP_EOL;
}
function preflightIniBytes(string $value): int {
    $value = trim($value);
    $bytes = (float)$value;
    $unit = strtolower(substr($value,-1));
    return (int)($bytes * match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 });
}
preflightCheck(PHP_VERSION_ID >= 80100, 'PHP 8.1 or later');
foreach (['mysqli', 'mysqlnd', 'curl', 'fileinfo', 'mbstring', 'dom', 'openssl', 'session', 'gd'] as $extension) preflightCheck(extension_loaded($extension), "PHP extension: {$extension}");
if (extension_loaded('gd')) {
    $imageSupport=gd_info();
    preflightCheck(!empty($imageSupport['JPEG Support']) && !empty($imageSupport['PNG Support']) && !empty($imageSupport['WebP Support']), 'GD supports JPEG, PNG and WebP profile pictures');
}
preflightCheck(extension_loaded('zip'), 'PHP ZIP supports multi-page staff report PNG downloads');
preflightCheck(is_file($root.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'), 'Staff report PDF/PNG font is available');
preflightCheck(is_file($root . '/vendor/autoload.php'), 'Composer dependencies are installed');
preflightCheck(filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN), 'PHP file uploads are enabled');
preflightCheck(preflightIniBytes((string)ini_get('upload_max_filesize')) >= 10 * 1048576, 'PHP permits attachments up to 10 MB');
$postLimit = preflightIniBytes((string)ini_get('post_max_size'));
preflightCheck($postLimit === 0 || $postLimit >= 12 * 1048576, 'PHP multipart request limit is at least 12 MB');
preflightCheck(is_dir($root . '/private_uploads') && is_writable($root . '/private_uploads'), 'Private upload storage is writable');
preflightCheck(is_dir($root . '/private_uploads/profile_photos') && is_writable($root . '/private_uploads/profile_photos'), 'Private profile photo storage is writable');
require_once $root . '/includes/visitor_identity.php';
try {
    visitorIdRetentionDays();
    preflightCheck(is_writable(visitorIdStorageDirectory()), 'Visitor ID storage is writable and outside public directories');
} catch (Throwable $e) { preflightCheck(false, 'Configure private visitor ID storage and a retention period of 1–365 days; run the migration'); }
require_once $root.'/includes/community_reports.php';
require_once $root.'/includes/report_evidence.php';
try {
    $reportLimits=reportFileLimits();reportSettingInt('CONDO_REPORT_EVIDENCE_RETENTION_DAYS',365,1,3650);reportSettingInt('CONDO_REPORT_ANONYMOUS_ACCESS_DAYS',365,1,3650);
    preflightCheck(is_writable(reportStorageDirectory()),'Community report evidence is writable and outside public directories');
    preflightCheck($postLimit===0 || $postLimit>$reportLimits['total_bytes'],'PHP request limit exceeds configured combined report evidence limit');
} catch(Throwable $error) { preflightCheck(false,'Prepare private community report storage and valid report settings with the migration'); }
foreach (['.htaccess','includes/.htaccess','private_uploads/.htaccess','private_uploads/profile_photos/.htaccess','scripts/.htaccess','vendor/.htaccess','inventory/.htaccess'] as $file) preflightCheck(is_file($root . '/' . $file), "HTTP protection file: {$file}");

$production = $production || $testing || appIsProduction();
if ($production) {
    preflightCheck(PHP_VERSION_ID >= ($testing ? 80300 : 80400), $testing ? 'Hostinger testing uses PHP 8.3 or later (install current security updates)' : 'Production deployment uses PHP 8.4 or later (install current security updates)');
    preflightCheck(appIsProduction(), 'CONDO_APP_ENV is production');
    $url = appSetting('CONDO_APP_URL');
    $parts = parse_url($url);
    preflightCheck(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']), 'Canonical CONDO_APP_URL uses HTTPS');
    preflightCheck(appSetting('CONDO_AUTO_MIGRATE', '0') === '0', 'Automatic runtime migrations are disabled');
    preflightCheck(!in_array(strtolower(appSetting('CONDO_DB_USER', 'root')), ['root',''], true), 'Dedicated database account is configured');
    preflightCheck(appSetting('CONDO_DB_PASS') !== '', 'Database password is configured');
    foreach (['CONDO_SMTP_USER','CONDO_SMTP_PASS','CONDO_MAIL_FROM'] as $setting) preflightCheck(appSetting($setting) !== '', "Required mail configuration: {$setting}");
    preflightCheck(filter_var(appSetting('CONDO_MAIL_FROM'), FILTER_VALIDATE_EMAIL) !== false, 'Mail sender has a valid email address');
    $key = appSetting('CONDO_PAYMONGO_SECRET_KEY');
    preflightCheck(str_starts_with($key, $testing ? 'sk_test_' : 'sk_live_'), $testing ? 'PayMongo sandbox secret key is configured; live charges are not permitted for this check' : 'PayMongo live secret key is configured');
    preflightCheck(str_starts_with(appSetting('CONDO_PAYMONGO_WEBHOOK_SECRET'), 'whsk_'), 'PayMongo webhook secret is configured');
    preflightCheck(strlen(appSetting('CONDO_PASS_SIGNING_KEY')) >= 32, 'Parking pass signing key has at least 32 characters');
    preflightCheck(strlen(appSetting('CONDO_CRON_SECRET')) >= 32, 'Reminder HTTP secret is configured (CLI scheduling can omit this)', true);
    $sms = appSetting('CONDO_SMS_PROVIDER', 'semaphore');
    $smsConfigured = $sms === 'twilio' ? appSetting('CONDO_TWILIO_SID') !== '' && appSetting('CONDO_TWILIO_AUTH_TOKEN') !== '' && appSetting('CONDO_TWILIO_FROM') !== '' : appSetting('CONDO_SEMAPHORE_API_KEY') !== '';
    preflightCheck($smsConfigured, 'SMS provider credentials are configured', true);
}

if (extension_loaded('mysqli')) {
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(appSetting('CONDO_DB_HOST', 'localhost'), appSetting('CONDO_DB_USER', 'root'), appSetting('CONDO_DB_PASS'), appSetting('CONDO_DB_NAME', 'Condo_System'));
        $db->set_charset('utf8mb4');
        preflightCheck(true, 'Database connection');
        $problems = deploymentSchemaProblems($db);
        if (!$problems) preflightCheck(true, 'Required tables, columns, unique indexes and InnoDB engines');
        foreach ($problems as $problem) preflightCheck(false, $problem);
        $table = $db->query("SHOW TABLES LIKE 'app_schema_versions'");
        $applied = false;
        if ($table->num_rows) {
            $version = APP_SCHEMA_VERSION;
            $find = $db->prepare('SELECT version FROM app_schema_versions WHERE version=?');
            $find->bind_param('s', $version); $find->execute();
            $applied = (bool)$find->get_result()->fetch_assoc();
        }
        preflightCheck($applied, 'Application schema migration is recorded: ' . APP_SCHEMA_VERSION);
        $admins = $db->query("SELECT COUNT(*) AS total FROM users WHERE role='superadmin' AND is_active=1 AND is_verified=1");
        preflightCheck((int)$admins->fetch_assoc()['total'] > 0, 'At least one active verified superadmin exists', true);
        try { reportAuditKey($db);preflightCheck(true,'Community report audit signing key is available'); } catch(Throwable $error) { preflightCheck(false,'Community report audit signing key is available'); }
        $reviewers=reportIndependentIds();$validReviewers=0;foreach($reviewers as$reviewer)if(reportReviewer($db,$reviewer))$validReviewers++;
        preflightCheck($validReviewers>0,'An eligible independent property-manager reviewer is configured; otherwise oversight cases stay sealed',true);
        $db->close();
    } catch (Throwable $e) {
        preflightCheck(false, 'Database/schema checks failed; verify access and run scripts/migrate.php --apply after backup');
    }
}

if ($httpUrl !== null) {
    $parts = parse_url($httpUrl);
    $allowed = is_array($parts) && in_array($parts['scheme'] ?? '', ['http','https'], true) && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
    preflightCheck($allowed && (!$production || ($parts['scheme'] ?? '') === 'https'), 'HTTP check target is a valid app URL');
    if ($allowed && extension_loaded('curl')) {
        $forbidden = ['/.env','/.git/HEAD','/config.php','/database.sql','/composer.json','/condo_units.csv','/parking-inventory.csv','/scripts/preflight.php','/vendor/autoload.php','/includes/workflows.php','/private_uploads/.htaccess','/private_uploads/profile_photos/.htaccess','/INFINITYFREE_SETUP.zip','/debug_live_updates.html'];
        $public = ['/index.php', '/styles.css'];
        $curl = curl_init();
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
        foreach (array_merge($forbidden, $public) as $path) {
            curl_setopt($curl, CURLOPT_URL, $httpUrl . $path); curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $ok = in_array($path, $forbidden, true) ? in_array($status, [403,404], true) : $status >= 200 && $status < 400;
            preflightCheck($ok, 'HTTP ' . $path . ' returns ' . $status);
        }
        curl_close($curl);
    }
} else preflightCheck(false, 'HTTP artifact-denial checks were not run; use --http=<app URL>', true);

echo "Preflight: {$failures} failure(s), {$warnings} warning(s). This does not verify camera hardware, provider delivery, backups or browser appearance.\n";
exit($failures ? 1 : 0);
