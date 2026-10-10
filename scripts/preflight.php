<?php
/** Read-only readiness checks: no business/schema writes, no provider calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$production = false; $httpUrl = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--production') $production = true;
    elseif (str_starts_with($argument, '--http=')) $httpUrl = rtrim(substr($argument, 7), '/');
    elseif ($argument === '--help') {
        echo "Usage: php scripts/preflight.php [--production] [--http=https://host/app]\nRead-only checks. --http also verifies public routes and forbidden artifact URLs.\n"; exit;
    } else { fwrite(STDERR, "Unknown option. Use --help.\n"); exit(2); }
}
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
foreach (['mysqli', 'mysqlnd', 'curl', 'fileinfo', 'mbstring', 'dom', 'openssl', 'session'] as $extension) preflightCheck(extension_loaded($extension), "PHP extension: {$extension}");
preflightCheck(is_file($root . '/vendor/autoload.php'), 'Composer dependencies are installed');
preflightCheck(filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN), 'PHP file uploads are enabled');
preflightCheck(preflightIniBytes((string)ini_get('upload_max_filesize')) >= 10 * 1048576, 'PHP permits attachments up to 10 MB');
$postLimit = preflightIniBytes((string)ini_get('post_max_size'));
preflightCheck($postLimit === 0 || $postLimit >= 12 * 1048576, 'PHP multipart request limit is at least 12 MB');
preflightCheck(is_dir($root . '/private_uploads') && is_writable($root . '/private_uploads'), 'Private upload storage is writable');
foreach (['.htaccess','includes/.htaccess','private_uploads/.htaccess','scripts/.htaccess','vendor/.htaccess','inventory/.htaccess'] as $file) preflightCheck(is_file($root . '/' . $file), "HTTP protection file: {$file}");

$production = $production || appIsProduction();
if ($production) {
    preflightCheck(PHP_VERSION_ID >= 80400, 'Production deployment uses PHP 8.4 or later (install current security updates)');
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
    preflightCheck(str_starts_with($key, 'sk_live_'), 'PayMongo live secret key is configured');
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
        $forbidden = ['/.env','/.git/HEAD','/config.php','/database.sql','/composer.json','/condo_units.csv','/parking-inventory.csv','/scripts/preflight.php','/vendor/autoload.php','/includes/workflows.php','/private_uploads/.htaccess','/INFINITYFREE_SETUP.zip','/debug_live_updates.html'];
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
