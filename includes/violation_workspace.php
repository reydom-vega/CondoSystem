<?php
/** Keep typed names tied to an existing account, including duplicate names. */
function violationResidentOption(array $resident): string {
    return $resident['full_name'].' - Unit '.($resident['unit_number'] ?: 'Unassigned').' (#'.(int)$resident['id'].')';
}

function violationResidentFromName(array $residents, string $name, int $postedId = 0): int {
    $normalize = static fn(string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 'UTF-8');
    if (mb_strlen($name, 'UTF-8') > 240 || $normalize($name) === '') throw new InvalidArgumentException('Enter the resident\'s full name.');
    $matches = array_values(array_filter($residents, static fn(array $resident): bool =>
        $normalize($resident['full_name']) === $normalize($name) || $normalize(violationResidentOption($resident)) === $normalize($name)
    ));
    if (count($matches) > 1) throw new InvalidArgumentException('More than one resident has that name. Choose the matching name and unit from the suggestions.');
    if (!$matches) throw new InvalidArgumentException('No eligible resident matches that name. Enter a full name or choose a suggestion.');
    $id = (int)$matches[0]['id'];
    if ($postedId > 0 && $postedId !== $id) throw new InvalidArgumentException('The resident name and account do not match. Enter the name again.');
    return $id;
}

/** Shared tab presentation; the existing violation controllers own access and actions. */
function renderViolationWorkspaceTabs(array $tabs, string $active, array $counts = []): void
{
    echo '<nav class="violation-tabs" role="tablist" aria-label="Violation sections">';
    foreach ($tabs as $key => $label) {
        $params = ['tab' => $key];
        if (isset($_GET['search']) && is_string($_GET['search'])) $params['search'] = $_GET['search'];
        echo '<a id="violation-tab-' . $key . '" href="?' . htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8') . '" role="tab" data-violation-tab="' . $key . '" aria-controls="violation-panel-' . $key . '" aria-selected="' . ($active === $key ? 'true' : 'false') . '" tabindex="' . ($active === $key ? '0' : '-1') . '">' . htmlspecialchars($label);
        if (isset($counts[$key])) echo ' <span class="violation-tab-count">' . (int)$counts[$key] . '</span>';
        echo '</a>';
    }
    echo '</nav>';
}

function violationWorkspacePanelAttributes(string $key, string $active): string
{
    return 'id="violation-panel-' . $key . '" role="tabpanel" aria-labelledby="violation-tab-' . $key . '" tabindex="0" data-violation-panel="' . $key . '"' . ($key !== $active ? ' hidden' : '');
}

/** Paid, waived and warning records have no outstanding fine or review action. */
function residentViolationIsHistory(array $record): bool
{
    return in_array($record['status'], ['paid', 'waived', 'warning_issued'], true);
}

function residentViolationFilterRecords(array $records, string $filter, string $search): array
{
    return array_values(array_filter($records, static function (array $record) use ($filter, $search): bool {
        $matches = match ($filter) {
            'unpaid' => in_array($record['status'], ['unpaid', 'disputed'], true),
            'disputed' => $record['status'] === 'disputed',
            'paid' => $record['status'] === 'paid',
            'waived' => $record['status'] === 'waived',
            'warnings' => $record['penalty_type'] === 'warning',
            default => true,
        };
        $text = implode(' ', [$record['id'], $record['violation_type'], $record['description'] ?? '', $record['location'] ?? '', $record['observed_plate'] ?? '']);
        return $matches && ($search === '' || stripos($text, $search) !== false);
    }));
}
