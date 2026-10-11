<?php
/** Presentation helpers; existing maintenance controllers enforce access and transitions. */
function maintenanceWorkspaceIsHistory(array $request): bool
{
    return in_array($request['status'], ['closed', 'rejected', 'cancelled'], true);
}

function maintenanceWorkspaceActiveTab(array $tabs, ?array $selected, string $default): string
{
    // Follow a selected request when an action moves it between Requests and History.
    if ($selected) return maintenanceWorkspaceIsHistory($selected) ? 'history' : 'requests';
    $requested = $_POST['tab'] ?? $_GET['tab'] ?? $default;
    return is_string($requested) && isset($tabs[$requested]) ? $requested : $default;
}

function renderMaintenanceWorkspaceTabs(array $tabs, string $active, array $counts): void
{
    $params = [];
    if (isset($_GET['search']) && is_string($_GET['search'])) $params['search'] = $_GET['search'];
    echo '<nav class="maintenance-tabs" role="tablist" aria-label="Maintenance sections">';
    foreach ($tabs as $key => $label) {
        $url = '?' . http_build_query($params + ['tab' => $key]);
        echo '<a id="maintenance-tab-' . $key . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" role="tab" data-maintenance-tab="' . $key . '" aria-controls="maintenance-panel-' . $key . '" aria-selected="' . ($active === $key ? 'true' : 'false') . '" tabindex="' . ($active === $key ? '0' : '-1') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if (isset($counts[$key])) echo ' <span class="maintenance-tab-count">' . (int)$counts[$key] . '</span>';
        echo '</a>';
    }
    echo '</nav>';
}

function maintenanceWorkspacePanelAttributes(string $key, string $active): string
{
    return 'id="maintenance-panel-' . $key . '" role="tabpanel" aria-labelledby="maintenance-tab-' . $key . '" tabindex="0" data-maintenance-panel="' . $key . '"' . ($key !== $active ? ' hidden' : '');
}
