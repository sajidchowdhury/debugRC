@php
    /**
     * Phase 4.1 — Shared 2-step "Find Invoice → return form" workspace.
     * Mirrors purchase-returns/partials/create-workspace.blade.php.
     *
     * Used by BOTH:
     *   - sales-returns/create.blade.php  (full-page, $compact = false)
     *   - sales-returns/index.blade.php   (offcanvas,    $compact = true)  [Phase 3.3]
     *
     * Variables:
     *   $workspaceId  string  Unique DOM id for the workspace root (page vs offcanvas).
     *   $compact      bool    Offcanvas mode renders tighter spacing.
     *
     * The workspace JS (SalesReturn.js → SalesReturnWorkspace) auto-binds to
     * any element with [data-srt-workspace] on DOMContentLoaded. Multiple
     * workspaces per page are supported (page + offcanvas).
     */
    $workspaceId = $workspaceId ?? 'salesReturnCreateRoot';
    $isCompact   = ! empty($compact);
@endphp
<div id="{{ e($workspaceId) }}"
     class="srt-create-workspace{{ $isCompact ? ' srt-create-workspace--compact' : '' }}"
     data-srt-workspace>
    {{-- Step 1: Find Invoice --}}
    <div class="srt-create-step srt-create-step-find" data-step="find">
        <div class="srt-create-find-head">
            <span class="srt-create-step-badge">1</span>
            <div>
                <strong>Find Invoice</strong>
                <p>Type invoice code or customer name — pick a match to load returnable lines</p>
            </div>
        </div>
        <div class="srt-create-search-wrap">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search"
                   id="{{ e($workspaceId) }}_invoiceSearch"
                   class="form-control srt-create-search-input"
                   placeholder="e.g. INV-2026-0001 or customer name"
                   autocomplete="off"
                   aria-label="Search invoice for return"
                   aria-controls="{{ e($workspaceId) }}_searchResults"
                   aria-expanded="false">
            <button type="button"
                    class="srt-create-search-clear d-none"
                    id="{{ e($workspaceId) }}_searchClear"
                    title="Clear"
                    aria-label="Clear search">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <p class="srt-create-search-hint" id="{{ e($workspaceId) }}_searchHint">
            <i class="fas fa-bolt"></i> Results update as you type ·
            <kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate · <kbd>Enter</kbd> select
        </p>
        <p class="srt-create-search-hint mb-0">
            <i class="fas fa-warehouse text-muted"></i>
            Only <strong>challan-issued</strong>, non-reversed invoices with at
            least one returnable line appear. Returnable = sold &minus; already returned.
        </p>
        <div id="{{ e($workspaceId) }}_searchResults"
             class="srt-create-results"
             role="listbox"
             aria-label="Invoice search results"></div>
    </div>

    {{-- Step 2: Return form (hidden until an invoice is picked) --}}
    <div class="srt-create-step srt-create-step-form d-none" data-step="form">
        <div class="srt-create-invoice-bar" id="{{ e($workspaceId) }}_invoiceBar"></div>
        <div id="{{ e($workspaceId) }}_invoiceDetails"></div>
    </div>
</div>
