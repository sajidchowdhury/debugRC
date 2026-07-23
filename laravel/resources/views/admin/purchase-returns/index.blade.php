@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/sales-pos.css">
<link rel="stylesheet" href="/assets/css/purchase-return-index.css">
<link rel="stylesheet" href="/assets/css/purchase-return-create.css">
<link rel="stylesheet" href="/assets/css/sales-dt-mobile.css">
@endpush

@section('content')
@php
    // Phase 4 — defaults for the smart-filter state (legacy-faithful shape).
    $filters = array_merge([
        'date_from'   => now()->format('Y-m-d'),
        'date_to'     => now()->format('Y-m-d'),
        'status'      => 'active',
        'date_preset' => 'today',
        'search'      => '',
        'smart_sort'  => true,
    ], is_array($filters ?? null) ? $filters : []);

    $branchName = $session_branch_name ?? (auth()->user()?->branch?->branch_name ?? 'Branch');
    $today      = now()->format('Y-m-d');

    // CSRF for the offcanvas workspace JS (PurchaseReturn.js).
    $csrf = csrf_token();

    // URL params that override localStorage persistence (same as legacy).
    $forceUrlParams = request()->hasAny(['date_from', 'date_to', 'status', 'q', 'grn']);

    // Pre-fill term for the offcanvas workspace (mirrors create.blade.php).
    $prefill = trim((string) (request()->input('grn') ?? request()->input('q') ?? ''));
    $smartSort = (bool) ($filters['smart_sort'] ?? true);

    // BUG-45 (revised): Blade's @json() directive uses explode(',', $expr, 2)
    // internally to split the value from the optional $options/$depth args.
    // ANY array literal with multiple comma-separated entries therefore breaks
    // the compiled PHP ("Unclosed '[' does not match ')'"), regardless of how
    // simple the values are. The fix is to NOT use @json([...]) for multi-key
    // arrays — compute json_encode() in @php and emit via {!! !!} instead.
    $createBoot = json_encode([
        'workspace_id' => 'purchaseReturnOffcanvasRoot',
        'prefill'      => $prefill,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $mainBoot = json_encode([
        'date_from'      => $filters['date_from'] ?? $today,
        'date_to'        => $filters['date_to']   ?? $today,
        'status'         => $filters['status']    ?? 'active',
        'search'         => $filters['search']    ?? '',
        'smart_sort'     => $smartSort,
        'date_preset'    => $filters['date_preset'] ?? 'today',
        'forceUrlParams' => $forceUrlParams,
        'csrf'           => $csrf,
        'endpoints'      => [
            'datatables'     => route('admin.purchase-returns.index'),
            'summary'        => route('admin.purchase-returns.summary'),
            'search_receives'=> route('admin.purchase-returns.search-receives'),
            'receive_details'=> route('admin.purchase-returns.receive-details'),
            'store'          => route('admin.purchase-returns.store'),
            'cancel'         => '',
            'show'           => '',
            'export'         => route('admin.purchase-returns.export'),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
@endphp

<div id="purchase-return-app" class="purchase-return-app container-fluid py-2">
    <header class="purchase-return-hero">
        <div>
            <h1><i class="fas fa-undo-alt me-2"></i>{{ $title }}</h1>
            <p>Return goods to suppliers — stock and GRN qty update automatically</p>
            <span class="purchase-return-branch-tag">
                <i class="fas fa-map-marker-alt me-1"></i>{{ e($branchName) }}
            </span>
        </div>
        <div class="purchase-return-hero-actions d-flex gap-2 flex-shrink-0">
            <button type="button"
                    class="btn btn-light btn-sm"
                    id="openPurchaseReturnCreate"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#purchaseReturnCreateOffcanvas"
                    aria-controls="purchaseReturnCreateOffcanvas">
                <i class="fas fa-plus"></i> Return
            </button>
            <a href="{{ route('admin.purchase-returns.create') }}"
               class="btn btn-light btn-sm d-none d-md-inline-flex"
               title="Full page return">
                <i class="fas fa-external-link-alt"></i>
            </a>
            <a href="{{ route('admin.purchase-returns.export', request()->only(['date_from', 'date_to', 'search', 'status'])) }}"
               class="btn btn-light btn-sm"
               title="Export CSV">
                <i class="fas fa-file-csv"></i>
            </a>
            <button type="button"
                    class="btn btn-light btn-sm collapsed"
                    id="togglePurchaseReturnFilters"
                    data-bs-toggle="collapse"
                    data-bs-target="#purchaseReturnFiltersCollapse"
                    aria-expanded="false"
                    aria-controls="purchaseReturnFiltersCollapse"
                    title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </header>

    {{-- Smart filter panel (collapsible) --}}
    <section class="purchase-return-filters-shell">
        <div class="collapse" id="purchaseReturnFiltersCollapse">
            <div class="purchase-return-smart-panel">
                <div class="purchase-return-smart-label">Quick period</div>
                <div class="purchase-return-preset-row">
                    <button type="button" class="purchase-return-preset-btn active" data-preset="today">Today</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="week">Last 7 days</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="month">This month</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="custom">Custom</button>
                </div>

                <div class="purchase-return-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search"
                           id="filterSearch"
                           class="form-control purchase-return-search-input"
                           placeholder="Smart search — return #, GRN, supplier…"
                           value="{{ e($filters['search'] ?? '') }}"
                           autocomplete="off">
                </div>

                <div class="purchase-return-smart-label">
                    Status <small class="text-muted fw-normal">(live counts)</small>
                </div>
                <div class="purchase-return-status-chips mb-3">
                    <button type="button" class="purchase-return-status-chip" data-status="all">
                        <span>All</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="purchase-return-status-chip active" data-status="active">
                        <span>Active</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="purchase-return-status-chip" data-status="reversed">
                        <span>Reversed</span><span class="chip-count">0</span>
                    </button>
                </div>
                <input type="hidden" id="filterStatus" value="{{ e($filters['status'] ?? 'active') }}">

                <div class="mt-3 pt-3 border-top">
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0" for="filterDateFrom">From</label>
                            <input type="date" id="filterDateFrom" class="form-control"
                                   value="{{ e($filters['date_from'] ?? $today) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0" for="filterDateTo">To</label>
                            <input type="date" id="filterDateTo" class="form-control"
                                   value="{{ e($filters['date_to'] ?? $today) }}">
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="form-check mt-2 mt-md-4">
                                <input class="form-check-input" type="checkbox" id="filterSmartSort" checked>
                                <label class="form-check-label small" for="filterSmartSort">
                                    Priority sort — active first, then reversed
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="button" id="clearFilters" class="btn btn-outline-secondary w-100">Reset all</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Active filter bar (populated by JS) --}}
    <div class="purchase-return-active-bar" id="activeFilterBar"></div>

    {{-- Results card --}}
    <section class="purchase-return-results-card">
        <div class="purchase-return-results-head">
            <div class="fw-bold"><span id="resultsCountNum">0</span> return(s)</div>
        </div>
        <div class="p-2 p-md-3">
            <div id="returnCards" class="purchase-return-mobile-cards"></div>
            <div class="table-responsive sales-dt-mobile-controls">
                <table class="table table-hover align-middle mb-0" id="returnTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Return</th>
                            <th>GRN</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

{{-- Offcanvas quick-create (uses the shared partial) --}}
<div class="offcanvas offcanvas-end purchase-return-create-offcanvas"
     tabindex="-1"
     id="purchaseReturnCreateOffcanvas"
     aria-labelledby="purchaseReturnCreateOffcanvasLabel">
    <div class="offcanvas-header">
        <div>
            <h5 class="offcanvas-title mb-0" id="purchaseReturnCreateOffcanvasLabel">
                <i class="fas fa-truck-loading me-2"></i>Quick return
            </h5>
            <p class="mb-0 small opacity-90">Search GRN → enter qty &amp; warehouse → save</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-2">
        @include('admin.purchase-returns.partials.create-workspace', [
            'workspaceId' => 'purchaseReturnOffcanvasRoot',
            'compact'     => true,
        ])
    </div>
</div>
@endsection

@push('scripts')
<script>window.CSRF_TOKEN = @json($csrf);</script>
<script>
window.PURCHASE_RETURN_CREATE_BOOT = {!! $createBoot !!};
</script>
<script>
window.PURCHASE_RETURN_BASE = '{{ rtrim(route('admin.purchase-returns.index'), '/') }}/';
window.PURCHASE_RETURN_BOOT = {!! $mainBoot !!};
</script>

{{-- ─────────────── PurchaseReturn.js (workspace JS, inlined) ─────────────── --}}
<script>
/**
 * Purchase return — quick return workspace (create page + index offcanvas).
 * Ported verbatim from legacy `public/assets/js/PurchaseReturn.js`,
 * adapted for Laravel routes + CSRF meta token + Laravel-style JSON.
 */
(function () {
    'use strict';

    let BASE_URL = '';
    let ENDPOINTS = {};

    function getCsrfToken() {
        return window.CSRF_TOKEN || '';
    }

    function postBody(params) {
        const body = new URLSearchParams(params);
        const token = getCsrfToken();
        if (token) body.append('csrf_token', token);
        return body.toString();
    }

    function postOptions(bodyString) {
        return {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: bodyString,
        };
    }

    function formatMoney(n) {
        return 'Tk ' + (parseFloat(n) || 0).toFixed(2);
    }

    function formatQty(n) {
        const v = parseFloat(n) || 0;
        return v.toFixed(2).replace(/\.?0+$/, '') || '0';
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    /** Laravel wraps AJAX list responses in { status, data: [...] }. */
    function parseReceiveSearchPayload(raw) {
        if (Array.isArray(raw)) return { rows: raw, error: null };
        if (!raw || typeof raw !== 'object') return { rows: [], error: null };
        if (raw.status === 'error') return { rows: [], error: raw.message || 'Search failed.' };
        if (Array.isArray(raw.data)) return { rows: raw.data, error: null };
        if (Array.isArray(raw.receives)) return { rows: raw.receives, error: null };
        return { rows: [], error: null };
    }

    class PurchaseReturnWorkspace {
        constructor(rootEl, options) {
            this.root = rootEl;
            this.id = rootEl.id;
            this.onSaved = options.onSaved || null;
            this.searchInput = rootEl.querySelector(`#${this.id}_receiveSearch`);
            this.searchClear = rootEl.querySelector(`#${this.id}_searchClear`);
            this.resultsDiv = rootEl.querySelector(`#${this.id}_searchResults`);
            this.receiveBar = rootEl.querySelector(`#${this.id}_receiveBar`);
            this.detailsDiv = rootEl.querySelector(`#${this.id}_receiveDetails`);
            this.formStep = rootEl.querySelector('[data-step="form"]');
            this.currentReceive = null;
            this.lastResults = [];
            this.focusIndex = -1;
            this.searchTimer = null;
            this.searchSeq = 0;
            this.bindEvents();
        }

        bindEvents() {
            if (!this.searchInput) return;

            this.searchInput.addEventListener('input', () => {
                const term = this.searchInput.value.trim();
                this.searchClear.classList.toggle('d-none', term.length === 0);
                clearTimeout(this.searchTimer);
                if (term.length < 2) {
                    this.clearResults();
                    return;
                }
                this.searchTimer = setTimeout(() => this.runSearch(term), 280);
            });

            this.searchInput.addEventListener('keydown', (e) => this.onSearchKeydown(e));

            this.searchClear.addEventListener('click', () => {
                this.resetWorkspace();
                this.searchInput.focus();
            });

            this.resultsDiv.addEventListener('click', (e) => {
                const card = e.target.closest('[data-receive-index]');
                if (!card) return;
                e.preventDefault();
                const idx = parseInt(card.dataset.receiveIndex, 10);
                if (this.lastResults[idx]) this.selectReceive(this.lastResults[idx]);
            });
        }

        onSearchKeydown(e) {
            const cards = this.resultsDiv.querySelectorAll('.prt-create-result-card');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!cards.length) return;
                this.focusIndex = Math.min(this.focusIndex + 1, cards.length - 1);
                this.updateFocus(cards);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!cards.length) return;
                this.focusIndex = Math.max(this.focusIndex - 1, 0);
                this.updateFocus(cards);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.focusIndex >= 0 && this.lastResults[this.focusIndex]) {
                    this.selectReceive(this.lastResults[this.focusIndex]);
                } else if (this.lastResults.length === 1) {
                    this.selectReceive(this.lastResults[0]);
                } else {
                    const term = this.searchInput.value.trim();
                    if (term.length >= 2) this.runSearch(term);
                }
            } else if (e.key === 'Escape') {
                this.clearResults();
            }
        }

        updateFocus(cards) {
            cards.forEach((c, i) => c.classList.toggle('is-focused', i === this.focusIndex));
            if (cards[this.focusIndex]) cards[this.focusIndex].scrollIntoView({ block: 'nearest' });
        }

        clearResults() {
            this.resultsDiv.innerHTML = '';
            this.lastResults = [];
            this.focusIndex = -1;
            this.searchInput.setAttribute('aria-expanded', 'false');
        }

        setResultsMessage(html, className) {
            this.resultsDiv.innerHTML = `<p class="prt-create-results-msg ${className || ''}">${html}</p>`;
        }

        async runSearch(term) {
            const seq = ++this.searchSeq;
            this.setResultsMessage('<i class="fas fa-spinner fa-spin"></i> Searching…', 'is-loading');
            this.searchInput.setAttribute('aria-expanded', 'true');

            try {
                const url = `${ENDPOINTS.search_receives}?term=${encodeURIComponent(term)}`;
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const raw = await res.json();
                if (seq !== this.searchSeq) return;

                const parsed = parseReceiveSearchPayload(raw);
                if (parsed.error) {
                    this.setResultsMessage(escapeHtml(parsed.error), 'is-warn');
                    return;
                }

                this.lastResults = parsed.rows;
                this.focusIndex = -1;

                if (this.lastResults.length === 0) {
                    this.setResultsMessage(
                        'No received GRN with returnable qty for your branch. Try GRN code or supplier name.',
                        'is-info'
                    );
                    return;
                }

                this.renderResultCards();

                if (this.lastResults.length === 1) {
                    const hint = document.createElement('p');
                    hint.className = 'prt-create-results-msg is-info mt-2 mb-0';
                    hint.innerHTML =
                        '<i class="fas fa-check-circle"></i> One match — press <kbd>Enter</kbd> or tap to load return form.';
                    this.resultsDiv.appendChild(hint);
                    this.focusIndex = 0;
                    const card = this.resultsDiv.querySelector('.prt-create-result-card');
                    if (card) card.classList.add('is-focused');
                }
            } catch (err) {
                console.error(err);
                this.setResultsMessage('Search failed. Check connection and try again.', 'is-warn');
            }
        }

        renderResultCards() {
            let html = '';
            this.lastResults.forEach((rec, i) => {
                html += `
                    <button type="button" class="prt-create-result-card" data-receive-index="${i}" role="option">
                        <div class="prt-create-result-top">
                            <span class="prt-create-result-code">${escapeHtml(rec.receive_code)}</span>
                            <span class="prt-create-result-amt">${formatMoney(rec.total_amount)}</span>
                        </div>
                        <div class="prt-create-result-meta">
                            <i class="fas fa-truck"></i>${escapeHtml(rec.supplier_name || '—')}
                        </div>
                    </button>`;
            });
            this.resultsDiv.innerHTML = html;
        }

        async selectReceive(basicReceive) {
            this.clearResults();
            this.detailsDiv.innerHTML = '<p class="prt-create-results-msg is-loading"><i class="fas fa-spinner fa-spin"></i> Loading returnable lines…</p>';
            this.root.classList.add('is-form-active');
            this.formStep.classList.remove('d-none');

            try {
                // Use GET receive-details?receive_id=... (Laravel route)
                const url = `${ENDPOINTS.receive_details}?receive_id=${encodeURIComponent(basicReceive.id)}`;
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const response = await res.json();

                if (response.status === 'success' || response.receive) {
                    this.currentReceive = response.receive;
                    this.renderReceiveBar(response.receive);
                    this.renderReturnForm(response.receive);
                } else {
                    this.resetWorkspace();
                    Swal.fire('Error', response.message || 'GRN not found', 'error');
                }
            } catch (e) {
                this.resetWorkspace();
                Swal.fire('Error', 'Failed to load GRN details', 'error');
                console.error(e);
            }
        }

        renderReceiveBar(receive) {
            this.receiveBar.innerHTML = `
                <div>
                    <span class="text-muted small">GRN</span><br>
                    <strong>${escapeHtml(receive.receive_code)}</strong>
                </div>
                <div>
                    <span class="text-muted small">Supplier</span><br>
                    <strong>${escapeHtml(receive.supplier_name || '—')}</strong>
                </div>
                <button type="button" class="prt-create-change-invoice" data-action="change-receive">
                    <i class="fas fa-search me-1"></i> Change GRN
                </button>`;
            this.receiveBar.querySelector('[data-action="change-receive"]').addEventListener('click', () => {
                this.resetWorkspace();
                this.searchInput.focus();
            });
        }

        renderReturnForm(receive) {
            const allItems = (receive.all_items || receive.items || []);
            const returnableItems = (receive.items || []).filter(
                (item) => parseFloat(item.returnable_qty || 0) > 0
            );

            if (returnableItems.length === 0) {
                let breakdownRows = '';
                if (allItems.length > 0) {
                    breakdownRows = allItems.map((item) => {
                        const received  = parseFloat(item.received_qty     || 0);
                        const returned  = parseFloat(item.already_returned || 0);
                        const returnable = parseFloat(item.returnable_qty  || 0);
                        return `
                            <tr>
                                <td>${escapeHtml(item.product_code || item.product_name || ('#' + item.product_id))}</td>
                                <td class="text-end">${formatQty(received)}</td>
                                <td class="text-end">${formatQty(returned)}</td>
                                <td class="text-end text-muted">${formatQty(returnable)}</td>
                            </tr>`;
                    }).join('');
                }

                this.detailsDiv.innerHTML = `
                    <div class="prt-create-results-msg is-warn">
                        <p class="mb-2">
                            <strong>Nothing left to return on GRN ${escapeHtml(receive.receive_code || '')}.</strong>
                            ${allItems.length === 0
                                ? 'This GRN has no receivable items.'
                                : 'Every line on this GRN has already been fully returned — returnable qty is 0 for all rows.'}
                        </p>
                        ${breakdownRows ? `
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Received</th>
                                    <th class="text-end">Already returned</th>
                                    <th class="text-end">Returnable</th>
                                </tr>
                            </thead>
                            <tbody>${breakdownRows}</tbody>
                        </table>` : ''}
                        <p class="mt-2 mb-0 small text-muted">
                            Tip: pick a different GRN from the search box above.
                        </p>
                    </div>`;
                return;
            }

            let rows = '';
            returnableItems.forEach((item) => {
                const returnable = parseFloat(item.returnable_qty || 0);
                const itemKey = item.purchase_receive_item_id || item.id;
                let whOptions = '<option value="">— Warehouse —</option>';
                if (item.warehouses && item.warehouses.length) {
                    item.warehouses.forEach((w) => {
                        const physical = parseFloat(w.physical_qty ?? w.qty ?? 0);
                        const avail = parseFloat(w.available_qty || 0);
                        whOptions += `<option value="${w.id}" data-available="${avail}" data-physical="${physical}">`
                            + `${escapeHtml(w.warehouse_name)} — ${formatQty(physical)} in stock, ${formatQty(avail)} avail</option>`;
                    });
                }

                rows += `
                    <tr data-item-key="${itemKey}">
                        <td>${escapeHtml(item.product_name)}</td>
                        <td class="text-center">${formatQty(item.received_qty)}</td>
                        <td class="text-center text-success fw-bold" title="Max back to supplier on this GRN (not warehouse on-hand)">${formatQty(returnable)}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm return-qty text-center"
                                   max="${returnable}" step="0.01" min="0" value="0"
                                   data-returnable="${returnable}"
                                   data-rate="${parseFloat(item.rate || 0)}">
                        </td>
                        <td class="text-end">${formatMoney(item.rate)}</td>
                        <td class="text-end return-amount">Tk 0.00</td>
                        <td>
                            <select class="form-select form-select-sm warehouse-select" required>${whOptions}</select>
                        </td>
                        <td>
                            <select class="form-select form-select-sm condition-select">
                                <option value="Good">Good</option>
                                <option value="Damage">Damage</option>
                            </select>
                            <input type="hidden" class="pri-id" value="${itemKey}">
                            <input type="hidden" class="product-id" value="${item.product_id}">
                            <input type="hidden" class="item-rate" value="${item.rate || 0}">
                        </td>
                    </tr>`;
            });

            this.detailsDiv.innerHTML = `
                <div class="prt-create-form-card">
                    <div class="prt-create-form-card-head">
                        <i class="fas fa-list-ul me-1"></i> Return qty: min(GRN returnable, warehouse_stock available)
                    </div>
                    <form id="${this.id}_returnForm" class="p-2 p-md-3">
                        <input type="hidden" name="purchase_receive_id" value="${receive.id}">
                        <input type="hidden" name="supplier_id" value="${receive.supplier_id}">
                        <input type="hidden" name="return_date" value="${new Date().toISOString().split('T')[0]}">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0 prt-create-lines-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Received</th>
                                        <th class="text-center">GRN returnable</th>
                                        <th class="text-center">Return qty</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end">Amount</th>
                                        <th>Warehouse</th>
                                        <th>Condition</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                        <div class="prt-create-total-strip">
                            <div class="flex-grow-1">
                                <label class="form-label small mb-1">Reason for return</label>
                                <textarea name="reason" class="form-control" rows="2"
                                    placeholder="Optional — e.g. damaged goods, wrong item received"></textarea>
                            </div>
                            <div class="text-end">
                                <p class="prt-create-total-label mb-0">Return total</p>
                                <div class="prt-create-total-value" id="${this.id}_totalReturn">Tk 0.00</div>
                                <input type="hidden" name="total_amount" id="${this.id}_total_amount" value="0">
                            </div>
                        </div>
                        <p class="small text-muted mb-2">
                            <strong>Good:</strong> return qty ≤ <em>GRN returnable</em> (supplier limit) and ≤ <em>warehouse avail</em> (warehouse_stock).
                            <strong>Damage:</strong> no stock OUT; GRN returnable still applies.
                        </p>
                        <div class="prt-create-form-actions">
                            <button type="button" class="btn btn-outline-secondary" data-action="cancel-form">Cancel</button>
                            <button type="submit" class="btn btn-success px-4">
                                <i class="fas fa-save me-1"></i> Save return
                            </button>
                        </div>
                    </form>
                </div>`;

            const form = this.detailsDiv.querySelector('form');
            form.querySelectorAll('.return-qty').forEach((input) => {
                input.addEventListener('input', () => this.calculateRow(input));
                input.addEventListener('change', () => this.calculateRow(input));
                input.addEventListener('blur', () => {
                    const row = input.closest('tr');
                    const wh = row?.querySelector('.warehouse-select');
                    if (wh?.value) this.validateStock(wh);
                });
            });
            form.querySelectorAll('.warehouse-select').forEach((sel) => {
                sel.addEventListener('change', () => {
                    this.applyRowQtyCap(sel.closest('tr'));
                    this.validateStock(sel);
                });
            });
            form.querySelector('[data-action="cancel-form"]').addEventListener('click', () => this.resetWorkspace());
            form.addEventListener('submit', (e) => this.submitReturn(e));
        }

        applyRowQtyCap(row) {
            if (!row) return;
            const returnQtyInput = row.querySelector('.return-qty');
            const select = row.querySelector('.warehouse-select');
            if (!returnQtyInput) return;

            const returnable = parseFloat(returnQtyInput.dataset.returnable || 0);
            let max = returnable;
            if (select?.value) {
                const avail = parseFloat(select.options[select.selectedIndex]?.dataset.available || 0);
                max = Math.min(returnable, avail);
            }
            returnQtyInput.max = String(max);
            const current = parseFloat(returnQtyInput.value) || 0;
            if (current > max) {
                returnQtyInput.value = max;
                this.calculateRow(returnQtyInput);
            }
        }

        validateStock(select) {
            const row = select.closest('tr');
            this.applyRowQtyCap(row);
            const available = parseFloat(select.options[select.selectedIndex]?.dataset.available || 0);
            const returnQtyInput = row?.querySelector('.return-qty');
            if (!returnQtyInput) return;
            const currentReturn = parseFloat(returnQtyInput.value) || 0;
            if (currentReturn > available + 0.0001) {
                Swal.fire(
                    'Stock limit',
                    `Only ${formatQty(available)} available in this warehouse (from warehouse_stock).`,
                    'warning'
                );
                returnQtyInput.value = Math.min(available, parseFloat(returnQtyInput.dataset.returnable || available));
                this.calculateRow(returnQtyInput);
            }
        }

        calculateRow(input) {
            const row = input.closest('tr');
            const qty = parseFloat(input.value) || 0;
            const rate = parseFloat(input.dataset.rate) || 0;
            const amountCell = row.querySelector('.return-amount');
            if (amountCell) amountCell.textContent = formatMoney(qty * rate);
            const wh = row.querySelector('.warehouse-select');
            if (wh && wh.value) this.validateStock(wh);
            this.calculateTotal();
        }

        calculateTotal() {
            let total = 0;
            this.detailsDiv.querySelectorAll('.return-amount').forEach((cell) => {
                const n = parseFloat(String(cell.textContent).replace(/[^\d.-]/g, '')) || 0;
                total += n;
            });
            const display = this.root.querySelector(`#${this.id}_totalReturn`);
            const hidden = this.root.querySelector(`#${this.id}_total_amount`);
            if (display) display.textContent = formatMoney(total);
            if (hidden) hidden.value = total.toFixed(2);
        }

        resetWorkspace() {
            this.currentReceive = null;
            this.root.classList.remove('is-form-active');
            this.formStep.classList.add('d-none');
            this.receiveBar.innerHTML = '';
            this.detailsDiv.innerHTML = '';
            this.clearResults();
            this.searchInput.value = '';
            this.searchClear.classList.add('d-none');
        }

        async submitReturn(e) {
            e.preventDefault();
            const form = e.target;
            const items = [];
            let totalAmount = 0;

            form.querySelectorAll('tbody tr').forEach((row) => {
                const returnQtyInput = row.querySelector('.return-qty');
                if (!returnQtyInput) return;
                const returnQty = parseFloat(returnQtyInput.value) || 0;
                if (returnQty > 0) {
                    const warehouseSelect = row.querySelector('.warehouse-select');
                    const conditionSelect = row.querySelector('.condition-select');
                    const rate = parseFloat(row.querySelector('.item-rate')?.value) || 0;
                    items.push({
                        purchase_receive_item_id: row.querySelector('.pri-id')?.value,
                        product_id: row.querySelector('.product-id')?.value,
                        warehouse_id: warehouseSelect ? warehouseSelect.value : '',
                        qty: returnQty,
                        return_qty: returnQty,
                        rate,
                        condition: conditionSelect ? conditionSelect.value : 'Good',
                    });
                    totalAmount += returnQty * rate;
                }
            });

            if (items.length === 0) {
                Swal.fire('Warning', 'Enter at least one return quantity', 'warning');
                return;
            }

            for (const item of items) {
                if (!item.warehouse_id) {
                    Swal.fire('Error', 'Select a warehouse for each returned line', 'error');
                    return;
                }
                const row = form.querySelector(`tr[data-item-key="${item.purchase_receive_item_id}"]`)
                    || Array.from(form.querySelectorAll('tbody tr')).find((tr) =>
                        tr.querySelector('.pri-id')?.value === String(item.purchase_receive_item_id)
                    );
                const returnable = parseFloat(row?.querySelector('.return-qty')?.dataset.returnable || 0);
                if (parseFloat(item.return_qty) > returnable + 0.0001) {
                    Swal.fire(
                        'GRN limit',
                        `Cannot return ${formatQty(item.return_qty)} — only ${formatQty(returnable)} returnable to supplier on this GRN line.`,
                        'error'
                    );
                    return;
                }
                if (String(item.condition).toLowerCase() === 'good') {
                    const whSel = row?.querySelector('.warehouse-select');
                    const avail = parseFloat(whSel?.options[whSel.selectedIndex]?.dataset.available || 0);
                    if (parseFloat(item.return_qty) > avail + 0.0001) {
                        Swal.fire(
                            'Warehouse stock',
                            `Cannot return ${formatQty(item.return_qty)} — only ${formatQty(avail)} available (warehouse_stock).`,
                            'error'
                        );
                        return;
                    }
                }
            }

            const formData = new FormData(form);
            // BUG-50 fix: previously this line was:
            //     formData.set('items', JSON.stringify(items));
            // That sent `items` as a JSON-encoded STRING, which caused
            // Laravel's 'items' => 'required|array' validation rule in
            // StorePurchaseReturnRequest to fail with
            // "The items field must be an array." — because a JSON string
            // is a string, not an array, from PHP's perspective.
            //
            // Fix: append each item as items[index][key] using Laravel's
            // standard form-encoded array notation. PHP's request parser
            // then reconstructs these into a proper nested array, satisfying
            // the 'array' validation rule. Same pattern as purchase-orders/
            // create.blade.php and purchase-receives/create.blade.php.
            items.forEach((item, idx) => {
                formData.append(`items[${idx}][purchase_receive_item_id]`, item.purchase_receive_item_id ?? '');
                formData.append(`items[${idx}][product_id]`,                item.product_id ?? '');
                formData.append(`items[${idx}][warehouse_id]`,              item.warehouse_id ?? '');
                formData.append(`items[${idx}][qty]`,                       item.qty ?? 0);
                formData.append(`items[${idx}][return_qty]`,                item.return_qty ?? 0);
                formData.append(`items[${idx}][rate]`,                      item.rate ?? 0);
                formData.append(`items[${idx}][condition]`,                 item.condition ?? 'Good');
            });
            formData.set('total_amount', totalAmount.toFixed(2));

            Swal.fire({
                title: 'Saving…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            try {
                // Laravel store() expects form-encoded POST with items as
                // indexed array fields: items[0][product_id], items[0][qty], etc.
                // (see BUG-50 fix above). FormData handles the encoding; the
                // X-CSRF-TOKEN header is the Laravel way for AJAX POSTs.
                const postHeaders = {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                };
                const res = await fetch(ENDPOINTS.store, {
                    method: 'POST',
                    headers: postHeaders,
                    body: formData,
                });

                // Laravel store() returns a redirect (302) on success — follow it.
                // On validation error returns 422 JSON or 200 with session error.
                const text = await res.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (err) {
                    // Not JSON — probably a redirect HTML. Treat as success if redirect target contains /purchase-returns/.
                    if (res.redirected && /purchase-returns\//.test(res.url)) {
                        result = { status: 'success', redirect: res.url, message: 'Return created.' };
                    } else {
                        result = { status: res.ok ? 'success' : 'error', message: text.slice(0, 200) };
                    }
                }

                if (result.status === 'success' || res.redirected) {
                    const slipUrl = '';
                    document.dispatchEvent(new CustomEvent('purchaseReturn:created', { detail: result }));

                    Swal.fire({
                        title: 'Return saved',
                        text: result.message || 'Purchase return created.',
                        icon: 'success',
                        confirmButtonText: 'View return',
                        showCancelButton: !!slipUrl,
                        cancelButtonText: 'Done',
                    }).then((swalResult) => {
                        if (typeof this.onSaved === 'function') {
                            this.onSaved(swalResult, slipUrl, result);
                        } else if (swalResult.isConfirmed && result.redirect) {
                            window.location.href = result.redirect;
                        } else if (!swalResult.isConfirmed) {
                            // Stay on index — table will reload via purchaseReturn:created event.
                            this.resetWorkspace();
                        } else {
                            this.resetWorkspace();
                        }
                    });
                } else {
                    Swal.fire('Error', result.message || 'Failed to save return', 'error');
                }
            } catch (err) {
                console.error(err);
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            }
        }

        prefill(term) {
            if (!term) return;
            this.searchInput.value = term;
            this.searchClear.classList.remove('d-none');
            this.runSearch(term);
        }
    }

    function resolveBaseUrl() {
        BASE_URL = window.PURCHASE_RETURN_BASE || '/admin/purchase-returns/';
        if (BASE_URL && !BASE_URL.endsWith('/')) BASE_URL += '/';
        ENDPOINTS = (window.PURCHASE_RETURN_BOOT && window.PURCHASE_RETURN_BOOT.endpoints) || {};
        // Fallbacks if endpoints not provided.
        if (!ENDPOINTS.search_receives) ENDPOINTS.search_receives = BASE_URL + 'search-receives';
        if (!ENDPOINTS.receive_details) ENDPOINTS.receive_details = BASE_URL + 'receive-details';
        if (!ENDPOINTS.store) ENDPOINTS.store = BASE_URL;
    }

    function initWorkspaces() {
        document.querySelectorAll('[data-prt-workspace]').forEach((root) => {
            const offcanvas = root.closest('.offcanvas');
            const ws = new PurchaseReturnWorkspace(root, {
                onSaved(swalResult, slipUrl, result) {
                    if (offcanvas) {
                        const oc = bootstrap.Offcanvas.getInstance(offcanvas);
                        ws.resetWorkspace();
                        if (oc) oc.hide();
                        // If user clicked "View return", navigate to the show page.
                        if (swalResult.isConfirmed && result && result.redirect) {
                            window.location.href = result.redirect;
                        }
                        return;
                    }
                    if (swalResult.isConfirmed && result && result.redirect) {
                        window.location.href = result.redirect;
                    } else if (!swalResult.isConfirmed) {
                        ws.resetWorkspace();
                        ws.searchInput.focus();
                    } else {
                        ws.resetWorkspace();
                    }
                },
            });
            root._prtWorkspace = ws;
        });

        const boot = window.PURCHASE_RETURN_CREATE_BOOT || {};
        const main = document.getElementById(boot.workspace_id || 'purchaseReturnCreateRoot');
        if (main && main._prtWorkspace && boot.prefill) {
            main._prtWorkspace.prefill(boot.prefill);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        resolveBaseUrl();
        initWorkspaces();

        const offcanvasEl = document.getElementById('purchaseReturnCreateOffcanvas');
        if (offcanvasEl) {
            offcanvasEl.addEventListener('shown.bs.offcanvas', function () {
                const root = document.getElementById('purchaseReturnOffcanvasRoot');
                if (root && root._prtWorkspace) {
                    root._prtWorkspace.resetWorkspace();
                    root._prtWorkspace.searchInput.focus();
                }
            });

            const params = new URLSearchParams(window.location.search);
            if (params.get('return') === '1' || params.get('new') === '1') {
                const btn = document.getElementById('openPurchaseReturnCreate');
                if (btn && typeof bootstrap !== 'undefined') {
                    bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl).show();
                }
            }
        }
    });

    window.PurchaseReturnWorkspace = PurchaseReturnWorkspace;
})();
</script>

