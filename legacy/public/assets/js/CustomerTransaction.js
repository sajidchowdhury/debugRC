/**
 * Customer payments — index (DataTable + mobile cards), create, reverse.
 */
(function () {
    'use strict';

    const TYPE_HINTS = {
        receive: 'Money in from customer — reduces AR (credit ledger). Posts Dr Cash/Bank, Cr AR.',
        payment: 'Refund or advance paid out — increases AR (debit ledger). Posts Dr AR, Cr Cash/Bank.',
        discount: 'Discount allowed — no cash movement. Amount cannot exceed current due.',
        write_off: 'Bad debt write-off — no cash movement. Amount cannot exceed current due.',
    };

    const TYPE_LABELS = {
        receive: 'Receive',
        payment: 'Payment',
        discount: 'Discount',
        write_off: 'Write-off',
    };

    let custTable = null;
    let lastDue = 0;
    const CT_RECENTS_KEY = 'cust_txn_customer_recents';

    function debounce(fn, ms) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function shortCustomerLabel(c) {
        if (!c) return 'Customer';
        const name = (c.shop_name || c.customer_name || 'Customer').trim();
        return name.length > 28 ? name.slice(0, 28) + '…' : name;
    }

    function ctBaseUrl() {
        if (window.CT_BOOT?.baseUrl) {
            const u = window.CT_BOOT.baseUrl;
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
        const base = ctBaseUrl();
        window.CT_BASE = base;

        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-cust-reverse');
            if (!btn) return;
            reverseTransaction(
                btn.dataset.paymentId,
                btn.dataset.paymentCode
            );
        });

        const txnTable = document.getElementById('custTxnTable');
        if (txnTable) {
            initIndexPage(base);
        }

        if (document.getElementById('customerTransactionForm')) {
            initCreateForm(base);
        }

        $(window).on('resize', () => {
            if (custTable) renderMobileCards(custTable);
        });
    });

    function custTxnTypeLabel(type) {
        return TYPE_LABELS[type] || (type ? type.charAt(0).toUpperCase() + type.slice(1) : '');
    }

    function custTxnTypeClass(type) {
        const t = String(type || '').replace(/[^a-z_]/gi, '').toLowerCase();
        return ['receive', 'payment', 'discount', 'write_off'].includes(t) ? t : 'receive';
    }

    function formatPaymentDate(value) {
        if (!value) return '—';
        const parts = String(value).split('-');
        if (parts.length !== 3) return value;
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function getCustTxnFilterParams() {
        const form = document.getElementById('custTxnFilterForm');
        if (!form) return {};
        return {
            date_from: form.querySelector('[name="date_from"]')?.value || '',
            date_to: form.querySelector('[name="date_to"]')?.value || '',
            transaction_type: form.querySelector('[name="transaction_type"]')?.value || 'all',
            status: form.querySelector('[name="status"]')?.value || 'all',
            payment_mode: form.querySelector('[name="payment_mode"]')?.value || 'all',
            customer_id: form.querySelector('[name="customer_id"]')?.value || '',
        };
    }

    function syncCustTxnFilterUrl() {
        const form = document.getElementById('custTxnFilterForm');
        if (!form || !window.history?.replaceState) return;
        const params = new URLSearchParams(getCustTxnFilterParams());
        Object.keys(Object.fromEntries(params)).forEach((key) => {
            const val = params.get(key);
            if (!val || val === 'all') params.delete(key);
        });
        const qs = params.toString();
        const base = (window.CT_BASE || ctBaseUrl()) + 'CustomerTransaction';
        window.history.replaceState(null, '', qs ? base + '?' + qs : base);
    }

    function initFilterCustomerSearch(base) {
        const searchInput = document.getElementById('filter_customer_search');
        const hiddenInput = document.getElementById('filter_customer_id');
        const suggestBox = document.getElementById('filterCustomerSuggestions');
        const clearBtn = document.getElementById('custTxnClearCustomerBtn');
        if (!searchInput || !hiddenInput || !suggestBox) return;

        const cache = {};
        const pre = window.CT_BOOT?.filterCustomer;
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
                const res = await fetch(base + 'CustomerTransaction/search_customer?term=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                });
                const rows = await parseJsonResponse(res);
                const list = Array.isArray(rows) ? rows : (rows.data || []);
                let html = '';
                list.forEach((c) => {
                    cache[c.id] = c;
                    html += `<button type="button" class="cust-txn-suggest-item" data-id="${escapeHtml(c.id)}">
                        <span class="suggest-title">${escapeHtml(c.shop_name || c.customer_name || '')}</span>
                        <span class="suggest-meta">${escapeHtml(c.customer_code || '')}${c.mobile ? ' · ' + escapeHtml(c.mobile) : ''}</span>
                    </button>`;
                });
                suggestBox.innerHTML = html || '<div class="cust-txn-suggest-empty">No customer found</div>';
                suggestBox.classList.add('show');
            } catch (e) {
                console.error('Filter customer search failed', e);
            }
        }, 250));

        suggestBox.addEventListener('click', (e) => {
            const btn = e.target.closest('.cust-txn-suggest-item');
            if (!btn) return;
            const id = btn.dataset.id;
            const c = cache[id];
            hiddenInput.value = id;
            searchInput.value = c?.shop_name || c?.customer_name || btn.querySelector('.suggest-title')?.textContent || '';
            suggestBox.classList.remove('show');
            suggestBox.innerHTML = '';
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.cust-txn-filter-customer')) {
                suggestBox.classList.remove('show');
            }
        });

        clearBtn?.addEventListener('click', () => {
            hiddenInput.value = '';
            searchInput.value = '';
            suggestBox.classList.remove('show');
            suggestBox.innerHTML = '';
            custTable?.ajax.reload();
            syncCustTxnFilterUrl();
        });
    }

    function initIndexPage(base) {
        window.CT_BASE = base;
        const showReversed = !!window.showReversed;
        initFilterCustomerSearch(base);

        const form = document.getElementById('custTxnFilterForm');
        if (showReversed) {
            const statusField = form?.querySelector('[name="status"]');
            if (statusField) {
                statusField.value = 'reversed';
                statusField.disabled = true;
            }
        }

        form?.addEventListener('submit', (e) => {
            e.preventDefault();
            custTable?.ajax.reload();
            syncCustTxnFilterUrl();
        });

        document.getElementById('custTxnTodayBtn')?.addEventListener('click', () => {
            const today = new Date().toISOString().slice(0, 10);
            form.querySelector('[name="date_from"]').value = today;
            form.querySelector('[name="date_to"]').value = today;
            custTable?.ajax.reload();
            syncCustTxnFilterUrl();
        });

        initIndexTable(base, showReversed);
    }

    function initIndexTable(base, showReversed) {
        if (typeof $ === 'undefined' || !$.fn.DataTable) {
            return;
        }

        const $table = $('#custTxnTable');
        if ($.fn.DataTable.isDataTable($table)) {
            $table.DataTable().destroy();
        }

        custTable = $table.DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: base + 'CustomerTransaction' + (showReversed ? '?reversed=1' : ''),
                data(d) {
                    Object.assign(d, getCustTxnFilterParams());
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
                    data: 'shop_name',
                    render(data, type, row) {
                        const shop = data || '—';
                        const initial = shop.charAt(0).toUpperCase();
                        const mobile = row.mobile
                            ? `<div class="branch-contact"><i class="fas fa-phone"></i> ${escapeHtml(row.mobile)}</div>`
                            : '';
                        const hub = row.customer_id
                            ? `<div class="name"><a href="${base}customer/show/${row.customer_id}" class="text-decoration-none text-reset">${escapeHtml(shop)}</a></div>`
                            : `<div class="name">${escapeHtml(shop)}</div>`;
                        return `<div class="branch-name-cell">
                            <div class="branch-avatar">${escapeHtml(initial)}</div>
                            <div>${hub}${mobile}</div>
                        </div>`;
                    },
                },
                {
                    data: 'transaction_type',
                    render(data) {
                        const cls = custTxnTypeClass(data);
                        return `<span class="cust-txn-type-pill ${cls}">${escapeHtml(custTxnTypeLabel(data))}</span>`;
                    },
                },
                {
                    data: 'amount',
                    className: 'text-end',
                    render(data, type, row) {
                        const cls = custTxnTypeClass(row.transaction_type);
                        return `<span class="cust-txn-amount ${cls}">${formatMoney(data)}</span>`;
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
                        html += `<a href="${base}CustomerTransaction/details/${data}" class="btn-action view" title="Details"><i class="fas fa-eye"></i></a>`;
                        if (!showReversed && parseInt(row.can_reverse, 10) === 1) {
                            html += `<button type="button" class="btn-action toggle-off js-cust-reverse"
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
                renderMobileCards(custTable);
            },
        });
    }

    function renderMobileCards(table) {
        const container = document.getElementById('custTxnCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const base = window.CT_BASE || ctBaseUrl();
        let html = '';

        table.rows({ page: 'current' }).every(function () {
            const row = this.node();
            if (!row) return;
            const $tr = $(row);
            const id = $tr.find('.js-cust-reverse').data('paymentId')
                || $tr.find('a[href*="details/"]').attr('href')?.split('/').pop();
            const code = $tr.find('.branch-code-pill').text().trim();
            const customer = $tr.find('.branch-name-cell .name').text().trim();
            const mobile = $tr.find('.branch-contact')?.text()?.trim() || '';
            const typePill = $tr.find('.cust-txn-type-pill');
            const typeCls = typePill.attr('class')?.match(/cust-txn-type-pill\s+(\S+)/)?.[1] || 'receive';
            const typeLabel = typePill.text().trim();
            const amount = $tr.find('.cust-txn-amount').text().trim();
            const date = $tr.find('td:first small').text().trim();
            const mode = $tr.find('td').eq(5).text().trim();
            const reversed = $tr.hasClass('table-secondary') || $tr.data('status') === 'reversed';
            const canReverse = !window.showReversed && $tr.find('.js-cust-reverse').length > 0;

            html += `<article class="cust-txn-mobile-card${reversed ? ' reversed' : ''}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="branch-code-pill">${escapeHtml(code)}</div>
                        <div class="fw-semibold mt-1">${escapeHtml(customer)}</div>
                        ${mobile ? `<div class="small text-muted">${escapeHtml(mobile)}</div>` : ''}
                    </div>
                    <span class="cust-txn-type-pill ${escapeHtml(typeCls)}">${escapeHtml(typeLabel)}</span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="small text-muted">${escapeHtml(date)} · ${escapeHtml(mode)}</span>
                    <span class="cust-txn-amount ${escapeHtml(typeCls)}">${escapeHtml(amount)}</span>
                </div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                    <a href="${base}CustomerTransaction/details/${escapeHtml(id)}" class="btn btn-sm btn-outline-primary flex-fill">
                        <i class="fas fa-eye me-1"></i> Details
                    </a>
                    ${canReverse ? `<button type="button" class="btn btn-sm btn-outline-danger js-cust-reverse flex-fill"
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

    async function loadCustomerDue(base, customerId, dueSummary) {
        if (!dueSummary) return;

        if (!customerId) {
            dueSummary.classList.add('d-none');
            dueSummary.innerHTML = '';
            lastDue = 0;
            return;
        }

        dueSummary.classList.remove('d-none');
        dueSummary.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Loading due…';

        try {
            const response = await fetch(base + 'CustomerTransaction/get_due', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: 'customer_id=' + encodeURIComponent(customerId),
            });
            const data = await parseJsonResponse(response);
            if (data.status === 'success') {
                lastDue = parseFloat(data.due_balance || 0);
                dueSummary.innerHTML =
                    '<i class="fas fa-wallet me-1"></i> Current due: <strong>' +
                    formatMoney(lastDue) + '</strong>';
            } else {
                dueSummary.innerHTML = '<span class="text-danger">Could not load due balance</span>';
            }
        } catch (e) {
            console.error('Failed to load customer due', e);
            dueSummary.innerHTML = '<span class="text-danger">Error loading due</span>';
        }
    }

    function rememberCustTxnRecent(customerId, label) {
        if (!customerId) return;
        let recents = [];
        try {
            recents = JSON.parse(localStorage.getItem(CT_RECENTS_KEY) || '[]');
        } catch (e) {
            recents = [];
        }
        recents = recents.filter((r) => String(r.id) !== String(customerId));
        recents.unshift({ id: customerId, label: label || ('Customer #' + customerId) });
        localStorage.setItem(CT_RECENTS_KEY, JSON.stringify(recents.slice(0, 5)));
    }

    function renderCustTxnRecents(base, cache, onSelect) {
        const box = document.getElementById('custTxnCustomerRecents');
        if (!box) return;

        let recents = [];
        try {
            recents = JSON.parse(localStorage.getItem(CT_RECENTS_KEY) || '[]');
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
                onSelect(id, { id, shop_name: btn.textContent.trim() });
            });
        });
    }

    function initCustomerPicker(base, dueSummary, onCustomerChange) {
        const searchInput = document.getElementById('custTxnCustomerSearch');
        const suggestBox = document.getElementById('custTxnCustomerSuggestions');
        const customerIdInput = document.getElementById('customer_id');
        const changeBtn = document.getElementById('custTxnChangeCustomer');
        const hubLink = document.getElementById('custTxnCustomerHubLink');
        const hubAnchor = document.getElementById('custTxnCustomerHubAnchor');
        const recentsBox = document.getElementById('custTxnCustomerRecents');
        const searchLabel = document.getElementById('custTxnCustomerSearchLabel');
        const cache = {};
        let selectedLabel = '';

        if (!searchInput || !customerIdInput) {
            return { getLabel: () => 'Customer', init: () => {} };
        }

        function setLocked(locked, customer) {
            if (locked && customer) {
                selectedLabel = shortCustomerLabel(customer);
                searchInput.value = selectedLabel;
                searchInput.readOnly = true;
                searchInput.classList.add('is-locked');
                changeBtn?.classList.remove('d-none');
                recentsBox?.classList.add('is-hidden');
                if (searchLabel) {
                    searchLabel.innerHTML = 'Customer <span class="text-danger">*</span> <span class="text-white-50 small fw-normal">(selected)</span>';
                }
                if (hubLink && hubAnchor) {
                    hubLink.classList.remove('d-none');
                    hubAnchor.href = base + 'customer/show/' + customer.id;
                }
            } else {
                selectedLabel = '';
                searchInput.readOnly = false;
                searchInput.classList.remove('is-locked');
                searchInput.value = '';
                changeBtn?.classList.add('d-none');
                recentsBox?.classList.remove('is-hidden');
                if (searchLabel) {
                    searchLabel.innerHTML = 'Customer <span class="text-danger">*</span>';
                }
                hubLink?.classList.add('d-none');
            }
        }

        function selectCustomer(id, customer) {
            if (!id) return;
            cache[id] = customer || cache[id] || { id, shop_name: 'Customer #' + id };
            customerIdInput.value = String(id);
            suggestBox?.classList.remove('show');
            if (suggestBox) suggestBox.innerHTML = '';
            setLocked(true, cache[id]);
            rememberCustTxnRecent(id, shortCustomerLabel(cache[id]));
            renderCustTxnRecents(base, cache, selectCustomer);
            loadCustomerDue(base, id, dueSummary);
            onCustomerChange?.();
        }

        function clearSelection() {
            customerIdInput.value = '';
            setLocked(false);
            lastDue = 0;
            if (dueSummary) {
                dueSummary.classList.add('d-none');
                dueSummary.innerHTML = '';
            }
            onCustomerChange?.();
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
                const res = await fetch(base + 'CustomerTransaction/search_customer?term=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                });
                const data = await parseJsonResponse(res);
                const rows = Array.isArray(data) ? data : (data.data || []);
                let html = '';
                rows.forEach((c) => {
                    cache[c.id] = c;
                    html += `<button type="button" class="cust-txn-suggest-item" data-id="${escapeHtml(c.id)}">
                        <span class="suggest-title">${escapeHtml(c.shop_name || c.customer_name || '')}</span>
                        <span class="suggest-meta">${escapeHtml(c.customer_code || '')}${c.customer_code ? ' · ' : ''}${escapeHtml(c.customer_name || '')}${c.mobile ? ' · ' + escapeHtml(c.mobile) : ''}</span>
                    </button>`;
                });
                suggestBox.innerHTML = html || '<div class="cust-txn-suggest-empty">No customer found</div>';
                suggestBox.classList.add('show');
            } catch (e) {
                console.error('Customer search failed', e);
                suggestBox.innerHTML = '<div class="cust-txn-suggest-empty">Search failed — try again</div>';
                suggestBox.classList.add('show');
            }
        }, 250));

        suggestBox?.addEventListener('click', (e) => {
            const btn = e.target.closest('.cust-txn-suggest-item');
            if (!btn) return;
            selectCustomer(parseInt(btn.dataset.id, 10), cache[btn.dataset.id]);
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = suggestBox?.querySelector('.cust-txn-suggest-item');
                if (first) selectCustomer(parseInt(first.dataset.id, 10), cache[first.dataset.id]);
            }
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#custTxnCustomerSearch') && !e.target.closest('#custTxnCustomerSuggestions')) {
                suggestBox?.classList.remove('show');
            }
        });

        changeBtn?.addEventListener('click', clearSelection);

        return {
            getLabel: () => selectedLabel || searchInput.value.trim() || 'Customer',
            selectCustomer,
            init() {
                renderCustTxnRecents(base, cache, selectCustomer);
                const pre = window.CT_BOOT?.preselectCustomer;
                if (pre?.id) {
                    cache[pre.id] = pre;
                    selectCustomer(parseInt(pre.id, 10), pre);
                } else if (customerIdInput.value) {
                    selectCustomer(parseInt(customerIdInput.value, 10), null);
                }
            },
        };
    }

    function initCreateForm(base) {
        const form = document.getElementById('customerTransactionForm');
        const modeSelect = document.getElementById('mode');
        const bankSection = document.getElementById('bank_section');
        const bankSelect = document.getElementById('bank_id');
        const customerIdInput = document.getElementById('customer_id');
        const dueSummary = document.getElementById('dueSummary');
        const typeSelect = document.getElementById('transaction_type');
        const amountInput = document.getElementById('amount');
        const typeHint = document.getElementById('typeHint');
        const glPreview = document.getElementById('accounting_preview');
        const glLabels = window.CT_BOOT?.glLabels || {};
        const glPreviewApi = window.AccountingJournalPreview;
        let customerPicker = { getLabel: () => 'Customer', init: () => {} };

        function renderGlPreview(type, amt, mode, bankName) {
            if (!glPreviewApi) {
                return;
            }
            glPreviewApi.renderCustomerPreview(glPreview, {
                type,
                amount: amt,
                mode,
                bankName,
                glLabels,
                partySelected: !!customerIdInput?.value,
            });
        }

        function updatePreview() {
            const type = typeSelect?.value || '';
            const amt = parseFloat(amountInput?.value || 0);
            const mode = modeSelect?.value || 'cash';
            const bankName = bankSelect?.selectedOptions?.[0]?.text || glLabels.bank || 'Bank';
            renderGlPreview(type, amt, mode, bankName);
        }

        customerPicker = initCustomerPicker(base, dueSummary, updatePreview);
        customerPicker.init();

        function syncModeVisibility() {
            const type = typeSelect?.value || '';
            const noCash = type === 'discount' || type === 'write_off';
            if (noCash && modeSelect) {
                modeSelect.value = 'cash';
                if (bankSection) bankSection.style.display = 'none';
                if (bankSelect) bankSelect.removeAttribute('required');
            } else if (modeSelect && bankSection) {
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

                if (!customerIdInput?.value) {
                    Swal.fire('Customer required', 'Search and select a customer.', 'warning');
                    document.getElementById('custTxnCustomerSearch')?.focus();
                    return;
                }

                if (!form.checkValidity()) {
                    form.classList.add('was-validated');
                    return;
                }

                const type = typeSelect?.value || '';
                const amt = parseFloat(amountInput?.value || 0);
                if (['discount', 'write_off'].includes(type) && amt > lastDue + 0.01) {
                    Swal.fire(
                        'Amount too high',
                        'Cannot exceed customer due (' + formatMoney(lastDue) + ').',
                        'warning'
                    );
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
                    const response = await fetch(base + 'CustomerTransaction/store', {
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
                            html: (result.message || 'Payment recorded.') +
                                (result.payment_code
                                    ? '<br><small>' + escapeHtml(result.payment_code) + '</small>'
                                    : ''),
                            timer: 2200,
                            showConfirmButton: false,
                        }).then(() => {
                            const pid = result.payment_id;
                            window.location.href = pid
                                ? base + 'CustomerTransaction/details/' + pid
                                : base + 'CustomerTransaction';
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
                'Payment <strong>' + escapeHtml(paymentCode || id) + '</strong> will be reversed.<br>' +
                '<span class="text-danger small">GL, customer ledger, and bank book (if any) are undone. This cannot be undone.</span>',
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
            const response = await fetch((window.CT_BASE || ctBaseUrl()) + 'CustomerTransaction/reverse', {
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
                        || (window.CT_BASE || ctBaseUrl()) + 'CustomerTransaction/details/' + id;
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