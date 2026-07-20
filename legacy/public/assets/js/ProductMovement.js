let BASE_URL = '';

document.addEventListener('DOMContentLoaded', function () {
    const baseInput = document.getElementById('base_url');
    BASE_URL = baseInput ? baseInput.value : '/remote-center-erp/public/';

    // Initialize Select2
    $('#product_id').select2({ placeholder: '-- Select Product --', allowClear: true });
    $('#branch_ids').select2({ placeholder: 'Select Branches', multiple: true });
    $('#warehouse_ids').select2({ placeholder: 'Select Warehouses', multiple: true });

    loadProducts();
    loadBranches();
    loadWarehouses();

    // Report Type Change
    $('#report_type').on('change', function () {
        if ($(this).val() === 'warehouse') {
            $('#warehouse_group').show();
            $('#branch_group').hide();
        } else {
            $('#warehouse_group').hide();
            $('#branch_group').show();
        }
    });

    $(".date-btn").click(function () {
        $(".date-btn").removeClass("active");
        $(this).addClass("active");
        if ($(this).data("range") === "custom") $("#customDate").show();
        else $("#customDate").hide();
    });

    $("#btnGenerate").click(generateReport);

    // Initial state
    $('#report_type').trigger('change');
});

function loadWarehouses() {
    fetch(BASE_URL + 'Report/getWarehouses')
        .then(r => r.json())
        .then(data => {
            let options = '<option value="">-- All Warehouses --</option>';
            data.forEach(p => options += `<option value="${p.id}">${p.warehouse_code} - ${p.warehouse_name}</option>`);
            $("#warehouse_ids").html(options);
        });
}

function loadProducts() {
    fetch(BASE_URL + 'Report/getProducts')
        .then(r => r.json())
        .then(data => {
            let options = '<option value="">-- Select Product --</option>';
            data.forEach(p => options += `<option value="${p.id}">${p.product_code} - ${p.product_name}</option>`);
            $("#product_id").html(options);
        });
}

function loadBranches() {
    fetch(BASE_URL + 'Report/getBranches')
        .then(r => r.json())
        .then(data => {
            let options = '<option value="">-- All Branches --</option>';
            data.forEach(b => options += `<option value="${b.id}">${b.branch_name}</option>`);
            $("#branch_ids").html(options);
        });
}

function generateReport() {
    const formData = {
        product_id: $("#product_id").val(),
        report_type: $("#report_type").val(),
        branch_ids: $("#branch_ids").val() || [],
        warehouse_ids: $("#warehouse_ids").val() || [],
        from_date: $("#from_date").val(),
        to_date: $("#to_date").val()
    };

    if (!formData.product_id) {
        Swal.fire('Error', 'Please select a product', 'error');
        return;
    }

    fetch(BASE_URL + 'Report/getProductMovement', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(formData)
    })
    .then(async r => {
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Raw response:', text);
            throw new Error('Invalid server response');
        }
    })
    .then(res => {
        if (res.status === 'success') {
            renderSummary(res);
            renderTable(res.movements);
        } else {
            Swal.fire('Error', res.message || 'Something went wrong', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Failed to load report', 'error');
    });
}

function renderSummary(res) {
    const html = `
        <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="text-muted">Opening Balance</h6>
            <h3 class="mb-0">৳ ${parseFloat(res.opening || 0).toLocaleString('en-IN')}</h3>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="text-muted">Total In</h6>
            <h3 class="text-success mb-0">৳ ${parseFloat(res.total_in || 0).toLocaleString('en-IN')}</h3>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="text-muted">Total Out</h6>
            <h3 class="text-danger mb-0">৳ ${parseFloat(res.total_out || 0).toLocaleString('en-IN')}</h3>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="text-muted">Closing Balance</h6>
            <h3 class="mb-0">৳ ${parseFloat(res.closing || 0).toLocaleString('en-IN')}</h3>
        </div></div></div>`;
    $("#summaryCards").html(html);
}

function renderTable(data) {
    const tableId = '#movementTable';
    const $table = $(tableId);

    if (!data || !Array.isArray(data)) {
        $table.find('tbody').html(`<tr><td colspan="8" class="text-center py-4">No movement found</td></tr>`);
        return;
    }

    if ($.fn.DataTable && $.fn.DataTable.isDataTable(tableId)) {
        $table.DataTable().destroy();
    }
    $table.find('tbody').empty();

    let html = '';
    data.forEach((row, index) => {
        html += `
        <tr>
            <td>${index + 1}</td>
            <td>${row.movement_date || '-'}</td>
            <td>${row.user || 'System'}</td>
            <td>${row.remarks || '-'}</td>
            <td class="text-end text-success fw-bold">${parseFloat(row.in_qty || 0).toLocaleString('en-IN')}</td>
            <td class="text-end text-danger fw-bold">${parseFloat(row.out_qty || 0).toLocaleString('en-IN')}</td>
            <td class="text-end fw-bold">${parseFloat(row.balance || 0).toLocaleString('en-IN')}</td>
           
        </tr>`;
    });

    $table.find('tbody').html(html);

    setTimeout(() => {
        $table.DataTable({
            "destroy": true,
            "pageLength": 25,
            "order": [[1, "desc"]],
            "dom": 'Bfrtip',
            "buttons": ['excel', 'pdf', 'copy']
        });
    }, 10);
}

// Add this after $("#btnGenerate").click(generateReport);

// Replace old export handler with this
$("#btnExport").click(function () {
    const formData = {
        product_id: $("#product_id").val(),
        report_type: $("#report_type").val(),
        branch_ids: $("#branch_ids").val() || [],
        warehouse_ids: $("#warehouse_ids").val() || [],
        from_date: $("#from_date").val(),
        to_date: $("#to_date").val()
    };

    if (!formData.product_id) {
        Swal.fire('Error', 'Please select a product', 'error');
        return;
    }

    window.location.href = BASE_URL + 'Report/exportProductMovement?' + $.param(formData);
});