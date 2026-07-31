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
        const tableEl = document.getElementById('oiTable');
        if (!tableEl || typeof $ === 'undefined' || !$.fn.DataTable) return;

        // Only initialize DataTable on the Blade-rendered table if there are data rows
        const hasDataRows = tableEl.querySelector('tbody tr td:not([colspan])');
        if (!hasDataRows) return;

        // Destroy any existing DataTable instance
        if ($.fn.DataTable.isDataTable('#oiTable')) {
            $('#oiTable').DataTable().destroy();
        }

        const dt = $('#oiTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { emptyTable: 'No other incomes for selected filters', search: 'Quick search:' },
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