{{-- ─────────────── purchase-return-index.js (index page JS, inlined) ─────────────── --}}
<script>
/**
 * Purchase return index — smart filters, offcanvas create, mobile cards.
 * Ported from legacy `public/assets/js/purchase-return-index.js`.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'purchase_return_filters_v1';
    let returnTable = null;
    let summaryDebounce = null;

    const STATUS_LABELS = {
        all: 'All returns',
        active: 'Active',
        reversed: 'Reversed',
    };

    $(function () {
        if (!document.getElementById('purchase-return-app')) return;

        window.CSRF_TOKEN = window.CSRF_TOKEN || (window.PURCHASE_RETURN_BOOT && window.PURCHASE_RETURN_BOOT.csrf) || '';

        initFromBootOrStorage();
        bindFilterUi();
        initFiltersCollapse();
        initDataTable();
        refreshSummary();
        updateActiveFilterBar();

        document.addEventListener('purchaseReturn:created', function () {
            if (returnTable) returnTable.ajax.reload(null, false);
            refreshSummary();
        });
    });

    function initFromBootOrStorage() {
        const boot = window.PURCHASE_RETURN_BOOT || {};
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { saved = null; }

        const state = saved && !boot.forceUrlParams ? saved : boot;

        if (state.date_preset) setActivePreset(state.date_preset, false);
        else applyDatePreset('today', false);

        if (state.date_from) $('#filterDateFrom').val(state.date_from);
        if (state.date_to) $('#filterDateTo').val(state.date_to);
        if (state.status) $('#filterStatus').val(state.status);
        if (state.search) $('#filterSearch').val(state.search);
        $('#filterSmartSort').prop('checked', state.smart_sort !== false && state.smart_sort !== '0');
        syncStatusChips();
    }

    function bindFilterUi() {
        $('.purchase-return-preset-btn').on('click', function () {
            applyDatePreset($(this).data('preset'));
        });

        $('.purchase-return-status-chip').on('click', function () {
            $('#filterStatus').val($(this).data('status'));
            syncStatusChips();
            persistAndReload();
        });

        $('#filterDateFrom, #filterDateTo').on('change', () => {
            $('.purchase-return-preset-btn').removeClass('active');
            $('.purchase-return-preset-btn[data-preset="custom"]').addClass('active');
            persistAndReload();
        });

        $('#filterSearch').on('input', debounce(() => {
            if (returnTable) returnTable.search($('#filterSearch').val()).draw();
            scheduleSummary();
            updateActiveFilterBar();
            saveFilters();
        }, 320));

        $('#clearFilters').on('click', resetFilters);
        $('#filterSmartSort').on('change', persistAndReload);
    }

    function initFiltersCollapse() {
        const el = document.getElementById('purchaseReturnFiltersCollapse');
        if (!el) return;
        el.addEventListener('shown.bs.collapse', syncFiltersToggleBtn);
        el.addEventListener('hidden.bs.collapse', syncFiltersToggleBtn);
        syncFiltersToggleBtn();
    }

    function syncFiltersToggleBtn() {
        const open = document.getElementById('purchaseReturnFiltersCollapse')?.classList.contains('show');
        $('#togglePurchaseReturnFilters')
            .toggleClass('collapsed', !open)
            .attr('aria-expanded', open ? 'true' : 'false');
    }

    function setActivePreset(preset, reload) {
        $('.purchase-return-preset-btn').removeClass('active');
        $(`.purchase-return-preset-btn[data-preset="${preset}"]`).addClass('active');
        if (reload) applyDatePreset(preset);
    }

    function applyDatePreset(preset, reload) {
        const range = dateRangeForPreset(preset);
        setActivePreset(preset, false);
        $('#filterDateFrom').val(range.from);
        $('#filterDateTo').val(range.to);
        if (reload !== false) persistAndReload();
    }

    function dateRangeForPreset(preset) {
        const today = new Date();
        const fmt = (d) => d.toISOString().slice(0, 10);
        switch (preset) {
            case 'yesterday': {
                const y = new Date(today);
                y.setDate(y.getDate() - 1);
                const s = fmt(y);
                return { from: s, to: s };
            }
            case 'week': {
                const w = new Date(today);
                w.setDate(w.getDate() - 6);
                return { from: fmt(w), to: fmt(today) };
            }
            case 'month': {
                const m = new Date(today.getFullYear(), today.getMonth(), 1);
                return { from: fmt(m), to: fmt(today) };
            }
            default:
                return { from: fmt(today), to: fmt(today) };
        }
    }

    function syncStatusChips() {
        const status = $('#filterStatus').val();
        $('.purchase-return-status-chip').removeClass('active');
        $(`.purchase-return-status-chip[data-status="${status}"]`).addClass('active');
    }

    function getFilterParams() {
        return {
            date_from: $('#filterDateFrom').val(),
            date_to: $('#filterDateTo').val(),
            status: $('#filterStatus').val(),
            search: $('#filterSearch').val().trim(),
            smart_sort: $('#filterSmartSort').is(':checked') ? '1' : '0',
        };
    }

    function saveFilters() {
        const preset = $('.purchase-return-preset-btn.active').data('preset') || 'custom';
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                ...getFilterParams(),
                date_preset: preset,
            }));
        } catch (e) { /* quota */ }
    }

    function persistAndReload(forceSummary) {
        saveFilters();
        if (returnTable) returnTable.ajax.reload();
        if (forceSummary) refreshSummary();
        else scheduleSummary();
        updateActiveFilterBar();
    }

    function resetFilters() {
        $('#filterStatus').val('active');
        $('#filterSearch').val('');
        $('#filterSmartSort').prop('checked', true);
        applyDatePreset('today');
        syncStatusChips();
        if (returnTable) returnTable.search('').draw();
        persistAndReload(true);
    }

    function scheduleSummary() {
        clearTimeout(summaryDebounce);
        summaryDebounce = setTimeout(refreshSummary, 280);
    }

    function refreshSummary() {
        const p = getFilterParams();
        const qs = new URLSearchParams({
            date_from: p.date_from,
            date_to: p.date_to,
            search: p.search,
        });
        const url = (window.PURCHASE_RETURN_BOOT?.endpoints?.summary || (window.PURCHASE_RETURN_BASE + 'summary'))
            + '?' + qs.toString();
        fetch(url).then((r) => r.json()).then(updateChipCounts).catch(() => {});
    }

    function updateChipCounts(data) {
        const map = {
            all: data.all ?? data.total ?? 0,
            active: data.active ?? 0,
            reversed: data.reversed ?? 0,
        };
        $('.purchase-return-status-chip').each(function () {
            $(this).find('.chip-count').text(map[$(this).data('status')] ?? 0);
        });
    }

    function updateActiveFilterBar() {
        const bar = document.getElementById('activeFilterBar');
        if (!bar) return;

        const p = getFilterParams();
        const presetLabel = $('.purchase-return-preset-btn.active').text().trim() || 'Custom';
        const tags = [
            `<span class="filter-tag"><i class="fas fa-calendar"></i> ${escapeHtml(presetLabel)} (${p.date_from} → ${p.date_to})</span>`,
            `<span class="filter-tag"><i class="fas fa-filter"></i> ${escapeHtml(STATUS_LABELS[p.status] || p.status)}</span>`,
        ];
        if (p.search) {
            tags.push(`<span class="filter-tag"><i class="fas fa-search"></i> "${escapeHtml(p.search)}"</span>`);
        }
        if (p.smart_sort === '1') {
            tags.push('<span class="filter-tag"><i class="fas fa-sort-amount-down"></i> Priority sort</span>');
        }

        bar.innerHTML = tags.join('')
            + '<button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="clearFiltersInline">Clear all</button>';
        $('#clearFiltersInline').on('click', resetFilters);
    }

    function initDataTable() {
        const ajaxUrl = window.PURCHASE_RETURN_BOOT?.endpoints?.datatables || window.PURCHASE_RETURN_BASE;
        returnTable = $('#returnTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [],
            language: {
                emptyTable: 'No returns match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: ajaxUrl,
                data: (d) => {
                    const p = getFilterParams();
                    d.datatables = 1;
                    d.date_from = p.date_from;
                    d.date_to = p.date_to;
                    d.filterStatus = p.status;
                    d.smart_sort = p.smart_sort;
                },
            },
            columns: [
                {
                    data: 'return_code',
                    render: (d, t, row) =>
                        `<a href="${row.show_url}" class="fw-bold text-decoration-none text-danger">${escapeHtml(d)}</a>`,
                },
                { data: 'receive_code', defaultContent: '—', render: (d) => escapeHtml(d || '—') },
                {
                    data: 'supplier_name',
                    render: (d, t, row) =>
                        `<div class="fw-semibold">${escapeHtml(d || '—')}</div>`
                        + `<small class="text-muted">${escapeHtml(row.branch_name || '')}</small>`,
                },
                { data: 'return_date', render: formatDate },
                { data: 'total_amount', className: 'text-end', render: (d) => formatMoney(d) },
                { data: 'is_reversed', render: (d) => returnStatusBadge(parseInt(d || 0, 10)) },
                { data: null, orderable: false, className: 'text-center', render: (d, t, row) => returnActions(row) },
            ],
            drawCallback: function () {
                renderReturnCards(this.api());
                $('#resultsCountNum').text(this.api().page.info().recordsDisplay);
            },
        });

        const initialSearch = $('#filterSearch').val();
        if (initialSearch) returnTable.search(initialSearch).draw();
    }

    function returnStatusBadge(isReversed) {
        return isReversed
            ? '<span class="badge rounded-pill bg-danger">Reversed</span>'
            : '<span class="badge rounded-pill bg-success">Active</span>';
    }

    function returnActions(row) {
        let html = '<div class="btn-group btn-group-sm flex-wrap">';
        html += `<a href="${row.show_url}" class="btn btn-outline-info" title="View"><i class="fas fa-eye"></i></a>`;
        if (row.can_cancel) {
            html += `<button type="button" class="btn btn-outline-danger btn-reverse-pret" data-id="${row.id}" data-code="${escapeHtml(row.return_code || '')}" data-cancel-url="${escapeHtml(row.cancel_url || '')}" title="Reverse"><i class="fas fa-undo"></i></button>`;
        }
        return html + '</div>';
    }

    $(document).on('click', '.btn-reverse-pret', function () {
        const id = $(this).data('id');
        const code = $(this).data('code') || ('PR-' + id);
        const cancelUrl = $(this).data('cancel-url') || '';
        Swal.fire({
            title: 'Reverse purchase return?',
            html: `Reverse <strong>${escapeHtml(code)}</strong>? Stock and GRN returnable qty will be restored.`,
            input: 'textarea',
            inputPlaceholder: 'Reason (min 5 characters)',
            showCancelButton: true,
            confirmButtonText: 'Reverse return',
            confirmButtonColor: '#dc2626',
            returnFocus: false,
            preConfirm: (v) => {
                const r = String(v || '').trim();
                if (r.length < 5) {
                    Swal.showValidationMessage('Please provide a meaningful reason (min 5 chars).');
                    return false;
                }
                return r;
            },
        }).then((result) => {
            if (!result.isConfirmed || !result.value || !cancelUrl) return;
            $.ajax({
                url: cancelUrl,
                method: 'POST',
                data: { cancel_reason: result.value, _token: window.CSRF_TOKEN || '' },
                dataType: 'json',
            }).done((resp) => {
                // Laravel cancel() returns a redirect on success — JSON on error.
                Swal.fire({ icon: 'success', title: 'Reversed', text: 'Purchase return reversed.', timer: 1800, showConfirmButton: false });
                persistAndReload(true);
            }).fail(() => Swal.fire('Error', 'Server error', 'error'));
        });
    });

    function renderReturnCards(table) {
        const container = document.getElementById('returnCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        let html = '';
        table.rows({ page: 'current' }).data().each((row) => {
            const statusClass = parseInt(row.is_reversed || 0, 10) ? 'status-reversed' : 'status-completed';
            html += `<div class="purchase-return-mobile-card ${statusClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <strong>${escapeHtml(row.return_code)}</strong>
                    ${returnStatusBadge(parseInt(row.is_reversed || 0, 10))}
                </div>
                <div class="small text-muted mt-1">${escapeHtml(row.receive_code || '—')} · ${escapeHtml(row.supplier_name || '')}</div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <span class="small text-muted">${formatDate(row.return_date)}</span>
                    <strong>${formatMoney(row.total_amount)}</strong>
                </div>
                <div class="mt-2">${returnActions(row)}</div>
            </div>`;
        });

        container.innerHTML = html || `
            <div class="text-center text-muted py-4">
                <i class="fas fa-undo-alt fa-2x mb-2 opacity-50"></i>
                <p class="mb-0">No returns for these filters</p>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="document.getElementById('clearFilters').click()">Reset filters</button>
            </div>`;
    }

    $(window).on('resize', () => {
        if (returnTable) renderReturnCards(returnTable);
    });

    function formatDate(d) {
        if (!d) return '—';
        const p = String(d).split('-');
        return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : d;
    }

    function formatMoney(n) {
        return 'Tk ' + (parseFloat(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    function debounce(fn, ms) {
        let t;
        return function () {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, arguments), ms);
        };
    }
})();
</script>
@endpush
