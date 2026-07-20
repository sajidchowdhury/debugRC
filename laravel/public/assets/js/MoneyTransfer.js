/**
 * Money transfers — index, create (with demand preview), details reverse.
 */
(function () {
    'use strict';

    const TYPE_LABELS = {
        cash_to_bank: 'Cash → Bank',
        bank_to_cash: 'Bank → Cash',
        cash_to_cash: 'Cash → Cash',
        bank_to_bank: 'Bank → Bank',
    };

    function mtBaseUrl() {
        if (window.MT_BOOT?.baseUrl) {
            const u = window.MT_BOOT.baseUrl;
            return u.endsWith('/') ? u : u + '/';
        }
        const el = document.getElementById('base_url');
        const u = el ? el.value : '/';
        return u.endsWith('/') ? u : u + '/';
    }

    function csrfToken() {
        const el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
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

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function reverseTransfer(id, code) {
        const base = mtBaseUrl();
        Swal.fire({
            title: 'Reverse transfer ' + (code || '#' + id) + '?',
            input: 'textarea',
            inputLabel: 'Reason (required, min 3 characters)',
            inputPlaceholder: 'Why is this transfer being reversed?',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Reverse',
        }).then(async (result) => {
            if (!result.isConfirmed) return;
            const reason = (result.value || '').trim();
            if (reason.length < 3) {
                Swal.fire('Error', 'Reversal reason is required (min 3 characters).', 'error');
                return;
            }

            const body = new URLSearchParams({
                id: String(id),
                reason,
                csrf_token: csrfToken(),
            });

            try {
                const res = await fetch(base + 'MoneyTransfer/reverse', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                const data = await parseJsonResponse(res);
                if (data.status === 'success') {
                    Swal.fire('Reversed', data.message || 'Transfer reversed.', 'success').then(() => {
                        window.location.href = data.redirect_url || (base + 'MoneyTransfer/details/' + id);
                    });
                } else {
                    Swal.fire('Error', data.message || 'Could not reverse', 'error');
                }
            } catch (err) {
                Swal.fire('Network Error', 'Please try again.', 'error');
            }
        });
    }

    window.reverseTransfer = reverseTransfer;

    document.addEventListener('DOMContentLoaded', () => {
        const base = mtBaseUrl();
        window.MT_BASE = base;

        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-mt-reverse');
            if (!btn) return;
            reverseTransfer(btn.dataset.transferId, btn.dataset.transferCode);
        });

        initCreateForm(base);
        initIndexTable(base);
    });

    function initIndexTable(base) {
        const tableEl = document.getElementById('transferTable');
        if (!tableEl || typeof $ === 'undefined' || !$.fn.DataTable) return;

        const showReversed = !!window.showReversed;
        const today = new Date().toISOString().split('T')[0];

        if (showReversed) {
            const fs = document.getElementById('filterStatus');
            if (fs) {
                fs.value = 'reversed';
                fs.disabled = true;
            }
        }

        const table = $('#transferTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: base + 'MoneyTransfer' + (showReversed ? '?reversed=1' : ''),
                data(d) {
                    d.fromDate = $('#fromDate').val();
                    d.toDate = $('#toDate').val();
                    d.filterType = $('#filterType').val();
                    d.filterStatus = $('#filterStatus').val();
                    if (showReversed) d.reversedMode = 'only_reversed';
                },
            },
            pageLength: 25,
            order: [[0, 'desc']],
            dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
            buttons: ['copy', 'excel', 'pdf'],
            drawCallback() {
                renderTransferCards(this.api());
            },
            columns: [
                {
                    data: 'transfer_date',
                    render(data) {
                        return new Date(data).toLocaleDateString('en-GB', {
                            day: '2-digit',
                            month: 'short',
                            year: 'numeric',
                        });
                    },
                },
                { data: 'transfer_code' },
                {
                    data: 'transfer_type',
                    render(data) {
                        const label = TYPE_LABELS[data] || data;
                        return '<span class="mt-txn-type-pill">' + escapeHtml(label) + '</span>';
                    },
                },
                {
                    data: 'amount',
                    className: 'text-end fw-bold',
                    render(data) {
                        return 'Tk ' + parseFloat(data || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
                    },
                },
                { data: 'from_bank', render: (d) => d || 'Cash' },
                { data: 'to_bank', render: (d) => d || 'Cash' },
                {
                    data: null,
                    render(_d, _t, row) {
                        return escapeHtml(row.from_branch_name || '') + ' → ' + escapeHtml(row.to_branch_name || '');
                    },
                },
                {
                    data: 'is_reversed',
                    render(data) {
                        return data == 1
                            ? '<span class="branch-status-pill inactive"><span class="dot"></span> Reversed</span>'
                            : '<span class="branch-status-pill active"><span class="dot"></span> Active</span>';
                    },
                },
                {
                    data: 'id',
                    orderable: false,
                    className: 'text-center',
                    render(data, _t, row) {
                        let html = '<a href="' + base + 'MoneyTransfer/details/' + data + '" class="btn-action view" title="Details"><i class="fas fa-eye"></i></a>';
                        if (!showReversed && row.is_reversed == 0) {
                            html += ' <button type="button" class="btn-action toggle-off js-mt-reverse-index" data-transfer-id="' + data + '" data-transfer-code="' + escapeHtml(row.transfer_code || '') + '" title="Reverse"><i class="fas fa-undo"></i></button>';
                        }
                        return html;
                    },
                },
            ],
        });

        document.getElementById('transferTable')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-mt-reverse-index');
            if (!btn) return;
            reverseTransfer(btn.dataset.transferId, btn.dataset.transferCode);
        });

        $('#filterType, #filterStatus, #fromDate, #toDate').on('change', () => table.ajax.reload());
        $('#clearFilters').on('click', () => {
            $('#filterType, #filterStatus').val('');
            $('#fromDate, #toDate').val(today);
            table.ajax.reload();
        });
    }

    function renderTransferCards(api) {
        const container = document.getElementById('transferCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const base = mtBaseUrl();
        const showReversed = !!window.showReversed;
        const data = api.rows({ page: 'current' }).data();
        let html = '';

        if (data.length === 0) {
            html = '<div class="text-center text-muted py-4">No transfers for selected filters.</div>';
        } else {
            data.each((row) => {
                const date = new Date(row.transfer_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
                const amount = 'Tk ' + parseFloat(row.amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
                const typeLabel = TYPE_LABELS[row.transfer_type] || row.transfer_type;
                let actions = '<a href="' + base + 'MoneyTransfer/details/' + row.id + '" class="btn-action view"><i class="fas fa-eye"></i> View</a>';
                if (!showReversed && row.is_reversed == 0) {
                    actions += ' <button type="button" class="btn-action toggle-off js-mt-reverse-index" data-transfer-id="' + row.id + '" data-transfer-code="' + escapeHtml(row.transfer_code || '') + '"><i class="fas fa-undo"></i></button>';
                }
                html += `
                    <article class="cust-txn-mobile-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><strong>${escapeHtml(row.transfer_code)}</strong><br><small class="text-muted">${date}</small></div>
                            <div class="text-end"><div class="fw-bold">${amount}</div><span class="mt-txn-type-pill">${escapeHtml(typeLabel)}</span></div>
                        </div>
                        <div class="mt-2 small">
                            <strong>From:</strong> ${escapeHtml(row.from_bank || 'Cash')} (${escapeHtml(row.from_branch_name)})<br>
                            <strong>To:</strong> ${escapeHtml(row.to_bank || 'Cash')} (${escapeHtml(row.to_branch_name)})
                        </div>
                        <div class="mt-2">${row.is_reversed == 1
                            ? '<span class="branch-status-pill inactive"><span class="dot"></span> Reversed</span>'
                            : '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'}</div>
                        <div class="mt-3">${actions}</div>
                    </article>`;
            });
        }
        container.innerHTML = html;
        container.querySelectorAll('.js-mt-reverse-index').forEach((btn) => {
            btn.addEventListener('click', () => reverseTransfer(btn.dataset.transferId, btn.dataset.transferCode));
        });
    }

    function initCreateForm(base) {
        const form = document.getElementById('moneyTransferForm');
        if (!form) return;

        const typeSelect = document.getElementById('transfer_type');
        const amountInput = document.getElementById('amount');
        const fromSection = document.getElementById('from_section');
        const toSection = document.getElementById('to_section');
        const previewContainer = document.getElementById('accounting_preview');
        const demandPreview = document.getElementById('demand_preview');

        const banksOptions = window.MT_BANKS_OPTIONS || '';
        const branchesOptions = window.MT_BRANCHES_OPTIONS || '';
        const currentBranchId = window.MT_CURRENT_BRANCH_ID || 0;
        const currentBranchName = window.MT_CURRENT_BRANCH_NAME || 'Branch';

        let previewTimer = null;

        function renderDynamicFields(type) {
            let f = '';
            let t = '';

            if (type === 'cash_to_bank') {
                f = `
                    <div class="mb-3">
                        <label class="form-label small">From Branch</label>
                        <input type="text" class="form-control form-control-sm" value="${escapeHtml(currentBranchName)}" readonly>
                    </div>
                    <div>
                        <label class="form-label small">From Cash Point <span class="text-danger">*</span></label>
                        <select name="from_cash_point" class="form-select" required><option value="main_cash">Main Cash</option></select>
                    </div>`;
                t = `
                    <div class="mb-3">
                        <label class="form-label small">To Bank <span class="text-danger">*</span></label>
                        <select name="to_bank_id" class="form-select" required><option value="">— Select Bank —</option>${banksOptions}</select>
                    </div>
                    <div>
                        <label class="form-label small">To Branch <span class="text-danger">*</span></label>
                        <select name="to_branch_id" id="to_branch_id" class="form-select" required><option value="">— Select Branch —</option>${branchesOptions}</select>
                        <div class="form-text">Inter-branch deposit can auto-settle open branch demands (FIFO).</div>
                    </div>`;
            } else if (type === 'bank_to_cash') {
                f = `
                    <div class="mb-3">
                        <label class="form-label small">From Bank <span class="text-danger">*</span></label>
                        <select name="from_bank_id" class="form-select" required><option value="">— Select Bank —</option>${banksOptions}</select>
                    </div>`;
                t = `
                    <div class="mb-3">
                        <label class="form-label small">To Branch</label>
                        <input type="text" class="form-control form-control-sm" value="${escapeHtml(currentBranchName)}" readonly>
                    </div>
                    <div>
                        <label class="form-label small">To Cash Point <span class="text-danger">*</span></label>
                        <select name="to_cash_point" class="form-select" required><option value="main_cash">Main Cash</option></select>
                    </div>`;
            } else if (type === 'cash_to_cash') {
                f = `
                    <div class="mb-3">
                        <label class="form-label small">From Branch</label>
                        <input type="text" class="form-control form-control-sm" value="${escapeHtml(currentBranchName)}" readonly>
                    </div>
                    <div>
                        <label class="form-label small">From Cash Point <span class="text-danger">*</span></label>
                        <select name="from_cash_point" class="form-select" required><option value="main_cash">Main Cash</option></select>
                    </div>`;
                t = `
                    <div class="mb-3">
                        <label class="form-label small">To Branch <span class="text-danger">*</span></label>
                        <select name="to_branch_id" id="to_branch_id" class="form-select" required><option value="">— Select Branch —</option>${branchesOptions}</select>
                    </div>
                    <div>
                        <label class="form-label small">To Cash Point <span class="text-danger">*</span></label>
                        <select name="to_cash_point" class="form-select" required><option value="main_cash">Main Cash</option></select>
                    </div>`;
            } else if (type === 'bank_to_bank') {
                f = `
                    <div class="mb-3">
                        <label class="form-label small">From Bank <span class="text-danger">*</span></label>
                        <select name="from_bank_id" class="form-select" required><option value="">— Select Bank —</option>${banksOptions}</select>
                    </div>`;
                t = `
                    <div class="mb-3">
                        <label class="form-label small">To Bank <span class="text-danger">*</span></label>
                        <select name="to_bank_id" class="form-select" required><option value="">— Select Bank —</option>${banksOptions}</select>
                    </div>`;
            }

            if (fromSection) fromSection.innerHTML = f;
            if (toSection) toSection.innerHTML = t;
            scheduleDemandPreview();
        }

        function updateAccountingPreview() {
            if (!previewContainer) return;
            const type = typeSelect?.value || '';
            const amt = parseFloat(amountInput?.value) || 0;
            if (!type || amt <= 0) {
                previewContainer.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-balance-scale fa-2x mb-3"></i><p>Select transfer type and amount</p></div>';
                return;
            }
            let debitLabel = '';
            let creditLabel = '';
            if (type === 'cash_to_bank') { debitLabel = 'Bank'; creditLabel = 'Cash'; }
            else if (type === 'bank_to_cash') { debitLabel = 'Cash'; creditLabel = 'Bank'; }
            else if (type === 'cash_to_cash') { debitLabel = 'Receiving cash'; creditLabel = 'Sending cash'; }
            else if (type === 'bank_to_bank') { debitLabel = 'Receiving bank'; creditLabel = 'Sending bank'; }
            previewContainer.innerHTML = `
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                        <tr><td>${escapeHtml(debitLabel)}</td><td class="text-end fw-bold">Tk ${amt.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td><td class="text-end text-muted">—</td></tr>
                        <tr><td>${escapeHtml(creditLabel)}</td><td class="text-end text-muted">—</td><td class="text-end fw-bold">Tk ${amt.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td></tr>
                    </tbody>
                </table>`;
        }

        async function loadDemandPreview() {
            if (!demandPreview) return;
            const type = typeSelect?.value || '';
            const amt = parseFloat(amountInput?.value) || 0;
            const toBranchEl = document.getElementById('to_branch_id');
            const toBranch = toBranchEl ? parseInt(toBranchEl.value, 10) : 0;

            if (!['cash_to_cash', 'cash_to_bank'].includes(type) || amt <= 0 || !toBranch || toBranch === currentBranchId) {
                demandPreview.innerHTML = '<p class="text-muted small mb-0">Select another branch and amount to preview demand settlement.</p>';
                return;
            }

            demandPreview.innerHTML = '<p class="text-muted small mb-0"><i class="fas fa-spinner fa-spin"></i> Loading demand preview…</p>';

            const body = new URLSearchParams({
                transfer_type: type,
                to_branch_id: String(toBranch),
                amount: String(amt),
            });

            try {
                const res = await fetch(base + 'MoneyTransfer/preview_settlement', { method: 'POST', body });
                const data = await parseJsonResponse(res);
                if (data.status === 'skipped' || !(data.preview_allocations || []).length) {
                    demandPreview.innerHTML = '<p class="text-muted small mb-0">No open branch demands to settle for this pair (or amount is unallocated).</p>';
                    return;
                }
                let rows = '';
                (data.preview_allocations || []).forEach((a) => {
                    rows += `<tr><td>${escapeHtml(a.demand_code || ('#' + a.demand_id))}</td><td class="text-end">${parseFloat(a.would_settle || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td></tr>`;
                });
                const unapplied = parseFloat(data.unapplied || 0);
                demandPreview.innerHTML = `
                    <p class="mb-2"><strong>FIFO preview</strong> — outstanding Tk ${parseFloat(data.total_outstanding || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</p>
                    <table class="table table-sm"><thead><tr><th>Demand</th><th class="text-end">Would settle</th></tr></thead><tbody>${rows}</tbody></table>
                    ${unapplied > 0.01 ? '<p class="small text-warning mb-0">Unapplied Tk ' + unapplied.toLocaleString('en-IN', { minimumFractionDigits: 2 }) + ' (transfer exceeds demands).</p>' : ''}`;
            } catch (e) {
                demandPreview.innerHTML = '<p class="text-danger small mb-0">Could not load demand preview.</p>';
            }
        }

        function scheduleDemandPreview() {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(loadDemandPreview, 350);
        }

        typeSelect?.addEventListener('change', () => {
            renderDynamicFields(typeSelect.value);
            updateAccountingPreview();
        });
        amountInput?.addEventListener('input', () => {
            updateAccountingPreview();
            scheduleDemandPreview();
        });
        document.addEventListener('change', (e) => {
            if (e.target && e.target.id === 'to_branch_id') scheduleDemandPreview();
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing…';
            }

            const fd = new FormData(form);
            if (!fd.has('csrf_token')) fd.append('csrf_token', csrfToken());

            try {
                const res = await fetch(base + 'MoneyTransfer/store', { method: 'POST', body: fd });
                const json = await parseJsonResponse(res);
                if (json.status === 'success') {
                    Swal.fire({ title: 'Success', text: json.message, icon: 'success', timer: 1800 }).then(() => {
                        window.location.href = json.redirect_url || (base + 'MoneyTransfer/details/' + (json.transfer_id || ''));
                    });
                } else {
                    Swal.fire('Error', json.message || 'Could not save transfer', 'error');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Transfer';
                    }
                }
            } catch (err) {
                Swal.fire('Network Error', 'Please try again.', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Transfer';
                }
            }
        });
    }
})();