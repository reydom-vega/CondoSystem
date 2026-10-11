<?php
// A read-only view of the existing requests and sticker orders; no shadow history tables.
$parkingRecords = [];
foreach ($requests as $record) {
    $parkingRecords[] = [
        'reference'=>'Parking #'.$record['id'],
        'kind'=>$record['request_type'] === 'visitor' ? 'Visitor parking' : 'Resident assignment',
        'resident'=>$record['full_name'], 'unit'=>$record['unit_number'],
        'vehicle'=>$record['vehicle_plate'], 'slot'=>$record['slot_code'] ?? 'Not assigned',
        'status'=>$record['status'], 'date'=>$record['created_at'],
        'details'=>$record['start_date'].' to '.($record['end_date'] ?: $record['start_date']).(!empty($record['admin_notes']) ? ' · '.$record['admin_notes'] : ''),
    ];
}
foreach ($stickerClaims as $record) {
    $parkingRecords[] = [
        'reference'=>'Sticker #'.$record['id'], 'kind'=>'Parking sticker',
        'resident'=>$record['full_name'], 'unit'=>$record['unit_number'] ?? '',
        'vehicle'=>implode(', ',array_column($record['vehicles'],'plate_number')) ?: 'Historical order',
        'slot'=>implode(', ',array_filter(array_column($record['vehicles'],'slot_code'))) ?: 'Not assigned',
        'status'=>$record['claim_status'] === 'issued' ? 'issued' : $record['payment_status'],
        'date'=>$record['issued_at'] ?: ($record['paid_at'] ?: ($record['created_at'] ?? '')),
        'details'=>(int)$record['quantity'].' sticker(s) · PHP '.number_format((float)$record['amount'],2),
    ];
}
usort($parkingRecords, static fn(array $a,array $b): int => strcmp((string)$b['date'],(string)$a['date']));
$recordStatuses = array_unique(array_column($parkingRecords,'status')); sort($recordStatuses);
$recordEscape = static fn($value): string => htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<section class="unit-management-panel parking-panel">
    <h2>Parking records</h2>
    <p class="panel-subtext">Parking requests, slot assignments and sticker payment / issuance records, including completed and cancelled requests.</p>
    <form method="get" action="parking.php" class="parking-record-filters" data-parking-record-filters>
        <input type="hidden" name="tab" value="records">
        <label class="field-label">Search records<input type="search" name="record_search" placeholder="Reference, resident, unit, plate or slot"></label>
        <label class="field-label">Status<select name="record_status"><option value="">All statuses</option><?php foreach ($recordStatuses as $recordStatus): ?><option value="<?= $recordEscape($recordStatus) ?>"><?= $recordEscape(ucfirst($recordStatus)) ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="service-btn service-btn-secondary">Search</button>
    </form>
    <p class="parking-record-count" data-record-count><?= count($parkingRecords) ?> records</p>
    <div class="unit-table-wrap"><table class="unit-table parking-record-table"><thead><tr><th>Reference / type</th><th>Resident / unit</th><th>Vehicle / slot</th><th>Status</th><th>Date / details</th></tr></thead><tbody>
        <?php if (!$parkingRecords): ?><tr><td colspan="5" class="unit-empty">No parking records yet.</td></tr><?php endif; ?>
        <?php foreach ($parkingRecords as $record): ?><tr data-parking-record data-status="<?= $recordEscape($record['status']) ?>">
            <td><strong><?= $recordEscape($record['reference']) ?></strong><br><?= $recordEscape($record['kind']) ?></td>
            <td><?= $recordEscape($record['resident']) ?><br>Unit <?= $recordEscape($record['unit']) ?></td>
            <td><?= $recordEscape($record['vehicle']) ?><br><?= $recordEscape($record['slot']) ?></td>
            <td><span class="unit-status <?= $recordEscape($record['status']) ?>"><?= $recordEscape(ucfirst($record['status'])) ?></span></td>
            <td><?= $recordEscape($record['date']) ?><br><?= $recordEscape($record['details']) ?></td>
        </tr><?php endforeach; ?>
    </tbody></table></div>
</section>
