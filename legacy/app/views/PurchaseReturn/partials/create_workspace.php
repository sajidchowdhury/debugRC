<?php
/** @var string $workspace_id Unique DOM root id (page vs offcanvas) */
$workspaceId = $workspace_id ?? 'purchaseReturnCreateRoot';
$isCompact = !empty($compact);
?>
<div id="<?= htmlspecialchars($workspaceId, ENT_QUOTES) ?>" class="prt-create-workspace<?= $isCompact ? ' prt-create-workspace--compact' : '' ?>" data-prt-workspace>
    <div class="prt-create-step prt-create-step-find" data-step="find">
        <div class="prt-create-find-head">
            <span class="prt-create-step-badge">1</span>
            <div>
                <strong>Find GRN</strong>
                <p>Type GRN code or supplier name — pick a match to load return lines</p>
            </div>
        </div>
        <div class="purchase-return-search-wrap prt-create-search-wrap">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search"
                   id="<?= $workspaceId ?>_receiveSearch"
                   class="form-control purchase-return-search-input prt-create-search-input"
                   placeholder="e.g. GRN-2026-0001 or supplier name"
                   autocomplete="off"
                   aria-label="Search GRN for return"
                   aria-controls="<?= $workspaceId ?>_searchResults"
                   aria-expanded="false">
            <button type="button" class="prt-create-search-clear d-none" id="<?= $workspaceId ?>_searchClear" title="Clear" aria-label="Clear search">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <p class="prt-create-search-hint" id="<?= $workspaceId ?>_searchHint">
            <i class="fas fa-bolt"></i> Results update as you type · <kbd>↑</kbd><kbd>↓</kbd> navigate · <kbd>Enter</kbd> select
        </p>
        <p class="prt-create-search-hint mb-0">
            <i class="fas fa-warehouse text-muted"></i>
            Stock shown per warehouse comes from <strong>warehouse_stock</strong> (same as sales). GRN “returnable” is how much is still allowed back to the supplier on that receive.
        </p>
        <div id="<?= $workspaceId ?>_searchResults" class="prt-create-results" role="listbox" aria-label="GRN search results"></div>
    </div>

    <div class="prt-create-step prt-create-step-form d-none" data-step="form">
        <div class="prt-create-invoice-bar" id="<?= $workspaceId ?>_receiveBar"></div>
        <div id="<?= $workspaceId ?>_receiveDetails"></div>
    </div>
</div>