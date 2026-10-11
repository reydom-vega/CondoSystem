<?php
/** Keep visitors currently inside visible until they are checked out. */
function visitorRequestIsHistory(array $request): bool
{
    return in_array($request['status'], ['checked_out', 'rejected', 'cancelled'], true)
        || ($request['status'] !== 'checked_in' && $request['end_date'] < date('Y-m-d'));
}

function visitorWorkspacePanelAttributes(string $key, string $active): string
{
    return 'id="visitor-panel-' . $key . '" role="tabpanel" aria-labelledby="visitor-tab-' . $key . '" tabindex="0" data-workspace-panel="' . $key . '"' . ($key !== $active ? ' hidden' : '');
}

function renderVisitorWorkspaceTabs(array $tabs, string $active, array $groups, bool $staff): void
{
    echo '<nav class="workspace-tabs" role="tablist" aria-label="Visitor registration sections">';
    foreach ($tabs as $key => $label) {
        $url = '?' . http_build_query(($staff ? ['kind' => 'visitor'] : []) + ['tab' => $key]);
        echo '<a id="visitor-tab-' . $key . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" role="tab" data-workspace-tab="' . $key . '" aria-controls="visitor-panel-' . $key . '" aria-selected="' . ($key === $active ? 'true' : 'false') . '" tabindex="' . ($key === $active ? '0' : '-1') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if (isset($groups[$key])) echo ' <span class="workspace-tab-count">' . count($groups[$key]) . '</span>';
        echo '</a>';
    }
    echo '</nav>';
}
