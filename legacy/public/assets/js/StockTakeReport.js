let BASE_URL = '';

document.addEventListener('DOMContentLoaded', function () {
    BASE_URL = document.getElementById('base_url')?.value || '/remote-center-erp/public/';

    // Initialize Select2
    $('#session_id').select2({ placeholder: '-- All Sessions --', allowClear: true });
    $('#branch_id').select2({ placeholder: '-- All Branches --', allowClear: true });
    $('#warehouse_id').select2({ placeholder: '-- All Warehouses --', allowClear: true });
    $('#product_id').select2({ placeholder: '-- All Products --', allowClear: true });

    // Load all dropdowns
    loadSessions();
    loadBranches();
    loadWarehouses();
    loadProducts();

    $("#btnGenerate").click(generateReport);
    $("#btnExport").click(exportCSV);
});

// ================== DROPDOWN LOADERS ==================
/** Extract list payload from ApiResponse wrapper (status + data[]). */
function unwrapList(raw) {
    if (Array.isArray(raw)) return raw;
    if (Array.isArray(raw?.data)) return raw.data;
    return null;
}

function loadSessions() {
    fetch(BASE_URL + 'StockTake/getSessionsList', { credentials: 'same-origin' })
        .then(async response => {
            let text = await response.text(); // get raw response

            if (!response.ok) {
                console.error("Server Error Response:", text);

                throw new Error(text || 'Unknown server error');
            }

            try {
                return JSON.parse(text); // try parse JSON
            } catch (e) {
                console.error("Invalid JSON:", text);
                throw new Error("Invalid JSON response from server");
            }
        })
        .then((raw) => {
            const data = unwrapList(raw);
            if (!Array.isArray(data)) {
                throw new Error('Invalid sessions list');
            }

            const preselect = new URLSearchParams(window.location.search).get('session_id') || '';
            let html = '<option value="">-- All Sessions --</option>';
            data.forEach(s => {
                const sel = preselect && String(s.id) === String(preselect) ? ' selected' : '';
                html += `<option value="${s.id}"${sel}>${s.session_code} - ${s.take_date}</option>`;
            });

            $("#session_id").html(html);
            if (preselect) {
                $("#session_id").trigger('change');
                generateReport();
            }
        })
        .catch(err => {
            console.error("Full Error:", err);

            Swal.fire({
                title: 'Error',
                text: err.message, // 👈 now shows actual server message
                icon: 'error'
            });
        });
}

function loadBranches() {
    fetch(BASE_URL + 'Report/getBranches')
        .then(r => r.json())
        .then(data => {
            let html = '<option value="">-- All Branches --</option>';
            data.forEach(b => html += `<option value="${b.id}">${b.branch_name}</option>`);
            $("#branch_id").html(html);
        });
}

function loadWarehouses() {
    fetch(BASE_URL + 'Report/getWarehouses')
        .then(r => r.json())
        .then(data => {
            let html = '<option value="">-- All Warehouses --</option>';
            data.forEach(w => html += `<option value="${w.id}">${w.warehouse_name}</option>`);
            $("#warehouse_id").html(html);
        });
}

function loadProducts() {
    fetch(BASE_URL + 'Report/getProducts')
        .then(r => r.json())
        .then(data => {
            let html = '<option value="">-- All Products --</option>';
            data.forEach(p => html += `<option value="${p.id}">${p.product_code} - ${p.product_name}</option>`);
            $("#product_id").html(html);
        });
}

// ================== REPORT FUNCTIONS ==================
function generateReport() {
    const formData = {
        session_id: $("#session_id").val(),
        branch_id: $("#branch_id").val(),
        warehouse_id: $("#warehouse_id").val(),
        product_id: $("#product_id").val()
    };

    fetch(BASE_URL + 'StockTake/getVarianceReport', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(formData)
    })
    .then(async response => {
        const text = await response.text();
        console.log("Raw Response:", text);   // ← Very useful for debugging
        return JSON.parse(text);
    })
    .then((raw) => {
        if (raw.status === 'success') {
            renderSummary(raw);
            renderTable(Array.isArray(raw.data) ? raw.data : []);
        } else {
            Swal.fire('Error', raw.message || 'No data', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Server Error', 'Please check console for details', 'error');
    });
}

function exportCSV() {
    const formData = {
        session_id: $("#session_id").val(),
        branch_id: $("#branch_id").val(),
        warehouse_id: $("#warehouse_id").val(),
        product_id: $("#product_id").val()
    };
    window.location.href = BASE_URL + 'StockTake/exportVarianceReport?' + $.param(formData);
}


function renderSummary(res) {
    const html = `
        <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="text-muted">Total Items Counted</h6>
            <h3>${res.total_items || 0}</h3>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="text-muted">Total Variance (Qty)</h6>
            <h3 class="${(res.total_variance || 0) >= 0 ? 'text-success' : 'text-danger'}">${res.total_variance || 0}</h3>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="text-muted">Total Value Diff</h6>
            <h3 class="${(res.total_value_diff || 0) >= 0 ? 'text-success' : 'text-danger'}">৳ ${parseFloat(res.total_value_diff || 0).toLocaleString('en-IN')}</h3>
        </div></div></div>`;
    $("#summaryCards").html(html);
}

function renderTable(data) {
    const tableId = '#stockTakeTable';
    
    // Destroy existing DataTable if exists
    if ($.fn.DataTable.isDataTable(tableId)) {
        $(tableId).DataTable().destroy();
    }

    let html = '';
    data.forEach((row, i) => {
        const variance = parseFloat(row.variance_qty ?? row.variance ?? (row.physical_qty - row.system_qty) || 0);
        const valueDiff = parseFloat(row.value_diff || 0);

        html += `<tr>
            <td>${i+1}</td>
            <td>${row.product_code || ''}</td>
            <td>${row.product_name || ''}</td>
            <td>${row.warehouse_name || ''}</td>
            <td class="text-end">${parseFloat(row.system_qty || 0).toLocaleString('en-IN')}</td>
            <td class="text-end fw-bold">${parseFloat(row.physical_qty || 0).toLocaleString('en-IN')}</td>
            <td class="text-end ${variance >= 0 ? 'text-success' : 'text-danger'} fw-bold">${variance}</td>
            <td class="text-end ${valueDiff >= 0 ? 'text-success' : 'text-danger'}">৳ ${valueDiff.toLocaleString('en-IN')}</td>
            <td>
                <span class="badge bg-${Math.abs(variance) < 0.01 ? 'success' : 'warning'}">
                    ${Math.abs(variance) < 0.01 ? 'Matched' : 'Variance'}
                </span>
            </td>
        </tr>`;
    });

    const tbody = $(tableId + ' tbody');
    tbody.html(html || '<tr><td colspan="9" class="text-center py-4">No physical count data found</td></tr>');

    // Initialize DataTable safely
    setTimeout(() => {
        try {
            $(tableId).DataTable({
                pageLength: 25,
                order: [[1, "asc"]],
                dom: 'Bfrtip',
                buttons: ['excel', 'pdf', 'copy'],
                destroy: true
            });
        } catch (e) {
            console.error("DataTable init failed:", e);
        }
    }, 100);
}