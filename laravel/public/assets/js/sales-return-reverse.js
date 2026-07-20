/**
 * Sales return reverse — confirmation guard
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('srReverseForm');
        if (!form) return;

        const boot = window.SR_REVERSE_BOOT || {};

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const reason = (document.getElementById('reverse_reason')?.value || '').trim();
            if (reason.length < 5) {
                Swal.fire('Reason required', 'Enter at least 5 characters explaining the reversal.', 'warning');
                return;
            }

            const stockNote = boot.stockLineCount
                ? '<li>Remove <strong>' + boot.stockLineCount + '</strong> warehouse stock IN line(s)</li>'
                : '';
            const completedNote = boot.isCompleted
                ? '<li>Reverse GL journal (sales return revenue + COGS/inventory)</li>'
                  + '<li>Debit customer balance (restore AR)</li>'
                  + stockNote
                : '<li>Cancel pending return (no stock/GL was posted)</li>';

            Swal.fire({
                title: 'Reverse this return?',
                html:
                    '<p class="mb-2">You are reversing <strong>' + (boot.returnCode || 'this return') + '</strong>'
                    + (boot.totalFormatted ? ' (' + boot.totalFormatted + ')' : '') + '.</p>'
                    + '<ul class="text-start small mb-0">' + completedNote
                    + '<li>Marked reversed — cannot undo</li></ul>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, reverse',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
})();