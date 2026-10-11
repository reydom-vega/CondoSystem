<?php
/** Use the existing gate schedule to classify permits without changing their stored status. */
function permitWorkspaceStatus(array $request): string
{
    if (!empty($request['gate_data'])) return gateEffectiveStatus($request);
    return $request['status'] === 'approved' && $request['end_date'] < date('Y-m-d') ? 'expired' : $request['status'];
}

function permitRequestIsHistory(array $request): bool
{
    return in_array(permitWorkspaceStatus($request), ['completed', 'rejected', 'cancelled', 'expired', 'checked_out'], true);
}

function permitWorkspacePanelAttributes(string $key, string $active): string
{
    return 'id="permit-panel-' . $key . '" role="tabpanel" aria-labelledby="permit-tab-' . $key . '" tabindex="0" data-workspace-panel="' . $key . '"' . ($key !== $active ? ' hidden' : '');
}

function renderPermitWorkspaceTabs(array $tabs, string $active, array $groups, bool $staff): void
{
    echo '<nav class="workspace-tabs" role="tablist" aria-label="Permit request sections">';
    foreach ($tabs as $key => $label) {
        $url = '?' . http_build_query(($staff ? ['kind' => 'permit'] : []) + ['tab' => $key]);
        echo '<a id="permit-tab-' . $key . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" role="tab" data-workspace-tab="' . $key . '" aria-controls="permit-panel-' . $key . '" aria-selected="' . ($key === $active ? 'true' : 'false') . '" tabindex="' . ($key === $active ? '0' : '-1') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if (isset($groups[$key])) echo ' <span class="workspace-tab-count">' . count($groups[$key]) . '</span>';
        echo '</a>';
    }
    echo '</nav>';
}
