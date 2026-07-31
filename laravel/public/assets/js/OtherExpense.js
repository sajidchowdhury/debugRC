/**
 * Other expense — index, reverse.
 *
 * NOTE: initCreate() removed — the blade template's inline JS handles
 * form submission with the correct Laravel route. The old initCreate()
 * used a legacy URL (OtherExpense/store) which caused HTTP 404.
 */
(function () {
    'use strict';

    function baseUrl() {
        if (window.OE_BOOT?.baseUrl) {
            const u = window.OE_BOOT.baseUrl;
            return u.endsWith('/') ? u : u + '/';
        }
        const el = document.getElementById('base_url');
        const u = el ? el.value : '/';
        return u.endsWith('/') ? u : u + '/';
    }

    function csrf() {
        const el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
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

    function reverseExpense(id, code) {
        const b = baseUrl();
        Swal.fire({
            title: 'Reverse expense ' + (code || '#' + id) + '?',
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
                // Use the Laravel route from OE_BOOT instead of legacy URL
                const reverseUrl = window.OE_BOOT?.routes?.reverse
                    ? window.OE_BOOT.routes.reverse.replace('{id}', String(id))
                    : b + 'admin/other-expenses/' + id + '/reverse';
                const res = await fetch(reverseUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf() },
                    body: body.toString(),
                });
                const data = await parseJson(res);
                if (data.status === 'success') {
                    Swal.fire('Reversed', data.message, 'success').then(() => {
                        window.location.href = data.redirect_url || (b + 'admin/other-expenses/' + id);
                    });
                } else {
                    Swal.fire('Error', data.message || 'Could not reverse', 'error');
                }
            } catch (e) {
                Swal.fire('Network Error', 'Please try again.', 'error');
            }
        });
    }

    window.reverseExpense = reverseExpense;

    document.addEventListener('DOMContentLoaded', () => {
        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-oe-reverse');
            if (!btn) return;
            reverseExpense(btn.dataset.expenseId, btn.dataset.expenseCode);
        });

        initIndex();
    });

    function initIndex() {
        const tableEl = document.getElementById('oeTable');
        if (!tableEl || typeof $ === 'undefined' || !$.fn.DataTable) return;

        // Only initialize DataTable on the Blade-rendered table if there are data rows
        const hasDataRows = tableEl.querySelector('tbody tr td:not([colspan])');
        if (!hasDataRows) return;

        // Destroy any existing DataTable instance
        if ($.fn.DataTable.isDataTable('#oeTable')) {
            $('#oeTable').DataTable().destroy();
        }

        $('#oeTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { emptyTable: 'No other expenses for selected filters', search: 'Quick search:' },
        });
    }
})();
