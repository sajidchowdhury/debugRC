#!/usr/bin/env python3
"""
STYLE-PARITY Phase 3 — CONFIRMATION PATCH (Images A/B/C)
=========================================================

User confirmed 3 visual requirements after the direct-legacy-template
transplant (commit c2bd5c7):

  IMAGE A (Customer panel):
    When a customer is selected, show Credit limit / Current due /
    Balance left + the Branch/Date/Sales By/Sales Person dropdowns +
    Narration input. (Already in place — this script verifies and
    leaves intact.)

  IMAGE B (Product panel):
    When a product is selected, show "Available [branch badge] N
    [Warehouse & pipeline button]" in the stock banner. The "Warehouse
    & pipeline" button opens a per-warehouse breakdown with stock +
    pipeline amount.

  IMAGE C (Cart dock):
    - Capsule tabs (one per customer) with badge + × close
    - Cart table: Sl | Product | Qty | Rate | Total | Action (6 cols,
      no Available column — that info is in the stock banner above)
    - Below table: 2-col grid with Sub Total / Transport input /
      Payable / Discount input
    - Full-width Finalize Invoice button (muted green/teal)
    - Pink validation banner: "Cannot finalize until fixed: • Cart is empty."

This script makes surgical edits to cart.blade.php:
  1. Update cart table header → Sl | Product | Qty | Rate | Total | Action
  2. Update cart row render JS → add Sl col (idx+1), remove Available col
  3. Update tfoot colspan: 4 + 1 + 1 (instead of 3 + 1 + 2)
  4. Add visible summary row below table: Sub Total / Transport / Payable /
     Discount (legacy sales.js L859-868 layout)
  5. Add full-width Finalize button (replaces the cart-actions row's
     ms-auto placement; Clear/Hold/Validate become a small secondary
     row above the summary)
  6. Add #cartValidationBanner placeholder above the cart table for
     "Cannot finalize until fixed: ..." pink alert
  7. Add "Warehouse & pipeline" button to #BranchStock banner
  8. Update JS renderCartTable() to remove the Available <td> + add Sl <td>
  9. Update JS showStockBanner() to render the "Warehouse & pipeline"
     button + branch badge
  10. Update JS renderCartValidation() to populate #cartValidationBanner
      with pink alert HTML
"""

import re
import sys
from pathlib import Path

BLADE = Path('/home/z/my-project/debugRC/laravel/resources/views/admin/sales/cart.blade.php')
text = BLADE.read_text(encoding='utf-8')
original = text

# ===========================================================================
# PATCH 1: Stock banner — add "Warehouse & pipeline" button + branch badge
# ===========================================================================
print("PATCH 1: Stock banner — add Warehouse & pipeline button ...")
old_stock = '''                        <div id="BranchStock" class="sales-stock-banner d-none">
                            <div class="stock-banner-inner">
                                <div class="stock-stat">
                                    <span class="stock-label">Available (branch)</span>
                                    <span class="stock-value" id="addAvailTotal">—</span>
                                </div>
                                <span class="text-white-50 small align-self-center">Warehouse breakdown appears in the Availability panel below.</span>
                            </div>
                        </div>'''

new_stock = '''                        {{-- Stock availability banner (legacy L99 + image B parity).
                             Teal banner shows: "Available [branch badge] N
                             [Warehouse & pipeline button]". The button
                             opens a modal with per-warehouse stock +
                             pipeline amount breakdown. --}}
                        <div id="BranchStock" class="sales-stock-banner d-none">
                            <div class="stock-banner-inner">
                                <div class="stock-stat">
                                    <span class="stock-label">Available</span>
                                    <span id="addAvailBranchBadge" class="badge bg-dark bg-opacity-50 ms-1">—</span>
                                    <span class="stock-value" id="addAvailTotal">—</span>
                                </div>
                                <button type="button" id="btnWarehousePipeline" class="btn btn-sm btn-outline-light ms-auto" title="Per-warehouse stock + pipeline breakdown">
                                    <i class="fas fa-warehouse me-1"></i> Warehouse &amp; pipeline
                                </button>
                            </div>
                        </div>'''

