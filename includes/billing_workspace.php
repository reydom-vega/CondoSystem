<?php
/** Presentation only: billing queries and permissions remain in the existing controllers. */
function billingWorkspaceActiveTab(): string
{
    $requested = $_POST['tab'] ?? $_GET['tab'] ?? (($_GET['status'] ?? '') === 'paid' ? 'history' : 'billing');
    return $requested === 'history' ? 'history' : 'billing';
}

function renderBillingWorkspaceTabs(string $active, int $openCount, int $historyCount): void
{
    $params = [];
    if (isset($_GET['search']) && is_string($_GET['search'])) $params['search'] = $_GET['search'];
    echo '<nav class="billing-tabs" role="tablist" aria-label="Billing and payment sections">';
    foreach (['billing' => ['Billing & Payments', $openCount], 'history' => ['History', $historyCount]] as $key => [$label, $count]) {
        $url = '?' . http_build_query($params + ['tab' => $key]);
        echo '<a id="billing-tab-' . $key . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" role="tab" data-billing-tab="' . $key . '" aria-controls="billing-panel-' . $key . '" aria-selected="' . ($active === $key ? 'true' : 'false') . '" tabindex="' . ($active === $key ? '0' : '-1') . '">' . htmlspecialchars($label) . ' <span class="billing-tab-count">' . $count . '</span></a>';
    }
    echo '</nav>';
}

function billingWorkspacePanelAttributes(string $key, string $active): string
{
    return 'id="billing-panel-' . $key . '" role="tabpanel" aria-labelledby="billing-tab-' . $key . '" tabindex="0" data-billing-panel="' . $key . '"' . ($key !== $active ? ' hidden' : '');
}
