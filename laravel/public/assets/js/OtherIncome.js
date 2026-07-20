/**
 * Other income — index, create, reverse.
 */
(function () {
    'use strict';

    function baseUrl() {
        if (window.OI_BOOT?.baseUrl) {
            const u = window.OI_BOOT.baseUrl;
            return u.endsWith('/') ? u : u + '/';
        }
        const el = document.getElementById('base_url');
        const u = el ? el.value : '/';
        return u.endsWith('/') ? u : u + '/';
    }

    function csrf() {
        const el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    async function parseJson(res) {
        const text = await res.text();
        if (!text) return { status: 'error', message: 'Empty response (HTTP ' + res.status + ')' };
        try {
            return JSON.parse(text);
        } catch (e) {
            return { status: 'error', message: 'Invalid server response (HTTP ' + res.status + ')' };
        }
    }

    function esc(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function reverseIncome(id, code) {
        const b = baseUrl();
        Swal.fire({
            title: 'Reverse income ' + (code || '#' + id) + '?',
            html: '<p class="small text-start mb-0">Posts a reversing GL entry and restores cash/bank balances.</p>',
            input: 'textarea',
            inputLabel: 'Reason (required, min 3 characters)',
            showCancelButton: true,
            confirmButtonColor: '#d33',
        }).then(async (r) => {
            if (!r.isConfirmed) return;
            const reason = (r.value || '').trim();
            if (reason.length < 3) {
                Swal.fire('Error', 'Reversal reason is required.', 'error');
                return;
            }
            const body = new URLSearchParams({ id: String(id), reason, csrf_token: csrf() });
            try {
                const res = await fetch(b + 'OtherIncome/reverse', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                const data = await parseJson(res);
                if (data.status === 'success') {
                    Swal.fire('Reversed', data.message, 'success').then(() => {
                        window.location.href = data.redirect_url || (b + 'OtherIncome/details/' + id);
                    });
                } else {
                    Swal.fire('Error', data.message || 'Could not reverse', 'error');
                }
            } catch (e) {
                Swal.fire('Network Error', 'Please try again.', 'error');
            }
        });
    }

    window.reverseIncome = reverseIncome;

    document.addEventListener('DOMContentLoaded', () => {
        const b = baseUrl();
        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-oi-reverse');
            if (!btn) return;
            reverseIncome(btn.dataset.incomeId, btn.dataset.incomeCode);
        });

        initIndex(b);
        initCreate(b);
    });

    function initIndex(b) {
        const tableEl = document.getElementById('incomeTable');
        if (!tableEl || typeof $ === 'undefined' || !$.fn.DataTable) return;

        const showReversed = !!window.showReversed;
        const today = new Date().toISOString().split('T')[0];
        if (showReversed) {
            const fs = document.getElementById('filterStatus');
            if (fs) { fs.value = 'reversed'; fs.disabled = true; }
        }

        const table = $('#incomeTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: b + 'OtherIncome' + (showReversed ? '?reversed=1' : ''),
                data(d) {
                    d.fromDate = $('#fromDate').val();
                    d.toDate = $('#toDate').val();
                    d.filterLedger = $('#filterLedger').val();
                    d.filterPaymentMode = $('#filterPaymentMode').val();
                    d.filterStatus = $('#filterStatus').val();
                    if (showReversed) d.reversedMode = 'only_reversed';
                },
            },
            pageLength: 25,
            order: [[0, 'desc']],
            dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
            buttons: ['copy', 'excel', 'pdf'],
            drawCallback() { renderCards(this.api()); },
            columns: [
                { data: 'income_date', render: (d) => new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) },
                { data: 'income_code' },
                { data: 'ledger_name' },
                { data: 'amount', className: 'text-end fw-bold', render: (d) => 'Tk ' + parseFloat(d || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
                { data: null, render: (_d, _t, row) => row.payment_mode === 'bank' ? esc(row.bank_name || 'Bank') : 'Cash' },
                { data: 'is_reversed', render: (d) => d == 1
                    ? '<span class="branch-status-pill inactive"><span class="dot"></span> Reversed</span>'
                    : '<span class="branch-status-pill active"><span class="dot"></span> Active</span>' },
                { data: 'id', orderable: false, className: 'text-center', render: (id, _t, row) => {
                    let h = '<a href="' + b + 'OtherIncome/details/' + id + '" class="btn-action view"><i class="fas fa-eye"></i></a>';
                    if (!showReversed && row.is_reversed == 0) {
                        h += ' <button type="button" class="btn-action toggle-off js-oi-reverse-index" data-income-id="' + id + '" data-income-code="' + esc(row.income_code || '') + '"><i class="fas fa-undo"></i></button>';
                    }
                    return h;
                }},
            ],
        });

        tableEl.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-oi-reverse-index');
            if (btn) reverseIncome(btn.dataset.incomeId, btn.dataset.incomeCode);
        });

        $('#filterLedger, #filterPaymentMode, #filterStatus, #fromDate, #toDate').on('change', () => table.ajax.reload());
        $('#clearFilters').on('click', () => {
            $('#filterLedger, #filterPaymentMode, #filterStatus').val('');
            $('#fromDate, #toDate').val(today);
            table.ajax.reload();
        });
    }

    function renderCards(api) {
        const c = document.getElementById('incomeCards');
        if (!c || window.innerWidth >= 768) { if (c) c.innerHTML = ''; return; }
        const b = baseUrl();
        const showReversed = !!window.showReversed;
        const data = api.rows({ page: 'current' }).data();
        let html = data.length ? '' : '<div class="text-center text-muted py-4">No records for selected filters.</div>';
        data.each((row) => {
            const amt = 'Tk ' + parseFloat(row.amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
            let actions = '<a href="' + b + 'OtherIncome/details/' + row.id + '" class="btn-action view">View</a>';
            if (!showReversed && row.is_reversed == 0) {
                actions += ' <button type="button" class="btn-action toggle-off js-oi-reverse-index" data-income-id="' + row.id + '" data-income-code="' + esc(row.income_code || '') + '"><i class="fas fa-undo"></i></button>';
            }
            html += `<article class="cust-txn-mobile-card"><strong>${esc(row.income_code)}</strong> · ${amt}<div class="mt-2 small">${esc(row.ledger_name)}</div><div class="mt-2">${actions}</div></article>`;
        });
        c.innerHTML = html;
        c.querySelectorAll('.js-oi-reverse-index').forEach((btn) => {
            btn.addEventListener('click', () => reverseIncome(btn.dataset.incomeId, btn.dataset.incomeCode));
        });
    }

    function initCreate(b) {
        const form = document.getElementById('otherIncomeForm');
        if (!form) return;

        const preview = document.getElementById('accounting_preview');
        const amountEl = document.getElementById('amount');
        const ledgerEl = document.getElementById('ledger_id');
        const bankSection = document.getElementById('bank_section');

        document.querySelectorAll('input[name="payment_mode"]').forEach((r) => {
            r.addEventListener('change', () => {
                if (bankSection) bankSection.style.display = r.value === 'bank' ? 'block' : 'none';
                updatePreview();
            });
        });
        [amountEl, ledgerEl].forEach((el) => el?.addEventListener('input', updatePreview));
        [amountEl, ledgerEl].forEach((el) => el?.addEventListener('change', updatePreview));

        function updatePreview() {
            if (!preview) return;
            const amt = parseFloat(amountEl?.value) || 0;
            const head = ledgerEl?.selectedOptions?.[0]?.text || 'Income head';
            const mode = document.querySelector('input[name="payment_mode"]:checked')?.value || 'cash';
            if (amt <= 0 || !ledgerEl?.value) {
                preview.innerHTML = '<p class="text-muted small mb-0">Select head and amount to preview GL effect.</p>';
                return;
            }
            const cashBank = mode === 'bank' ? 'Bank' : 'Cash';
            preview.innerHTML = `<table class="table table-sm mb-0"><thead><tr><th>Account</th><th class="text-end">Dr</th><th class="text-end">Cr</th></tr></thead><tbody>
                <tr><td>${esc(cashBank)}</td><td class="text-end fw-bold">${amt.toFixed(2)}</td><td class="text-end">—</td></tr>
                <tr><td>${esc(head)}</td><td class="text-end">—</td><td class="text-end fw-bold">${amt.toFixed(2)}</td></tr>
            </tbody></table>`;
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving…'; }
            const fd = new FormData(form);
            if (!fd.has('csrf_token')) fd.append('csrf_token', csrf());
            try {
                const res = await fetch(b + 'OtherIncome/store', { method: 'POST', body: fd });
                const data = await parseJson(res);
                if (data.status === 'success') {
                    Swal.fire({ title: 'Saved', text: data.message, icon: 'success', timer: 1600 }).then(() => {
                        window.location.href = data.redirect_url || (b + 'OtherIncome');
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i> Save Income'; }
                }
            } catch (err) {
                Swal.fire('Network Error', 'Please try again.', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i> Save Income'; }
            }
        });
    }
})();