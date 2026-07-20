// StockTake.js — physical count (workflow B: save then post)

function stBaseUrl() {
    if (window.ST_BOOT?.baseUrl) {
        let u = window.ST_BOOT.baseUrl;
        return u.endsWith('/') ? u : u + '/';
    }
    const baseInput = document.getElementById('base_url');
    let u = baseInput ? baseInput.value : '/remote-center-erp/public/';
    return u.endsWith('/') ? u : u + '/';
}

function parseJsonPayload(data) {
    if (data && typeof data === 'object' && data.data !== undefined && data.status !== undefined) {
        return data.data;
    }
    return data;
}

let BASE_URL = '';

document.addEventListener('DOMContentLoaded', () => {
    BASE_URL = stBaseUrl();

    const branchSelect = document.getElementById('branch_id');
    if (branchSelect) {
        branchSelect.addEventListener('change', loadBranchWarehouses);
        if (branchSelect.value) {
            loadBranchWarehouses();
        }
    }

    initCreateForm();
    initPostButton();
    initReverseButtons();
});

function initReverseButtons() {
    document.querySelectorAll('.js-st-reverse').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.sessionId, 10);
            const code = btn.dataset.sessionCode || '';
            if (!id) return;
            reverseStockTake(id, code);
        });
    });
}

function loadBranchWarehouses() {
    const branchId = document.getElementById('branch_id')?.value;
    const container = document.getElementById('warehouse_list');
    if (!container) return;

    if (!branchId) {
        container.innerHTML = '<span class="text-muted small">Select a branch first</span>';
        return;
    }

    fetch(`${BASE_URL}StockTake/WarehousesByBranch?branch_id=${branchId}`)
        .then((r) => r.json())
        .then((raw) => {
            const data = parseJsonPayload(raw) ?? raw;
            const list = Array.isArray(data) ? data : [];
            if (!list.length) {
                container.innerHTML = '<p class="text-muted mb-0">No warehouses for this branch</p>';
                return;
            }
            container.innerHTML = list
                .map(
                    (w) => `
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="warehouse_ids[]" value="${w.id}" id="w${w.id}">
                    <label class="form-check-label" for="w${w.id}">${w.warehouse_name}</label>
                </div>`
                )
                .join('');
        })
        .catch(() => {
            container.innerHTML = '<p class="text-danger mb-0">Failed to load warehouses</p>';
        });
}

function initCreateForm() {
    const form = document.getElementById('stockTakeForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const checked = document.querySelectorAll('input[name="warehouse_ids[]"]:checked');
        if (!checked.length) {
            Swal.fire('Error', 'Select at least one warehouse', 'error');
            return;
        }

        try {
            const res = await fetch(`${BASE_URL}StockTake/store`, { method: 'POST', body: new FormData(form) });
            const raw = await res.json();
            const data = parseJsonPayload(raw) ?? raw;
            if (data.status === 'success') {
                Swal.fire('Started', `Session ${data.session_code || ''} created`, 'success').then(
                    () => (window.location.href = `${BASE_URL}StockTake/details/${data.session_id}`)
                );
            } else {
                Swal.fire('Error', data.message || 'Failed', 'error');
            }
        } catch {
            Swal.fire('Error', 'Network error', 'error');
        }
    });
}

function initPostButton() {
    const root = document.getElementById('stockTakeDetails');
    if (!root) return;

    const runPost = (sessionId) => {
        Swal.fire({
            title: 'Finalize session?',
            html: `<p>Applies <strong>all warehouses</strong> to stock (avg cost) and posts GL (shrinkage / surplus).</p>
                   <p class="small text-muted mb-0">Use this after every warehouse is marked complete.</p>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            confirmButtonText: 'Post now',
        }).then(async (result) => {
            if (!result.isConfirmed) return;
            try {
                const res = await fetch(`${BASE_URL}StockTake/post`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${sessionId}`,
                });
                const text = await res.text();
                const raw = JSON.parse(text);
                const data = parseJsonPayload(raw) ?? raw;
                if (data.status === 'success') {
                    Swal.fire('Posted', data.message || 'Done', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                }
            } catch (err) {
                Swal.fire('Error', err.message || 'Network error', 'error');
            }
        });
    };

    const sessionId = root.dataset.sessionId;
    document.getElementById('btnPostSession')?.addEventListener('click', () => runPost(sessionId));
    document.getElementById('btnPostSessionBanner')?.addEventListener('click', () => runPost(sessionId));
}

function reverseStockTake(id, code) {
    Swal.fire({
        title: `Reverse ${code}?`,
        html: `<p>Undoes posted stock adjustments and restores quantities via reversal movements.</p>
               <p class="small text-muted mb-0">If stock was sold or transferred after the take, reversing a <strong>surplus</strong> line may fail until enough quantity is on hand.</p>`,
        icon: 'warning',
        input: 'textarea',
        inputLabel: 'Reason (required)',
        inputValidator: (v) => (!v || v.trim().length < 3 ? 'Enter at least 3 characters' : undefined),
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Reverse',
    }).then(async (result) => {
        if (!result.isConfirmed) return;
        try {
            const res = await fetch(`${BASE_URL}StockTake/reverse`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${id}&reason=${encodeURIComponent(result.value)}`,
                credentials: 'same-origin',
            });
            const text = await res.text();
            let raw;
            try {
                raw = JSON.parse(text);
            } catch {
                throw new Error('Invalid server response. Check PHP error log.');
            }
            const data = parseJsonPayload(raw) ?? raw;
            if (data.status === 'success') {
                Swal.fire('Reversed', data.message || 'Stock restored via audit trail.', 'success').then(
                    () => location.reload()
                );
            } else {
                Swal.fire('Could not reverse', data.message || 'Failed', 'error');
            }
        } catch (err) {
            Swal.fire('Could not reverse', err.message || 'Network error', 'error');
        }
    });
}

function deleteDraftSession(id) {
    Swal.fire({
        title: 'Delete draft session?',
        text: 'This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Delete',
    }).then((result) => {
        if (!result.isConfirmed) return;
        fetch(`${BASE_URL}StockTake/delete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`,
        })
            .then((r) => r.json())
            .then((raw) => {
                const data = parseJsonPayload(raw) ?? raw;
                if (data.status === 'success') {
                    Swal.fire('Deleted', '', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                }
            });
    });
}