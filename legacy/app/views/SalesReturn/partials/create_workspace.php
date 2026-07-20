<?php
/** @var string $workspace_id Unique DOM root id (page vs offcanvas) */
$workspaceId = $workspace_id ?? 'salesReturnCreateRoot';
$isCompact = !empty($compact);
?>
<div id="<?= htmlspecialchars($workspaceId, ENT_QUOTES) ?>" class="sr-create-workspace<?= $isCompact ? ' sr-create-workspace--compact' : '' ?>" data-sr-workspace>
    <?php if (!$isCompact): ?>
    <div class="sr-journey-steps sr-journey-steps--panel mb-3" aria-label="Return process">
        <div class="sr-journey-step is-active">
            <span class="sr-journey-num">1</span>
            <span class="sr-journey-label">Receive from customer</span>
        </div>
        <span class="sr-journey-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
        <div class="sr-journey-step is-muted">
            <span class="sr-journey-num">2</span>
            <span class="sr-journey-label">Warehouse confirm</span>
        </div>
    </div>
    <?php endif; ?>
    <div class="sr-create-step sr-create-step-find" data-step="find">
        <div class="sr-create-find-head">
            <span class="sr-create-step-badge">1</span>
            <div>
                <strong>Find invoice</strong>
                <p>Challan-completed invoices for your branch only · search by #, shop, or mobile</p>
            </div>
        </div>
        <div class="sales-return-search-wrap sr-create-search-wrap">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search"
                   id="<?= $workspaceId ?>_invoiceSearch"
                   class="form-control sales-return-search-input sr-create-search-input"
                   placeholder="e.g. INV-2026-001 or customer name / mobile"
                   autocomplete="off"
                   aria-label="Search invoice for return"
                   aria-controls="<?= $workspaceId ?>_searchResults"
                   aria-expanded="false">
            <button type="button" class="sr-create-search-clear d-none" id="<?= $workspaceId ?>_searchClear" title="Clear" aria-label="Clear search">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <p class="sr-create-search-hint" id="<?= $workspaceId ?>_searchHint">
            <i class="fas fa-bolt"></i> Results update as you type · <kbd>↑</kbd><kbd>↓</kbd> navigate · <kbd>Enter</kbd> select
        </p>
        <div id="<?= $workspaceId ?>_searchResults" class="sr-create-results" role="listbox" aria-label="Invoice search results"></div>
    </div>

    <div class="sr-create-step sr-create-step-form d-none" data-step="form">
        <div class="sr-create-invoice-bar" id="<?= $workspaceId ?>_invoiceBar"></div>
        <div id="<?= $workspaceId ?>_invoiceDetails"></div>
    </div>
</div>