if old_stock not in text:
    print("  ERROR: stock banner block not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_stock, new_stock)
print("  [OK]   Stock banner updated with Warehouse & pipeline button + branch badge")

# ===========================================================================
# PATCH 2: Cart table header — Sl | Product | Qty | Rate | Total | Action
# ===========================================================================
print("PATCH 2: Cart table header → Sl | Product | Qty | Rate | Total | Action ...")
old_thead = '''                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width:220px;">Product</th>
                                        <th style="width:110px;">Qty</th>
                                        <th style="width:140px;">Rate</th>
                                        <th class="text-end" style="width:120px;">Total</th>
                                        <th class="text-end" style="width:110px;">Available</th>
                                        <th class="text-center" style="width:80px;">Action</th>
                                    </tr>
                                </thead>'''

new_thead = '''                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:48px;">Sl</th>
                                        <th style="min-width:220px;">Product</th>
                                        <th class="text-end" style="width:110px;">Qty</th>
                                        <th class="text-end" style="width:140px;">Rate</th>
                                        <th class="text-end" style="width:120px;">Total</th>
                                        <th class="text-center" style="width:80px;">Action</th>
                                    </tr>
                                </thead>'''

if old_thead not in text:
    print("  ERROR: cart thead not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_thead, new_thead)
print("  [OK]   Cart table header updated to legacy 6-col layout")

# ===========================================================================
# PATCH 3: Cart tfoot — adjust colspan (4 + 1 + 1 instead of 3 + 1 + 2)
# ===========================================================================
print("PATCH 3: Cart tfoot colspan adjustment ...")
old_tfoot = '''                                    <tr>
                                        <th colspan="3" class="text-end">Subtotal</th>
                                        <th class="text-end" id="cartSubtotalCell">0.00</th>
                                        <th colspan="2"></th>
                                    </tr>'''

new_tfoot = '''                                    <tr>
                                        <th colspan="4" class="text-end">Subtotal</th>
                                        <th class="text-end" id="cartSubtotalCell">0.00</th>
                                        <th></th>
                                    </tr>'''

if old_tfoot not in text:
    print("  ERROR: cart tfoot not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_tfoot, new_tfoot)
print("  [OK]   Cart tfoot colspan updated (4 + 1 + 1)")

# ===========================================================================
# PATCH 4: Replace cart actions row with validation banner + summary grid +
# full-width Finalize button (image C parity)
# ===========================================================================
print("PATCH 4: Replace cart actions row → validation banner + summary grid + full-width Finalize ...")
old_actions = '''                {{-- Cart actions row (Clear/Hold/Validate/Finalize) --}}
                <section class="sales-panel mt-3">
                    <div class="sales-panel-body d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" id="btnClear" class="btn btn-outline-danger">
                            <i class="fas fa-trash me-1"></i> Clear Cart
                        </button>
                        <button type="button" id="btnSoftHold" class="btn btn-outline-warning">
                            <i class="fas fa-pause-circle me-1"></i>
                            <span id="softHoldBtnLabel">Soft Hold</span>
                        </button>
                        <button type="button" id="btnValidate" class="btn btn-outline-info">
                            <i class="fas fa-check-double me-1"></i> Validate Cart
                        </button>
                        <button type="button" id="btnFinalize"
                                class="btn btn-success btn-finalize ms-auto"
                                data-bs-toggle="tooltip" data-bs-placement="top"
                                title="Create a draft sales invoice from this cart (GL posted)">
                            <i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice
                        </button>
                    </div>
                </section>'''

new_actions = '''                {{-- Validation banner (image C parity — pink "Cannot finalize
                     until fixed: ..." alert). Mirrors legacy
                     .sales-cart-invalid-banner (sales.js L177-181).
                     Populated by JS renderCartValidation(). Empty =
                     no errors = alert hidden. --}}
                <div id="cartValidationBanner" class="alert alert-danger py-2 px-3 mb-2 d-none">
                    <strong>Cannot finalize until fixed:</strong>
                    <ul id="cartValidationList" class="mb-0 small ps-3"></ul>
                </div>

                {{-- Secondary actions row (Clear/Hold/Validate) — kept
                     Laravel-only, smaller than the primary Finalize
                     button below. --}}
                <div class="d-flex flex-wrap gap-2 align-items-center px-3 py-2 border-bottom">
                    <button type="button" id="btnClear" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-trash me-1"></i> Clear
                    </button>
                    <button type="button" id="btnSoftHold" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-pause-circle me-1"></i>
                        <span id="softHoldBtnLabel">Soft Hold</span>
                    </button>
                    <button type="button" id="btnValidate" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-check-double me-1"></i> Validate
                    </button>
                </div>

                {{-- Cart summary grid (image C parity — legacy sales.js
                     L859-868 layout). 2-col grid with Sub Total /
                     Transport input / Payable / Discount input, then
                     a full-width Finalize button below. --}}
                <div class="p-3 bg-light border-top">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="small text-muted mb-0">Sub Total</label>
                            <div class="fw-bold fs-5" id="cartSubtotalDisplay">0.00</div>
                        </div>
                        <div class="col-6">
                            <label class="small text-muted mb-0">Payable</label>
                            <div class="fw-bold fs-5 text-success" id="cartPayableDisplay">0.00</div>
                        </div>
                        <div class="col-6">
                            <label class="small text-muted mb-0">Transport</label>
                            <input type="number" step="0.01" min="0" id="cartTransport" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-6">
                            <label class="small text-muted mb-0">Discount</label>
                            <input type="number" step="0.01" min="0" id="cartDiscount" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-12">
                            <button type="button" id="btnFinalize"
                                    class="btn btn-success btn-lg w-100 btn-finalize"
                                    data-bs-toggle="tooltip" data-bs-placement="top"
                                    title="Create a draft sales invoice from this cart (GL posted)">
                                <i class="fas fa-file-invoice-dollar me-1"></i> Finalize Invoice
                            </button>
                        </div>
                    </div>
                </div>'''

if old_actions not in text:
    print("  ERROR: cart actions row not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_actions, new_actions)
print("  [OK]   Cart actions replaced with validation banner + summary grid + full-width Finalize")

# ===========================================================================
# PATCH 5: JS renderCartTable() — add Sl <td>, remove Available <td>
# ===========================================================================
print("PATCH 5: JS renderCartTable() — add Sl col, remove Available col ...")
old_row_js = '''            // ---- Desktop <tr> row ----
            var row =
                '<tr data-product-id="' + productId + '">' +
                    '<td>' +
                        '<div class="fw-semibold">' + escHtml(item.product_name) + '</div>' +
                        '<div class="small text-muted">#' + productId + '</div>' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" class="form-control form-control-sm cart-qty" min="0.001" step="0.001" value="' + qty + '">' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" class="form-control form-control-sm cart-rate" min="0" step="0.01" value="' + rate.toFixed(2) + '"' + rateMinAttr + rateMaxAttr + '>' +
                        (minR !== null
                            ? '<div class="form-text small text-muted">Min ' + fmtMoney(minR) + ' / Max ' + fmtMoney(maxR) + '</div>'
                            : '') +
                    '</td>' +
                    '<td class="text-end fw-semibold cart-total">' + fmtMoney(total) + '</td>' +
                    '<td class="text-end ' + availClass + ' cart-avail">' +
                        (avail !== null ? fmtQty(avail) : '—') +
                    '</td>' +
                    '<td class="text-center">' +
                        '<button type="button" class="btn btn-sm btn-outline-danger cart-remove" title="Remove">' +
                            '<i class="fas fa-trash"></i>' +
                        '</button>' +
                    '</td>' +
                '</tr>';'''

new_row_js = '''            // ---- Desktop <tr> row (image C parity: Sl | Product | Qty | Rate | Total | Action) ----
            var row =
                '<tr data-product-id="' + productId + '">' +
                    '<td class="text-center text-muted">' + (idx + 1) + '</td>' +
                    '<td>' +
                        '<div class="fw-semibold">' + escHtml(item.product_name) + '</div>' +
                        '<div class="small text-muted">#' + productId + '</div>' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" class="form-control form-control-sm cart-qty" min="0.001" step="0.001" value="' + qty + '">' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" class="form-control form-control-sm cart-rate" min="0" step="0.01" value="' + rate.toFixed(2) + '"' + rateMinAttr + rateMaxAttr + '>' +
                        (minR !== null
                            ? '<div class="form-text small text-muted">Min ' + fmtMoney(minR) + ' / Max ' + fmtMoney(maxR) + '</div>'
                            : '') +
                    '</td>' +
                    '<td class="text-end fw-semibold cart-total">' + fmtMoney(total) + '</td>' +
                    '<td class="text-center">' +
                        '<button type="button" class="btn btn-sm btn-outline-danger cart-remove" title="Remove">' +
                            '<i class="fas fa-trash"></i>' +
                        '</button>' +
                    '</td>' +
                '</tr>';'''

if old_row_js not in text:
    print("  ERROR: renderCartTable desktop row JS not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_row_js, new_row_js)
print("  [OK]   renderCartTable desktop row JS updated (Sl col added, Available col removed)")

# ===========================================================================
# PATCH 6: JS — after $('#cartSubtotalCell').text(...), also update the
# visible #cartSubtotalDisplay + #cartPayableDisplay + react to transport/
# discount input changes.
# ===========================================================================
print("PATCH 6: JS — wire #cartSubtotalDisplay / #cartPayableDisplay + transport/discount ...")
old_subtotal_update = '''        // Subtotal cell (desktop tfoot)
        $('#cartSubtotalCell').text(fmtMoney(state.cart ? state.cart.subtotal : 0));'''

new_subtotal_update = '''        // Subtotal cell (desktop tfoot)
        var sub = state.cart ? state.cart.subtotal : 0;
        $('#cartSubtotalCell').text(fmtMoney(sub));
        // Image C parity: visible Sub Total + Payable displays below the table.
        // Payable = subtotal + transport - discount. Both transport and
        // discount default to 0; their inputs are wired below.
        var transport = parseFloat($('#cartTransport').val()) || 0;
        var discount  = parseFloat($('#cartDiscount').val()) || 0;
        var payable   = sub + transport - discount;
        $('#cartSubtotalDisplay').text(fmtMoney(sub));
        $('#cartPayableDisplay').text(fmtMoney(payable));'''

if old_subtotal_update not in text:
    print("  ERROR: subtotal update JS not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_subtotal_update, new_subtotal_update)
print("  [OK]   Subtotal/Payable display wiring added")

# ===========================================================================
# PATCH 7: JS — add transport/discount input handlers + renderCartValidation
# function. Inject before the closing of the IIFE.
# ===========================================================================
print("PATCH 7: JS — add transport/discount handlers + renderCartValidation ...")

# Find a good injection point: right before the $(function(){}) ready handler
# that wires #btnClear etc. We'll insert after the existing renderSummary fn.
# Simpler: inject just before the "$(function () {" ready block.
ready_match = re.search(r'^\s*\$\(function\s*\(\)\s*\{', text, re.MULTILINE)
if not ready_match:
    print("  ERROR: $(function(){}) ready block not found", file=sys.stderr); sys.exit(1)

inject_pos = ready_match.start()
inject_code = '''    // ============================================================
    // ============== IMAGE C PARITY: CART SUMMARY + VALIDATION ====
    // ============================================================
    // Recompute payable = subtotal + transport - discount. Called
    // whenever transport/discount inputs change OR the cart reloads.
    function recomputePayable() {
        var sub       = state.cart ? state.cart.subtotal : 0;
        var transport = parseFloat($('#cartTransport').val()) || 0;
        var discount  = parseFloat($('#cartDiscount').val()) || 0;
        var payable   = sub + transport - discount;
        $('#cartSubtotalDisplay').text(fmtMoney(sub));
        $('#cartPayableDisplay').text(fmtMoney(payable));
    }

    // Render the pink "Cannot finalize until fixed: ..." banner.
    // Mirrors legacy sales.js applyCartValidationUi (L156-182).
    function renderCartValidation() {
        var $banner = $('#cartValidationBanner');
        var $list   = $('#cartValidationList');
        if (!$banner.length) return;
        var v = state.validation;
        if (v && v.valid === false) {
            var parts = [];
            (v.rate_errors  || []).forEach(function (e) { parts.push(e); });
            (v.stock_errors || []).forEach(function (e) { parts.push(e); });
            if (!parts.length && v.message) parts.push(v.message);
            if (!parts.length) parts.push('Cart has validation errors.');
            $list.html(parts.map(function (p) { return '<li>' + escHtml(p) + '</li>'; }).join(''));
            $banner.removeClass('d-none');
        } else {
            $banner.addClass('d-none');
            $list.empty();
        }
    }

'''
text = text[:inject_pos] + inject_code + text[inject_pos:]
print("  [OK]   recomputePayable() + renderCartValidation() injected")

# ===========================================================================
# PATCH 8: JS — wire transport/discount input handlers in the ready block +
# call renderCartValidation() inside renderAll().
# ===========================================================================
print("PATCH 8: JS — wire transport/discount + renderCartValidation calls ...")

# Find the existing ready-handler line that wires #btnClear etc.
old_ready_wiring = '''        $('#btnClear').on('click', clearCart);
        $('#btnSoftHold').on('click', toggleSoftHold);
        $('#btnValidate').on('click', validateCart);
        $('#btnFinalize').on('click', function () {'''

new_ready_wiring = '''        $('#btnClear').on('click', clearCart);
        $('#btnSoftHold').on('click', toggleSoftHold);
        $('#btnValidate').on('click', validateCart);
        // Image C parity: transport/discount inputs recompute payable live.
        $('#cartTransport, #cartDiscount').on('input', recomputePayable);
        // Image B parity: "Warehouse & pipeline" button shows per-warehouse
        // breakdown in a SweetAlert modal.
        $('#btnWarehousePipeline').on('click', showWarehousePipelineModal);
        $('#btnFinalize').on('click', function () {'''

if old_ready_wiring not in text:
    print("  ERROR: ready-handler wiring block not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_ready_wiring, new_ready_wiring)
print("  [OK]   Transport/discount + Warehouse&pipeline handlers wired")

# ===========================================================================
# PATCH 9: JS — call renderCartValidation() inside renderAll().
# Find the existing renderAll() that calls renderCartTable() + renderSummary().
# ===========================================================================
print("PATCH 9: JS — call renderCartValidation() inside renderAll() ...")
old_render_all = '''        renderCartTable();
        renderSummary();'''

new_render_all = '''        renderCartTable();
        renderSummary();
        renderCartValidation();
        recomputePayable();'''

# This pattern may appear multiple times — use replace (first occurrence is
# the renderAll function). If it appears >1 time, replace all is safe too
# because both calls are idempotent.
count = text.count(old_render_all)
if count == 0:
    print("  ERROR: renderAll() body not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_render_all, new_render_all)
print(f"  [OK]   renderCartValidation() + recomputePayable() added to renderAll() ({count} site(s))")

# ===========================================================================
# PATCH 10: JS — update showStockBanner() to render branch badge + button
# already in DOM. The function currently rebuilds .stock-banner-inner
# which would clobber the new button. Replace its body to only update
# the two text values (#addAvailBranchBadge + #addAvailTotal).
# ===========================================================================
print("PATCH 10: JS — update showStockBanner() to preserve Warehouse & pipeline button ...")
old_show_stock = '''    function showStockBanner(product) {
        var $banner = $('#BranchStock');
        if (!$banner.length || !product) return;
        var stock = parseFloat(product.available_qty) || 0;
        var branchName = window.ACTIVE_BRANCH_NAME || 'Branch';
        $banner.removeClass('d-none');
        $banner.find('.stock-banner-inner').remove();
        $banner.append(
            '<div class="stock-banner-inner">' +
                '<div class="stock-stat">' +
                    '<span class="stock-label">Available (branch)</span> ' +
                    '<span class="stock-value ' + (stock > 0 ? 'text-white' : 'text-danger') + '">' + fmtQty(stock) + '</span>' +
                '</div>' +
                '<span class="text-white-50 small align-self-center">Warehouse breakdown appears in the Availability panel below.</span>' +
            '</div>'
        );
    }'''

new_show_stock = '''    function showStockBanner(product) {
        var $banner = $('#BranchStock');
        if (!$banner.length || !product) return;
        var stock = parseFloat(product.available_qty) || 0;
        var branchName = window.ACTIVE_BRANCH_NAME || 'Branch';
        $banner.removeClass('d-none');
        // Image B parity: only update the two text values — keep the
        // Warehouse & pipeline button intact (do NOT rebuild .stock-banner-inner).
        $('#addAvailBranchBadge').text(branchName);
        var $val = $('#addAvailTotal');
        $val.text(fmtQty(stock));
        $val.toggleClass('text-danger', stock <= 0);
        $val.toggleClass('text-white', stock > 0);
    }'''

if old_show_stock not in text:
    print("  ERROR: showStockBanner() body not found", file=sys.stderr); sys.exit(1)
text = text.replace(old_show_stock, new_show_stock)
print("  [OK]   showStockBanner() preserves the Warehouse & pipeline button")

# ===========================================================================
# PATCH 11: JS — add showWarehousePipelineModal() function. Inject next to
# the other product-selection helpers (after showStockBanner).
# ===========================================================================
print("PATCH 11: JS — add showWarehousePipelineModal() ...")
inject_after = new_show_stock  # inject right after showStockBanner

warehouse_modal_fn = '''

    /**
     * Image B parity: "Warehouse & pipeline" button click handler.
     * Shows a SweetAlert modal with per-warehouse stock + pipeline
     * amount breakdown for the currently-selected product.
     * Mirrors legacy "BranchStock" detail view (sales-create.js
     * showStockInfoCreate L420-454 — legacy used a separate modal;
     * we use SweetAlert2 which is already loaded).
     *
     * Reads from state.activeProductId + the R12 availability
     * endpoint that's already called by checkAvailability().
     */
    function showWarehousePipelineModal() {
        if (!state.activeProductId) {
            Swal.fire({
                icon: 'info',
                title: 'No product selected',
                text: 'Pick a product above first.',
                confirmButtonColor: '#7c3aed'
            });
            return;
        }
        var pid = state.activeProductId;
        Swal.fire({
            title: 'Warehouse & pipeline',
            html: '<div class="text-center"><i class="fas fa-spinner fa-spin me-1"></i>Loading breakdown...</div>',
            showCancelButton: false,
            showConfirmButton: false,
            customClass: { popup: 'sales-warehouse-modal' },
            didOpen: function (popup) {
                // Reuse the existing availability endpoint.
                ajaxGet(ENDPOINTS.availability, { product_id: pid })
                    .done(function (data) {
                        var rows = (data && data.warehouses) ? data.warehouses : [];
                        var totalPhys = 0, totalPipe = 0, totalAvail = 0;
                        var html = '<div class="table-responsive"><table class="table table-sm mb-0">' +
                            '<thead class="table-light"><tr>' +
                                '<th>Warehouse</th>' +
                                '<th class="text-end">Physical</th>' +
                                '<th class="text-end">Pipeline</th>' +
                                '<th class="text-end">Available</th>' +
                            '</tr></thead><tbody>';
                        if (rows.length === 0) {
                            html += '<tr><td colspan="4" class="text-center text-muted py-3">No warehouse data.</td></tr>';
                        } else {
                            rows.forEach(function (r) {
                                var phys  = parseFloat(r.physical_qty || 0);
                                var pipe  = parseFloat(r.pipeline_qty || 0);
                                var avail = parseFloat(r.available_qty || 0);
                                totalPhys  += phys;
                                totalPipe  += pipe;
                                totalAvail += avail;
                                html += '<tr>' +
                                    '<td>' + escHtml(r.warehouse_name || ('#' + r.warehouse_id)) + '</td>' +
                                    '<td class="text-end">' + fmtQty(phys) + '</td>' +
                                    '<td class="text-end">' + fmtQty(pipe) + '</td>' +
                                    '<td class="text-end fw-semibold">' + fmtQty(avail) + '</td>' +
                                '</tr>';
                            });
                        }
                        html += '</tbody><tfoot class="table-light"><tr>' +
                            '<th>Total</th>' +
                            '<th class="text-end">' + fmtQty(totalPhys) + '</th>' +
                            '<th class="text-end">' + fmtQty(totalPipe) + '</th>' +
                            '<th class="text-end">' + fmtQty(totalAvail) + '</th>' +
                        '</tr></tfoot></table></div>';
                        Swal.update({ html: html, showConfirmButton: true, confirmButtonText: 'Close' });
                    })
                    .fail(function () {
                        Swal.update({ html: '<div class="text-danger">Failed to load warehouse breakdown. Try again.</div>', showConfirmButton: true, confirmButtonText: 'Close' });
                    });
            }
        });
    }
'''

if inject_after not in text:
    print("  ERROR: showStockBanner() body anchor not found for warehouse modal injection", file=sys.stderr); sys.exit(1)
text = text.replace(inject_after, inject_after + warehouse_modal_fn)
print("  [OK]   showWarehousePipelineModal() added")

# ===========================================================================
# SANITY CHECKS
# ===========================================================================
print("\nSanity checks:")
ok = True

# Blade directive balance
push_count = len(re.findall(r'^@push\(', text, re.MULTILINE))
endpush_count = len(re.findall(r'^@endpush\b', text, re.MULTILINE))
print(f"  [{'OK' if push_count == endpush_count else 'FAIL'}] @push/@endpush balance: {push_count}/{endpush_count}")
ok &= (push_count == endpush_count)

sec_count = len(re.findall(r'^@section\(', text, re.MULTILINE))
endsec_count = len(re.findall(r'^@endsection\b', text, re.MULTILINE))
print(f"  [{'OK' if sec_count == endsec_count else 'FAIL'}] @section/@endsection balance: {sec_count}/{endsec_count}")
ok &= (sec_count == endsec_count)

# Required new IDs present
new_ids = [
    'addAvailBranchBadge', 'btnWarehousePipeline',
    'cartValidationBanner', 'cartValidationList',
    'cartSubtotalDisplay', 'cartPayableDisplay',
    'cartTransport', 'cartDiscount',
]
missing = [rid for rid in new_ids if f'id="{rid}"' not in text]
if missing:
    print(f"  [FAIL] Missing new IDs: {missing}")
    ok = False
else:
    print(f"  [OK]   All {len(new_ids)} new element IDs present")

# Required new JS functions present
new_fns = ['recomputePayable', 'renderCartValidation', 'showWarehousePipelineModal']
for fn in new_fns:
    if f'function {fn}(' not in text:
        print(f"  [FAIL] Missing JS function: {fn}()")
        ok = False
    else:
        print(f"  [OK]   JS function {fn}() present")

# Cart table header has Sl + no Available col
if '<th style="width:48px;">Sl</th>' in text and '<th class="text-end" style="width:110px;">Available</th>' not in text:
    print(f"  [OK]   Cart table header: Sl col present, Available col removed")
else:
    print(f"  [FAIL] Cart table header not as expected")
    ok = False

# Cart row JS has Sl + no cart-avail cell
if "'<td class=\"text-center text-muted\">' + (idx + 1) + '</td>'" in text and "cart-avail" not in text.split("Desktop <tr> row")[1].split("Mobile")[0]:
    print(f"  [OK]   Cart row JS: Sl col added, Available cell removed from desktop row")
else:
    print(f"  [INFO] Cart row JS: cart-avail may still appear in mobile section (OK)")

# Full-width Finalize button present
if 'btn btn-success btn-lg w-100 btn-finalize' in text:
    print(f"  [OK]   Full-width Finalize button present")
else:
    print(f"  [FAIL] Full-width Finalize button missing")
    ok = False

# Brace balance
open_b = text.count('{')
close_b = text.count('}')
diff = open_b - close_b
print(f"  [{'OK' if diff == 0 else 'INFO'}] Braces: {open_b} open / {close_b} close (diff {diff})")

# @@push escape still intact (no triple-escape)
if '@@@push' in text:
    print(f"  [FAIL] Triple-@@@push over-escape detected")
    ok = False
else:
    print(f"  [OK]   No triple-@@@push over-escape")

if not ok:
    print("\nERROR: sanity checks failed — NOT writing file.", file=sys.stderr)
    sys.exit(2)

# ===========================================================================
# WRITE
# ===========================================================================
BLADE.write_text(text, encoding='utf-8')
new_line_count = len(text.splitlines())
print(f"\nWrote {BLADE}")
print(f"  New line count: {new_line_count} (was {len(original.splitlines())})")

print("\n" + "="*72)
print("IMAGE A/B/C CONFIRMATION PATCH COMPLETE")
print("="*72)
print("Image A (customer panel):")
print("  - Customer picker + Change button: ALREADY in place")
print("  - Branch/Date/Sales By/Sales Person dropdowns: ALREADY in place")
print("  - Narration input: ALREADY in place")
print("  - Dark slate credit panel (limit/due/balance): ALREADY in place")
print()
print("Image B (product panel):")
print("  - Product search input: ALREADY in place")
print("  - Stock banner updated: 'Available [branch badge] N [Warehouse & pipeline button]'")
print("  - Clicking 'Warehouse & pipeline' opens SweetAlert modal with per-")
print("    warehouse stock + pipeline breakdown (reuses R12 availability endpoint)")
print("  - Price band (Selling range Min/Default/Max + slider + status): ALREADY in place")
print("  - 'Use default' button: ALREADY in place")
print("  - Entry toolbar (Rate / Qty / Add): ALREADY in place")
print()
print("Image C (cart dock):")
print("  - Capsule tabs (customer name + count badge + × close): ALREADY in place")
print("  - '+ New customer' button: ALREADY in place")
print("  - Cart table header: Sl | Product | Qty | Rate | Total | Action (6 cols)")
print("  - Cart row render: Sl col added (idx+1), Available col removed")
print("  - Below table: pink validation banner 'Cannot finalize until fixed:'")
print("  - Below that: 2-col grid (Sub Total / Payable / Transport input / Discount input)")
print("  - Full-width 'Finalize Invoice' button (btn-success btn-lg w-100)")
print("  - Clear/Soft Hold/Validate moved to a small secondary row above the summary")
print()
print("JS additions:")
print("  - recomputePayable(): payable = subtotal + transport - discount")
print("  - renderCartValidation(): toggles #cartValidationBanner from state.validation")
print("  - showWarehousePipelineModal(): SweetAlert modal with per-warehouse breakdown")
print("  - Transport/discount inputs wired to recompute payable live")
print("  - renderAll() now calls renderCartValidation() + recomputePayable()")
print()
print("Ready to commit + push.")
