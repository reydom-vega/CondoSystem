<?php
require_once __DIR__ . '/config.php';

$connection = connectDb();
ensureParkingTables($connection);
$result = importParkingInventoryCsv($connection);
file_put_contents(__DIR__ . '/parking_import_diagnostic_output.txt', json_encode([
    'file_path' => $result['file_path'],
    'rows_read' => $result['rows_read'],
    'imported' => $result['imported'],
    'skipped' => $result['skipped'],
    'errors' => $result['errors'],
    'ok' => $result['ok'],
    'b1_001_exists' => $connection->query("SELECT COUNT(*) AS count FROM parking_slots WHERE slot_code = 'B1-001'")->fetch_assoc()['count'] ?? null,
    'total_slots' => $connection->query('SELECT COUNT(*) AS count FROM parking_slots')->fetch_assoc()['count'] ?? null,
], JSON_PRETTY_PRINT) . PHP_EOL);
