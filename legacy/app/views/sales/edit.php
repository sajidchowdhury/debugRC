<?php
$title = 'Edit Invoice #' . htmlspecialchars($invoice['invoice_code'] ?? '', ENT_QUOTES);
$inv = $invoice;
$customerLabel = trim(($inv['shop_name'] ?? '') ?: ($inv['customer_name'] ?? 'Customer'));
$customerSub = trim(($inv['customer_name'] ?? '') . ($inv['mobile'] ? ' · ' . $inv['mobile'] : ''));
$invoiceDate = !empty($inv['invoice_date']) ? date('Y-m-d', strtotime($inv['invoice_date'])) : date('Y-m-d');
$transport = (float)($inv['transport_cost'] ?? 0);
$discount = (float)($inv['discount'] ?? 0);
$branchLocked = empty($can_override_branch);
$blockedReason = $edit_blocked_reason ?? '';

$content = '
<link rel="stylesheet" href="' . BASE_URL . 'assets/css/sales-pos.css">
<meta name="theme-color" content="#4f46e5">

<div id="sales-edit-app" class="sales-create-app sales-edit-app">
    <header class="sales-create-header sales-edit-header">
        <div>
            <h1 class="sales-create-title">Edit draft</h1>
            <p class="sales-create-sub">
                <span class="sales-invoice-chip">' . htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) . '</span>
                · ' . htmlspecialchars($inv['branch_name'] ?? 'Branch', ENT_QUOTES) . '
            </p>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
            <a href="' . BASE_URL . 'sales/today" class="btn btn-light btn-sm sales-header-btn">
                <i class="fas fa-arrow-left"></i> Today
            </a>
        </div>
    </header>';

