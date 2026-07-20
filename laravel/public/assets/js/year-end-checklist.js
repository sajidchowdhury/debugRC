(function () {
    'use strict';

    const btn = document.getElementById('btnRefreshYearEnd');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const year = document.getElementById('ye_year')?.value || new Date().getFullYear();
        const branchId = document.getElementById('ye_branch_id')?.value || '';
        const base = (document.getElementById('base_url') || {}).value || '/';

        btn.disabled = true;
        try {
            const qs = new URLSearchParams({ year, branch_id: branchId });
            const res = await fetch(base + 'AccountingPeriod/run_year_end_checks?' + qs.toString(), {
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (data.status === 'success') {
                window.location.reload();
                return;
            }
            alert(data.message || 'Could not refresh checklist.');
        } catch (e) {
            alert('Network error.');
        } finally {
            btn.disabled = false;
        }
    });
})();
