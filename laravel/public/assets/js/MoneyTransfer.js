/**
 * Money transfers — reverse functionality.
 *
 * Index page uses client-side DataTables (inline JS in the blade).
 * Create page uses inline JS with proper Laravel routes (not legacy URLs).
 * This file only handles the reverse action.
 */
(function () {
    'use strict';

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function reverseTransfer(id, code) {
        const routes = window.MT_BOOT?.routes || {};
        const reverseUrl = (routes.reverse || '').replace('__ID__', id);

        if (!reverseUrl) {
            Swal.fire('Error', 'Reverse route not configured. Refresh the page.', 'error');
            return;
        }

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

            try {
                const res = await fetch(reverseUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: new URLSearchParams({
                        reverse_reason: reason,
                    }).toString(),
                });

                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    Swal.fire('Error', 'Invalid server response (HTTP ' + res.status + ').', 'error');
                    return;
                }

                if (data.status === 'success') {
                    Swal.fire('Reversed', data.message || 'Transfer reversed.', 'success').then(() => {
                        window.location.reload();
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
        // Delegate reverse button clicks
        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-mt-reverse');
            if (!btn) return;
            reverseTransfer(btn.dataset.transferId, btn.dataset.transferCode);
        });
    });
})();
