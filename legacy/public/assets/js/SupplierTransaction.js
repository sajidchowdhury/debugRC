/**
 * Supplier payments — index (server-side DataTable + mobile cards), create, reverse.
 */
(function () {
    'use strict';

    const TYPE_HINTS = {
        payment: 'Pay supplier — reduces payable (Dr AP, Cr cash/bank). Updates supplier_ledger and bank book.',
        advance: 'Advance to supplier — same GL flow as payment (Dr AP, Cr cash/bank).',
        receive: 'Receive from supplier (credit/refund) — increases payable (Dr cash/bank, Cr AP).',
    };

    const TYPE_LABELS = {
        payment: 'Payment',
        advance: 'Advance',
        receive: 'Receive',
    };

    let suppTable = null;
    const ST_RECENTS_KEY = 'supp_txn_supplier_recents';

    function debounce(fn, ms) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function shortSupplierLabel(s) {
        if (!s) return 'Supplier';
        const name = (s.supplier_name || 'Supplier').trim();
        return name.length > 28 ? name.slice(0, 28) + '…' : name;
    }

    function stBaseUrl() {
        if (window.ST_BOOT?.baseUrl) {
            const u = window.ST_BOOT.baseUrl;
            return u.endsWith('/') ? u : u + '/';
        }
        const el = document.getElementById('base_url');
        const u = el ? el.value : '/';
        return u.endsWith('/') ? u : u + '/';
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatMoney(n) {
        return 'Tk ' + parseFloat(n || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        if (!text) {
            return { status: 'error', message: 'Empty server response (HTTP ' + response.status + ')' };
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON response', text.slice(0, 500));
            return {
                status: 'error',
                message: 'Server returned an invalid response (HTTP ' + response.status + '). Refresh and try again.',
            };
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const base = stBaseUrl();
        window.ST_BASE = base;

        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-supp-reverse');
            if (!btn) return;
            reverseTransaction(btn.dataset.paymentId, btn.dataset.paymentCode);
        });

        if (document.getElementById('suppTxnTable')) {
            initIndexPage(base);
        }

        if (document.getElementById('supplierTransactionForm')) {
            initCreateForm(base);
        }

        $(window).on('resize', () => {
            if (suppTable) renderMobileCards(suppTable);
        });
    });

    function suppTxnTypeLabel(type) {
        return TYPE_LABELS[type] || (type ? type.charAt(0).toUpperCase() + type.slice(1) : '');
    }

    function suppTxnTypeClass(type) {
        const t = String(type || '').replace(/[^a-z_]/gi, '').toLowerCase();
        return ['payment', 'advance', 'receive'].includes(t) ? t : 'payment';
    }

    function formatPaymentDate(value) {
        if (!value) return '—';
        const parts = String(value).split('-');
        if (parts.length !== 3) return value;
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function getSuppTxnFilterParams() {
        const form = document.getElementById('suppTxnFilterForm');
        if (!form) return {};
        return {
            date_from: form.querySelector('[name="date_from"]')?.value || '',
            date_to: form.querySelector('[name="date_to"]')?.value || '',
            transaction_type: form.querySelector('[name="transaction_type"]')?.value || 'all',
            status: form.querySelector('[name="status"]')?.value || 'all',
            payment_mode: form.querySelector('[name="payment_mode"]')?.value || 'all',
            supplier_id: form.querySelector('[name="supplier_id"]')?.value || '',
        };
    }

    function syncSuppTxnFilterUrl() {
        const form = document.getElementById('suppTxnFilterForm');
        if (!form || !window.history?.replaceState) return;
        const params = new URLSearchParams(getSuppTxnFilterParams());
        Object.keys(Object.fromEntries(params)).forEach((key) => {
            const val = params.get(key);
            if (!val || val === 'all') params.delete(key);
        });
        const qs = params.toString();
        const base = (window.ST_BASE || stBaseUrl()) + 'SupplierTransaction';
        window.history.replaceState(null, '', qs ? base + '?' + qs : base);
    }

    function initFilterSupplierSearch(base) {
        const searchInput = document.getElementById('filter_supplier_search');
        const hiddenInput = document.getElementById('filter_supplier_id');
        const suggestBox = document.getElementById('filterSupplierSuggestions');
        const clearBtn = document.getElementById('suppTxnClearSupplierBtn');
        if (!searchInput || !hiddenInput || !suggestBox) return;

        const cache = {};
        const pre = window.ST_BOOT?.filterSupplier;
        if (pre?.id) cache[pre.id] = pre;

        searchInput.addEventListener('input', debounce(async function () {
            const term = this.value.trim();
            hiddenInput.value = '';
            if (term.length < 1) {
                suggestBox.classList.remove('show');
                suggestBox.innerHTML = '';
                return;
            }
            try {
                const res = await fetch(base + 'SupplierTransaction/search_supplier?term=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                });
                const rows = await parseJsonResponse(res);
                const list = Array.isArray(rows) ? rows : (rows.data || []);
                let html = '';
                list.forEach((s) => {
                    cache[s.id] = s;
                    html += `<button type="button" class="supp-txn-suggest-item" data-id="${escapeHtml(s.id)}">
                        <span class="suggest-title">${escapeHtml(s.supplier_name || '')}</span>
                        <span class="suggest-meta">${escapeHtml(s.supplier_code || '')}${s.mobile ? ' · ' + escapeHtml(s.mobile) : ''}</span>
                    </button>`;
                });
                suggestBox.innerHTML = html || '<div class="supp-txn-suggest-empty">No supplier found</div>';
                suggestBox.classList.add('show');
            } catch (e) {
                console.error('Filter supplier search failed', e);
            }
        }, 250));

        suggestBox.addEventListener('click', (e) => {
            const btn = e.target.closest('.supp-txn-suggest-item');
            if (!btn) return;
            const id = btn.dataset.id;
            const s = cache[id];
            hiddenInput.value = id;
            searchInput.value = s?.supplier_name || btn.querySelector('.suggest-title')?.textContent || '';
            suggestBox.classList.remove('show');
            suggestBox.innerHTML = '';
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.supp-txn-filter-supplier')) {
                suggestBox.classList.remove('show');
            }
        });

        clearBtn?.addEventListener('click', () => {
            hiddenInput.value = '';
            searchInput.value = '';
            suggestBox.classList.remove('show');
            suggestBox.innerHTML = '';
            suppTable?.ajax.reload();
            syncSuppTxnFilterUrl();
        });
    }

    function initIndexPage(base) {
        window.ST_BASE = base;
        const showReversed = !!window.showReversed;
        initFilterSupplierSearch(base);

        const form = document.getElementById('suppTxnFilterForm');
        if (showReversed) {
            const statusField = form?.querySelector('[name="status"]');
            if (statusField) {
                statusField.value = 'reversed';
                statusField.disabled = true;
            }
        }

        form?.addEventListener('submit', (e) => {
            e.preventDefault();
            suppTable?.ajax.reload();
            syncSuppTxnFilterUrl();
        });

        document.getElementById('suppTxnTodayBtn')?.addEventListener('click', () => {
            const today = new Date().toISOString().slice(0, 10);
            form.querySelector('[name="date_from"]').value = today;
            form.querySelector('[name="date_to"]').value = today;
            suppTable?.ajax.reload();
            syncSuppTxnFilterUrl();
        });

        initIndexTable(base, showReversed);
    }

    function initIndexTable(base, showReversed) {
        if (typeof $ === 'undefined' || !$.fn.DataTable) {
            return;
        }

        const $table = $('#suppTxnTable');
        if ($.fn.DataTable.isDataTable($table)) {
            $table.DataTable().destroy();
        }

        suppTable = $table.DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: base + 'SupplierTransaction' + (showReversed ? '?reversed=1' : ''),
                data(d) {
                    Object.assign(d, getSuppTxnFilterParams());
                    if (showReversed) {
                        d.reversedMode = 'only_reversed';
                    }
                },
            },
            pageLength: 25,
            order: [[0, 'desc']],
            dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
            buttons: ['copy', 'excel', 'pdf'],
            language: {
                processing: '<i class="fas fa-circle-notch fa-spin me-1"></i> Loading payments…',
                emptyTable: 'No payments match these filters',
                zeroRecords: 'No matching payments',
                search: 'Quick search:',
            },
            columnDefs: [
                { orderable: false, targets: -1 },
                { className: 'd-none d-lg-table-cell', targets: 6 },
            ],
            columns: [
                {
                    data: 'payment_date',
                    render(data, type) {
                        if (type === 'sort' || type === 'type') return data || '';
                        return `<small class="text-nowrap">${escapeHtml(formatPaymentDate(data))}</small>`;
                    },
                },
                {
                    data: 'payment_code',
                    render(data) {
                        return `<span class="branch-code-pill">${escapeHtml(data || '')}</span>`;
                    },
                },
                {
                    data: 'supplier_name',
                    render(data, type, row) {
                        const name = data || '—';
                        const initial = name.charAt(0).toUpperCase();
                        const mobile = row.mobile
                            ? `<div class="branch-contact"><i class="fas fa-phone"></i> ${escapeHtml(row.mobile)}</div>`
                            : '';
                        const hub = row.supplier_id
                            ? `<div class="name"><a href="${base}supplier/show/${row.supplier_id}" class="text-decoration-none text-reset">${escapeHtml(name)}</a></div>`
                            : `<div class="name">${escapeHtml(name)}</div>`;
                        return `<div class="branch-name-cell">
                            <div class="branch-avatar">${escapeHtml(initial)}</div>
                            <div>${hub}${mobile}</div>
                        </div>`;
                    },
                },
                {
                    data: 'transaction_type',
                    render(data) {
                        const cls = suppTxnTypeClass(data);
                        return `<span class="supp-txn-type-pill ${cls}">${escapeHtml(suppTxnTypeLabel(data))}</span>`;
                    },
                },
                {
                    data: 'amount',
                    className: 'text-end',
                    render(data, type, row) {
                        const cls = suppTxnTypeClass(row.transaction_type);
                        return `<span class="supp-txn-amount ${cls}">${formatMoney(data)}</span>`;
                    },
                },
                {
                    data: 'payment_mode',
                    render(data) {
                        return escapeHtml(String(data || '').toUpperCase());
                    },
                },
                {
                    data: 'collected_by_name',
                    className: 'd-none d-lg-table-cell',
                    render(data) {
                        return escapeHtml(data || '—');
                    },
                },
                {
                    data: 'is_reversed',
                    render(data) {
                        const reversed = parseInt(data, 10) === 1;
                        return reversed
                            ? '<span class="branch-status-pill inactive"><span class="dot"></span> Reversed</span>'
                            : '<span class="branch-status-pill active"><span class="dot"></span> Active</span>';
                    },
                },
                {
                    data: 'id',
                    orderable: false,
                    className: 'text-center',
                    render(data, type, row) {
                        let html = '<div class="branch-action-bar">';
                        html += `<a href="${base}SupplierTransaction/details/${data}" class="btn-action view" title="Details"><i class="fas fa-eye"></i></a>`;
                        if (!showReversed && parseInt(row.can_reverse, 10) === 1) {
                            html += `<button type="button" class="btn-action toggle-off js-supp-reverse"
                                data-payment-id="${data}"
                                data-payment-code="${escapeHtml(row.payment_code || '')}"
                                title="Reverse payment"><i class="fas fa-rotate-left"></i></button>`;
                        }
                        html += '</div>';
                        return html;
                    },
                },
            ],
            createdRow(row, data) {
                if (parseInt(data.is_reversed, 10) === 1) {
                    $(row).addClass('table-secondary').attr('data-status', 'reversed');
                } else {
                    $(row).attr('data-status', 'active');
                }
                $(row).attr('data-mode', String(data.payment_mode || '').toLowerCase());
            },
            drawCallback() {
                renderMobileCards(suppTable);
            },
        });
    }

    function renderMobileCards(table) {
        const container = document.getElementById('suppTxnCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const base = window.ST_BASE || stBaseUrl();
        let html = '';

        table.rows({ page: 'current' }).every(function () {
            const row = this.node();
            if (!row) return;
            const $tr = $(row);
            const id = $tr.find('.js-supp-reverse').data('paymentId')
                || $tr.find('a[href*="details/"]').attr('href')?.split('/').pop();
            const code = $tr.find('.branch-code-pill').text().trim();
            const supplier = $tr.find('.branch-name-cell .name').text().trim();
            const mobile = $tr.find('.branch-contact')?.text()?.trim() || '';
            const typePill = $tr.find('.supp-txn-type-pill');
            const typeCls = typePill.attr('class')?.match(/supp-txn-type-pill\s+(\S+)/)?.[1] || 'payment';
            const typeLabel = typePill.text().trim();
            const amount = $tr.find('.supp-txn-amount').text().trim();
            const date = $tr.find('td:first small').text().trim();
            const mode = $tr.find('td').eq(5).text().trim();
            const reversed = $tr.hasClass('table-secondary') || $tr.data('status') === 'reversed';
            const canReverse = !window.showReversed && $tr.find('.js-supp-reverse').length > 0;

            html += `<article class="supp-txn-mobile-card${reversed ? ' reversed' : ''}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="branch-code-pill">${escapeHtml(code)}</div>
                        <div class="fw-semibold mt-1">${escapeHtml(supplier)}</div>
                        ${mobile ? `<div class="small text-muted">${escapeHtml(mobile)}</div>` : ''}
                    </div>
                    <span class="supp-txn-type-pill ${escapeHtml(typeCls)}">${escapeHtml(typeLabel)}</span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="small text-muted">${escapeHtml(date)} · ${escapeHtml(mode)}</span>
                    <span class="supp-txn-amount ${escapeHtml(typeCls)}">${escapeHtml(amount)}</span>
                </div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                    <a href="${base}SupplierTransaction/details/${escapeHtml(id)}" class="btn btn-sm btn-outline-primary flex-fill">
                        <i class="fas fa-eye me-1"></i> Details
                    </a>
                    ${canReverse ? `<button type="button" class="btn btn-sm btn-outline-danger js-supp-reverse flex-fill"
                        data-payment-id="${escapeHtml(id)}"
                        data-payment-code="${escapeHtml(code)}">
                        <i class="fas fa-rotate-left me-1"></i> Reverse
                    </button>` : ''}
                </div>
            </article>`;
        });

        container.innerHTML = html
            || '<p class="text-muted text-center py-4 mb-0">No payments found.</p>';
    }

    async function loadSupplierDue(base, supplierId, dueSummary) {
        if (!dueSummary) return;

        if (!supplierId) {
            dueSummary.classList.add('d-none');
            dueSummary.innerHTML = '';
            return;
        }

        dueSummary.classList.remove('d-none');
        dueSummary.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Loading payable…';

        try {
            const response = await fetch(base + 'SupplierTransaction/get_due', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: 'supplier_id=' + encodeURIComponent(supplierId),
            });
            const data = await parseJsonResponse(response);
            if (data.status === 'success') {
                dueSummary.innerHTML =
                    '<i class="fas fa-wallet me-1"></i> Payable now: <strong>' +
                    formatMoney(data.due_balance) + '</strong>';
            } else {
                dueSummary.innerHTML = '<span class="text-danger">Could not load payable balance</span>';
            }
        } catch (e) {
            console.error('Failed to load supplier due', e);
            dueSummary.innerHTML = '<span class="text-danger">Error loading payable</span>';
        }
    }

    function rememberSuppTxnRecent(supplierId, label) {
        if (!supplierId) return;
        let recents = [];
        try {
            recents = JSON.parse(localStorage.getItem(ST_RECENTS_KEY) || '[]');
        } catch (e) {
            recents = [];
        }
        recents = recents.filter((r) => String(r.id) !== String(supplierId));
        recents.unshift({ id: supplierId, label: label || ('Supplier #' + supplierId) });
        localStorage.setItem(ST_RECENTS_KEY, JSON.stringify(recents.slice(0, 5)));
    }

    function renderSuppTxnRecents(base, cache, onSelect) {
        const box = document.getElementById('suppTxnSupplierRecents');
        if (!box) return;

        let recents = [];
        try {
            recents = JSON.parse(localStorage.getItem(ST_RECENTS_KEY) || '[]');
        } catch (e) {
            recents = [];
        }

        if (!recents.length) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }

        box.classList.remove('d-none');
        box.innerHTML = recents.map((r) =>
            `<button type="button" class="btn btn-outline-secondary btn-sm" data-id="${escapeHtml(r.id)}">${escapeHtml(r.label)}</button>`
        ).join('');

        box.querySelectorAll('button').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.id, 10);
                if (cache[id]) {
                    onSelect(id, cache[id]);
                    return;
                }
                onSelect(id, { id, supplier_name: btn.textContent.trim() });
            });
        });
    }

    function initSupplierPicker(base, dueSummary, onSupplierChange) {
        const searchInput = document.getElementById('suppTxnSupplierSearch');
        const suggestBox = document.getElementById('suppTxnSupplierSuggestions');
        const supplierIdInput = document.getElementById('supplier_id');
        const changeBtn = document.getElementById('suppTxnChangeSupplier');
        const hubLink = document.getElementById('suppTxnSupplierHubLink');
        const hubAnchor = document.getElementById('suppTxnSupplierHubAnchor');
        const recentsBox = document.getElementById('suppTxnSupplierRecents');
        const searchLabel = document.getElementById('suppTxnSupplierSearchLabel');
        const cache = {};
        let selectedLabel = '';

        if (!searchInput || !supplierIdInput) {
            return { getLabel: () => 'Supplier', init: () => {} };
        }

        function setLocked(locked, supplier) {
            if (locked && supplier) {
                selectedLabel = shortSupplierLabel(supplier);
                searchInput.value = selectedLabel;
                searchInput.readOnly = true;
                searchInput.classList.add('is-locked');
                changeBtn?.classList.remove('d-none');
                recentsBox?.classList.add('is-hidden');
                if (searchLabel) {
                    searchLabel.innerHTML = 'Supplier <span class="text-danger">*</span> <span class="text-muted small fw-normal">(selected)</span>';
                }
                if (hubLink && hubAnchor) {
                    hubLink.classList.remove('d-none');
                    hubAnchor.href = base + 'supplier/show/' + supplier.id;
                }
            } else {
                selectedLabel = '';
                searchInput.readOnly = false;
                searchInput.classList.remove('is-locked');
                searchInput.value = '';
                changeBtn?.classList.add('d-none');
                recentsBox?.classList.remove('is-hidden');
                if (searchLabel) {
                    searchLabel.innerHTML = 'Supplier <span class="text-danger">*</span>';
                }
                hubLink?.classList.add('d-none');
            }
        }

        function selectSupplier(id, supplier) {
            if (!id) return;
            cache[id] = supplier || cache[id] || { id, supplier_name: 'Supplier #' + id };
            supplierIdInput.value = String(id);
            suggestBox?.classList.remove('show');
            if (suggestBox) suggestBox.innerHTML = '';
            setLocked(true, cache[id]);
            rememberSuppTxnRecent(id, shortSupplierLabel(cache[id]));
            renderSuppTxnRecents(base, cache, selectSupplier);
            loadSupplierDue(base, id, dueSummary);
            onSupplierChange?.();
        }

        function clearSelection() {
            supplierIdInput.value = '';
            setLocked(false);
            if (dueSummary) {
                dueSummary.classList.add('d-none');
                dueSummary.innerHTML = '';
            }
            onSupplierChange?.();
            searchInput.focus();
        }

        searchInput.addEventListener('input', debounce(async function () {
            const term = this.value.trim();
            if (!suggestBox) return;
            if (term.length < 1) {
                suggestBox.classList.remove('show');
                suggestBox.innerHTML = '';
                return;
            }

            try {
                const res = await fetch(base + 'SupplierTransaction/search_supplier?term=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                });
                const data = await parseJsonResponse(res);
                const rows = Array.isArray(data) ? data : (data.data || []);
                let html = '';
                rows.forEach((s) => {
                    cache[s.id] = s;
                    html += `<button type="button" class="supp-txn-suggest-item" data-id="${escapeHtml(s.id)}">
                        <span class="suggest-title">${escapeHtml(s.supplier_name || '')}</span>
                        <span class="suggest-meta">${escapeHtml(s.supplier_code || '')}${s.mobile ? ' · ' + escapeHtml(s.mobile) : ''}</span>
                    </button>`;
                });
                suggestBox.innerHTML = html || '<div class="supp-txn-suggest-empty">No supplier found</div>';
                suggestBox.classList.add('show');
            } catch (e) {
                console.error('Supplier search failed', e);
                suggestBox.innerHTML = '<div class="supp-txn-suggest-empty">Search failed — try again</div>';
                suggestBox.classList.add('show');
            }
        }, 250));

        suggestBox?.addEventListener('click', (e) => {
            const btn = e.target.closest('.supp-txn-suggest-item');
            if (!btn) return;
            selectSupplier(parseInt(btn.dataset.id, 10), cache[btn.dataset.id]);
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = suggestBox?.querySelector('.supp-txn-suggest-item');
                if (first) selectSupplier(parseInt(first.dataset.id, 10), cache[first.dataset.id]);
            }
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#suppTxnSupplierSearch') && !e.target.closest('#suppTxnSupplierSuggestions')) {
                suggestBox?.classList.remove('show');
            }
        });

        changeBtn?.addEventListener('click', clearSelection);

        return {
            getLabel: () => selectedLabel || searchInput.value.trim() || 'Supplier',
            selectSupplier,
            init() {
                renderSuppTxnRecents(base, cache, selectSupplier);
                const pre = window.ST_BOOT?.preselectSupplier;
                if (pre?.id) {
                    cache[pre.id] = pre;
                    selectSupplier(parseInt(pre.id, 10), pre);
                } else if (supplierIdInput.value) {
                    selectSupplier(parseInt(supplierIdInput.value, 10), null);
                }
            },
        };
    }

    function initCreateForm(base) {
        const form = document.getElementById('supplierTransactionForm');
        const modeSelect = document.getElementById('mode');
        const bankSection = document.getElementById('bank_section');
        const bankSelect = document.getElementById('bank_id');
        const supplierIdInput = document.getElementById('supplier_id');
        const dueSummary = document.getElementById('dueSummary');
        const typeSelect = document.getElementById('transaction_type');
        const amountInput = document.getElementById('amount');
        const typeHint = document.getElementById('typeHint');
        const glPreview = document.getElementById('accounting_preview');
        const glLabels = window.ST_BOOT?.glLabels || {};
        const glPreviewApi = window.AccountingJournalPreview;
        let supplierPicker = { getLabel: () => 'Supplier', init: () => {} };

        function renderGlPreview(type, amt, mode, bankName) {
            if (!glPreviewApi) {
                return;
            }
            glPreviewApi.renderSupplierPreview(glPreview, {
                type,
                amount: amt,
                mode,
                bankName,
                glLabels,
                partySelected: !!supplierIdInput?.value,
            });
        }

        function updatePreview() {
            const type = typeSelect?.value || '';
            const amt = parseFloat(amountInput?.value || 0);
            const mode = modeSelect?.value || 'cash';
            const bankName = bankSelect?.selectedOptions?.[0]?.text || glLabels.bank || 'Bank';
            renderGlPreview(type, amt, mode, bankName);
        }

        supplierPicker = initSupplierPicker(base, dueSummary, updatePreview);
        supplierPicker.init();

        function syncModeVisibility() {
            if (modeSelect && bankSection) {
                bankSection.style.display = modeSelect.value === 'bank' ? 'block' : 'none';
                if (bankSelect) {
                    if (modeSelect.value === 'bank') {
                        bankSelect.setAttribute('required', 'required');
                    } else {
                        bankSelect.removeAttribute('required');
                    }
                }
            }
        }

        if (modeSelect && bankSection) {
            modeSelect.addEventListener('change', () => {
                syncModeVisibility();
                updatePreview();
            });
        }
        bankSelect?.addEventListener('change', updatePreview);

        if (typeSelect) {
            typeSelect.addEventListener('change', () => {
                const t = typeSelect.value;
                if (typeHint) {
                    typeHint.textContent = TYPE_HINTS[t] || '';
                }
                syncModeVisibility();
                updatePreview();
            });
        }

        [typeSelect, amountInput].forEach((el) => {
            if (el) el.addEventListener('input', updatePreview);
            if (el) el.addEventListener('change', updatePreview);
        });

        if (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();

                if (!supplierIdInput?.value) {
                    Swal.fire('Supplier required', 'Search and select a supplier.', 'warning');
                    document.getElementById('suppTxnSupplierSearch')?.focus();
                    return;
                }

                if (!form.checkValidity()) {
                    form.classList.add('was-validated');
                    return;
                }

                const formData = new FormData(form);
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn?.innerHTML;
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving…';
                }

                try {
                    const response = await fetch(base + 'SupplierTransaction/store', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const result = await parseJsonResponse(response);

                    if (result.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Saved',
                            html: (result.message || 'Transaction recorded.') +
                                (result.payment_code
                                    ? '<br><small>' + escapeHtml(result.payment_code) + '</small>'
                                    : ''),
                            timer: 2200,
                            showConfirmButton: false,
                        }).then(() => {
                            const pid = result.payment_id;
                            window.location.href = pid
                                ? base + 'SupplierTransaction/details/' + pid
                                : base + 'SupplierTransaction';
                        });
                    } else {
                        Swal.fire('Error', result.message || 'Save failed', 'error');
                    }
                } catch (err) {
                    console.error(err);
                    Swal.fire('Error', err.message || 'Network or server error', 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                }
            });
        }

        syncModeVisibility();
        updatePreview();
    }

    async function reverseTransaction(id, paymentCode) {
        const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
        const { value: reason, isConfirmed } = await Swal.fire({
            title: 'Reverse payment?',
            html:
                'Transaction <strong>' + escapeHtml(paymentCode || id) + '</strong> will be reversed.<br>' +
                '<span class="text-danger small">GL, supplier ledger, and bank book (if any) are undone. This cannot be undone.</span>',
            input: 'textarea',
            inputLabel: 'Reason (required, min 3 characters)',
            inputPlaceholder: 'Why is this payment being reversed?',
            inputAttributes: { 'aria-label': 'Reversal reason', maxlength: 500 },
            showCancelButton: true,
            confirmButtonText: 'Yes, reverse',
            confirmButtonColor: '#dc2626',
            focusConfirm: false,
            preConfirm: (value) => {
                const r = String(value || '').trim();
                if (r.length < 3) {
                    Swal.showValidationMessage('Please enter at least 3 characters.');
                    return false;
                }
                return r;
            },
        });

        if (!isConfirmed || !reason) return;

        Swal.fire({
            title: 'Reversing…',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        try {
            const body = new URLSearchParams({
                csrf_token: csrf,
                id: String(id),
                reason: reason.trim(),
            });
            const response = await fetch((window.ST_BASE || stBaseUrl()) + 'SupplierTransaction/reverse', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: body.toString(),
            });
            const result = await parseJsonResponse(response);
            Swal.close();

            if (result.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Reversed',
                    text: result.message,
                    timer: 2000,
                    showConfirmButton: false,
                }).then(() => {
                    const url = result.redirect_url
                        || (window.ST_BASE || stBaseUrl()) + 'SupplierTransaction/details/' + id;
                    window.location.href = url;
                });
            } else {
                Swal.fire('Reversal failed', result.message || 'Could not reverse', 'error');
            }
        } catch (e) {
            Swal.close();
            Swal.fire('Error', 'Could not reach server', 'error');
        }
    }

    window.reverseTransaction = reverseTransaction;
})();
