(function () {
    'use strict';

    const base = (document.getElementById('base_url') || {}).value || '/';
    const csrf = document.querySelector('[name="csrf_token"]')?.value || window.CSRF_TOKEN || '';

    const closeForm = document.getElementById('periodCloseForm');
    closeForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(closeForm);
        fd.append('csrf_token', csrf);
        const btn = closeForm.querySelector('[type="submit"]');
        btn.disabled = true;
        try {
            const res = await fetch(base + 'AccountingPeriod/close', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.status === 'success') {
                window.location.reload();
                return;
            }
            alert(data.message || 'Could not close period.' + (data.checklist ? '\n\nReview the year-end checklist for details.' : ''));
        } catch (err) {
            alert('Network error.');
        } finally {
            btn.disabled = false;
        }
    });

    document.querySelectorAll('.js-reopen-period').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const branchId = btn.getAttribute('data-branch-id');
            if (!confirm('Remove period lock for this branch? Posting to closed dates will be allowed again.')) {
                return;
            }
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('branch_id', branchId);
            btn.disabled = true;
            try {
                const res = await fetch(base + 'AccountingPeriod/reopen', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await res.json();
                if (data.status === 'success') {
                    window.location.reload();
                    return;
                }
                alert(data.message || 'Could not reopen period.');
            } catch (err) {
                alert('Network error.');
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
