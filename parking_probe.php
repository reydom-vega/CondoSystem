<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $connection = connectDb();
    $path = getParkingInventoryFilePath();
    $handle = fopen($path, 'rb');
    $header = fgetcsv($handle);
    fclose($handle);
    $normalizedHeader = [];
    foreach ($header as $index => $name) {
        $normalizedHeader[strtolower(str_replace([' ', '-', '_'], '', (string) $name))] = $index;
    }
    $result = importParkingInventoryCsv($connection);
    $count = $connection->query("SELECT COUNT(*) AS count FROM parking_slots WHERE slot_code = 'B1-001'")->fetch_assoc()['count'] ?? null;
    $total = $connection->query('SELECT COUNT(*) AS count FROM parking_slots')->fetch_assoc()['count'] ?? null;
    echo json_encode([
        'path' => $path,
        'header' => $header,
        'normalized_header' => $normalizedHeader,
        'rows_read' => $result['rows_read'],
        'imported' => $result['imported'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
        'ok' => $result['ok'],
        'b1_001' => $count,
        'total_slots' => $total,
        'php_version' => PHP_VERSION,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
}