if ($blockedReason !== '') {
    $content .= '
    <div class="sales-edit-alert alert alert-warning border-0 shadow-sm" role="alert">
        <i class="fas fa-lock me-2"></i>' . htmlspecialchars($blockedReason, ENT_QUOTES) . '
        <div class="mt-2">
            <a href="' . BASE_URL . 'sales/today" class="btn btn-sm btn-outline-dark">Back to today\'s list</a>
        </div>
    </div>';
} else {
    $content .= '
    <div class="sales-edit-safe-banner" role="status">
        <i class="fas fa-shield-alt"></i>
        <span>Draft only · changes reverse &amp; repost accounting · customer cannot be changed</span>
    </div>

    <form id="kt_form" class="sales-create-form">
        <input type="hidden" id="edit_mode" value="1">
        <input type="hidden" id="invoice_id" value="' . (int)($inv['id'] ?? 0) . '">
        <input type="hidden" id="customer_id" value="' . (int)($inv['customer_id'] ?? 0) . '">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) . '">
        <script>
        window.CSRF_TOKEN = ' . json_encode($_SESSION['csrf_token'] ?? '') . ';
        window.SALES_EDIT_MODE = true;
        window.SALES_EDIT_AMOUNTS = { transport: ' . json_encode($transport) . ', discount: ' . json_encode($discount) . ' };
        window.SALES_EDIT_BOOT = ' . json_encode([
            'invoice_id' => (int)($inv['id'] ?? 0),
            'customer_id' => (int)($inv['customer_id'] ?? 0),
            'customer_label' => $customerLabel,
            'customer_sub' => $customerSub,
            'branch_id' => (int)($inv['branch_id'] ?? 0),
            'salesman_id' => (int)($inv['salesman_id'] ?? 0),
            'sales_person' => (int)($inv['sales_person'] ?? 0),
            'invoice_date' => $invoiceDate,
            'narration' => (string)($inv['narration'] ?? ''),
            'transport_cost' => $transport,
            'discount' => $discount,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        </script>

        <div class="row g-3">
            <div class="col-12 col-xl-4">
                <section class="sales-panel">
                    <div class="sales-panel-head">
                        <i class="fas fa-user-tie"></i>
                        <span>Customer</span>
                        <span class="badge bg-secondary ms-auto">Locked</span>
                    </div>
                    <div class="sales-panel-body">
                        <div class="sales-edit-customer-card">
                            <div class="sales-edit-customer-name">' . htmlspecialchars($customerLabel, ENT_QUOTES) . '</div>
                            <div class="sales-edit-customer-meta">' . htmlspecialchars($customerSub, ENT_QUOTES) . '</div>
                        </div>

                        <div class="mt-3 sales-meta-grid">
                            <div>
                                <label class="form-label small">Branch</label>
                                <select id="branch_id" class="form-select"' . ($branchLocked ? ' disabled' : '') . '></select>
                                ' . ($branchLocked ? '<input type="hidden" id="branch_id_locked" value="' . (int)($inv['branch_id'] ?? 0) . '">' : '') . '
                            </div>
                            <div>
                                <label class="form-label small">Date</label>
                                <input type="date" id="invoice_date" class="form-control" value="' . htmlspecialchars($invoiceDate, ENT_QUOTES) . '">
                            </div>
                            <div>
                                <label class="form-label small">Sales By</label>
                                <select id="sales_by" class="form-select"></select>
                            </div>
                            <div>
                                <label class="form-label small">Sales Person</label>
                                <select id="sales_person" class="form-select"></select>
                            </div>
                            <div class="col-span-2">
                                <label class="form-label small">Narration</label>
                                <input type="text" id="narration" class="form-control" value="' . htmlspecialchars($inv['narration'] ?? '', ENT_QUOTES) . '" placeholder="Delivery note...">
                            </div>
                        </div>
                    </div>
                    <div id="customerDetailsPanel" class="sales-customer-due">
                        <div class="due-row"><span>Credit limit</span><strong id="disp_limit">0</strong></div>
                        <div class="due-row"><span>Current due</span><strong id="disp_due">0</strong></div>
                        <div class="due-row highlight"><span>Balance left</span><strong id="disp_left">0</strong></div>
                        <span id="disp_name" class="d-none"></span>
                        <span id="disp_shop" class="d-none"></span>
                        <span id="disp_mobile" class="d-none"></span>
                        <span id="disp_address" class="d-none"></span>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-8">
                <section class="sales-panel sales-panel-product">
                    <div class="sales-panel-head">
                        <i class="fas fa-barcode"></i>
                        <span>Add or change lines</span>
                    </div>
                    <div class="sales-panel-body">
                        <label class="form-label small text-muted mb-1">Product name or code</label>
                        <div class="position-relative mb-3">
                            <input type="text" id="productSearch" class="form-control sales-search-input"
                                   placeholder="Scan barcode or search product..." autocomplete="off">
                            <div id="productSuggestions" class="sales-suggest-list"></div>
                        </div>

                        <div id="BranchStock" class="sales-stock-banner d-none"></div>

                        <div id="priceRangePanel" class="sales-price-band d-none" aria-live="polite">
                            <div class="sales-price-band-head">
                                <span class="sales-price-band-title"><i class="fas fa-tags"></i> Selling range</span>
                                <button type="button" class="btn btn-sm btn-outline-primary sales-use-default-btn" id="btnUseDefaultRate" title="Set rate to default">
                                    Use default
                                </button>
                            </div>
                            <div class="sales-price-band-labels">
                                <span>Min <b id="priceBandMin">0</b></span>
                                <span class="sales-price-band-default">Default <b id="priceBandDefault">0</b></span>
                                <span>Max <b id="priceBandMax">0</b></span>
                            </div>
                            <div class="sales-price-band-track-wrap">
                                <div class="sales-price-band-track">
                                    <div class="sales-price-band-fill" id="priceBandFill"></div>
                                    <div class="sales-price-band-default-mark" id="priceBandDefaultMark" title="Default price"></div>
                                    <div class="sales-price-band-thumb" id="priceBandThumb"></div>
                                </div>
                            </div>
                            <div id="priceRangeStatus" class="sales-price-band-status sales-price-ok">Rate is within allowed range</div>
                        </div>

                        <div class="sales-entry-toolbar">
                            <div class="sales-entry-group sales-entry-rate">
                                <label class="sales-entry-label" for="sales_rate">Rate (৳)</label>
                                <input type="number" step="0.01" id="sales_rate" class="form-control sales-entry-input" placeholder="0.00" inputmode="decimal">
                            </div>
                            <div class="sales-entry-group sales-entry-qty">
                                <label class="sales-entry-label" for="quantity">Qty</label>
                                <input type="number" step="0.01" id="quantity" class="form-control sales-entry-input text-center" value="1" inputmode="decimal">
                            </div>
                            <button type="button" id="addToCartBtn" class="btn btn-primary sales-add-btn">
                                <i class="fas fa-cart-plus"></i>
                                <span class="sales-add-text">Add</span>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </form>

    <section class="sales-cart-dock sales-edit-cart mt-3">
        <div class="sales-cart-dock-head">
            <div>
                <strong><i class="fas fa-receipt me-1"></i> Invoice lines</strong>
                <span class="text-muted small ms-1">— swipe left on mobile to remove</span>
            </div>
        </div>
        <div id="single-cart-area" class="sales-edit-cart-body cart-container" data-customer_id="' . (int)($inv['customer_id'] ?? 0) . '">
            <div class="text-center text-muted py-5">
                <i class="fas fa-spinner fa-spin fa-2x mb-2 opacity-50"></i>
                <p class="mb-0">Loading invoice lines…</p>
            </div>
        </div>
    </section>';
}

$content .= '
</div>';

if ($blockedReason === '') {
    $content .= '
<div class="sales-pos-sticky-bar" id="posStickyBar">
    <div class="d-flex justify-content-between align-items-center gap-2 w-100">
        <div class="sticky-summary" id="posStickySummary">Loading…</div>
        <button type="button" class="btn btn-success btn-finalize flex-shrink-0" id="posStickyFinalize" disabled>
            <i class="fas fa-save"></i> <span id="posStickyActionLabel">Update</span>
        </button>
    </div>
</div>';
}

$content .= '

<input type="hidden" id="base_url" value="' . BASE_URL . '">
<script>
window.CAN_OVERRIDE_BRANCH = ' . (!empty($can_override_branch) ? 'true' : 'false') . ';
window.SESSION_BRANCH_ID = ' . (int)($session_branch_id ?? 1) . ';
window.ACTIVE_BRANCH_NAME = ' . json_encode($session_branch_name ?? 'Branch') . ';
window.SALES_EDIT_INVOICE_BRANCH = ' . (int)($inv['branch_id'] ?? 0) . ';
</script>
';
if ($blockedReason === '') {
    $content .= '
<script src="' . BASE_URL . 'assets/js/sales.js"></script>
<script src="' . BASE_URL . 'assets/js/sales-edit.js"></script>';
}

require_once '../app/views/layouts/main.php';