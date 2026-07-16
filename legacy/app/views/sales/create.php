<?php
$title = 'Create Sales Invoice';

$content = '
<link rel="stylesheet" href="' . BASE_URL . 'assets/css/sales-pos.css">
<meta name="theme-color" content="#4f46e5">
<meta name="mobile-web-app-capable" content="yes">

<div id="sales-create-app" class="sales-create-app">
    <header class="sales-create-header">
        <div>
            <h1 class="sales-create-title">New Sale</h1>
            <p class="sales-create-sub">Fast billing · multiple customers · live stock</p>
        </div>
    
        <a href="' . BASE_URL . 'sales/today" class="btn btn-light btn-sm sales-header-btn">
            <i class="fas fa-list"></i> Today
        </a>
    </header>

    <form id="kt_form" class="sales-create-form">
        <input type="hidden" id="related_id" value="New">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) . '">
        <input type="hidden" id="customer_id" value="">
        <script>window.CSRF_TOKEN = ' . json_encode($_SESSION['csrf_token'] ?? '') . '; window.SALES_CREATE_MODE = true;</script>

        <div class="row g-3">
            <!-- Customer -->
            <div class="col-12 col-xl-4">
                <section class="sales-panel">
                    <div class="sales-panel-head">
                        <i class="fas fa-user-tie"></i>
                        <span>Customer</span>
                    </div>
                    <div class="sales-panel-body">
                        <label class="form-label small text-muted mb-1" id="customerSearchLabel">Search name, shop or mobile</label>
                        <div class="sales-customer-picker">
                            <div class="position-relative flex-grow-1">
                                <input type="text" id="customerSearch" class="form-control sales-search-input"
                                       placeholder="Type to search customer..." autocomplete="off">
                                <div id="customerSuggestions" class="sales-suggest-list"></div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary sales-change-customer d-none" id="btnChangeCustomer" title="Change customer">
                                Change
                            </button>
                        </div>
                        <div id="customerRecents" class="sales-recents mt-2"></div>

                        <div class="mt-3 sales-meta-grid">
                            <div>
                                <label class="form-label small">Branch</label>
                                <select id="branch_id" class="form-select"></select>
                            </div>
                            <div>
                                <label class="form-label small">Date</label>
                                <input type="date" id="invoice_date" class="form-control" value="' . date('Y-m-d') . '">
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
                                <input type="text" id="narration" class="form-control" placeholder="Delivery note...">
                            </div>
                        </div>
                    </div>
                    <div id="customerDetailsPanel" class="sales-customer-due d-none">
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

            <!-- Product entry -->
            <div class="col-12 col-xl-8">
                <section class="sales-panel sales-panel-product">
                    <div class="sales-panel-head">
                        <i class="fas fa-barcode"></i>
                        <span>Add products</span>
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

    <!-- Multi-cart dock -->
    <section class="sales-cart-dock mt-3">
        <div class="sales-cart-dock-head">
            <div>
                <strong><i class="fas fa-layer-group me-1"></i> Carts</strong>
                <span class="text-muted small ms-1">— switch customers without losing items</span>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="btnFocusCustomer">
                <i class="fas fa-plus"></i> New customer
            </button>
        </div>
        <div class="sales-cart-tabs-wrap">
            <ul class="nav sales-cart-tabs" id="draft-tabs" role="tablist"></ul>
        </div>
        <div class="tab-content sales-tab-panels" id="draft-tab-content">
            <div class="sales-empty-cart text-center text-muted py-5" id="emptyCartHint">
                <i class="fas fa-shopping-basket fa-2x mb-2 opacity-50"></i>
                <p class="mb-0">Select a customer, then add products</p>
            </div>
        </div>
    </section>
</div>

<div class="sales-pos-sticky-bar" id="posStickyBar">
    <div class="d-flex justify-content-between align-items-center gap-2 w-100">
        <div class="sticky-summary" id="posStickySummary">No active cart</div>
        <button type="button" class="btn btn-success btn-finalize flex-shrink-0" id="posStickyFinalize" disabled>
            <i class="fas fa-check-circle"></i> Finalize
        </button>
    </div>
</div>

<input type="hidden" id="base_url" value="' . BASE_URL . '">
<script>
window.CAN_OVERRIDE_BRANCH = ' . (!empty($can_override_branch) ? 'true' : 'false') . ';
window.SESSION_BRANCH_ID = ' . (int)($session_branch_id ?? 1) . ';
window.ACTIVE_BRANCH_NAME = ' . json_encode($session_branch_name ?? 'Branch') . ';
</script>
<script src="' . BASE_URL . 'assets/js/sales.js"></script>
<script src="' . BASE_URL . 'assets/js/sales-create.js"></script>
';

require_once '../app/views/layouts/main.php';
?>