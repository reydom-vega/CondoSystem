<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc > 2 || !in_array($argv[1] ?? '--dry-run', ['--dry-run','--apply'], true)) {
    fwrite(STDERR, "Usage: php scripts/purge_visitor_ids.php [--dry-run|--apply]\n"); exit(2);
}
require_once dirname(__DIR__) . '/config.php';
$result = purgeExpiredVisitorIds(connectDb(), ($argv[1] ?? '') === '--apply');
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
