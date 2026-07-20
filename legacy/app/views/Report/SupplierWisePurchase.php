<?php
// views/report/SupplierWisePurchase.php

$a = new Helper();

$content = '
<div class="card shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-truck me-2"></i> Supplier Wise Purchase Report
        </h5>
        <button class="btn btn-sm btn-outline-secondary" data-toggle="collapse" href="#filterPanel">
            <i class="fas fa-chevron-down"></i>
        </button>
    </div>

    <div id="filterPanel" class="collapse show">
        <div class="card-body">
            <form method="get">
                <div class="row g-3">

                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" class="form-control" value="' . htmlspecialchars($from_date) . '">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to_date" class="form-control" value="' . htmlspecialchars($to_date) . '">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Branch (Multiple)</label>
                        <select name="branch_ids[]" class="form-select select2" multiple>';

foreach($branches as $b) {
    $selected = in_array($b['id'], $branch_ids ?? []) ? 'selected' : '';
    $content .= '
                            <option value="' . $b['id'] . '" ' . $selected . '>' . htmlspecialchars($b['branch_name']) . '</option>';
}

$content .= '
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-select select2">
                            <option value="">All Suppliers</option>';

foreach($suppliers as $s) {
    $selected = ($supplier_id == $s['id']) ? 'selected' : '';
    $content .= '
                            <option value="' . $s['id'] . '" ' . $selected . '>' . htmlspecialchars($s['supplier_name']) . '</option>';
}

$content .= '
                        </select>
                    </div>

                    <div class="col-12 d-flex gap-2 mt-3">
                        <button type="submit" name="search" value="1" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button type="submit" name="export" value="1" class="btn btn-success">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>
</div>
';

if (isset($purchase_data) && !empty($purchase_data)) {

    $content .= '
<div class="card shadow-sm mt-4">
    <div class="card-header bg-dark text-white">
        <strong>Supplier Wise Purchase Summary</strong>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Supplier Code</th>
                    <th>Supplier Name</th>
                    <th class="text-end">Total GRN</th>
                    <th class="text-end">Total Purchase Value</th>
                    <th>Last Purchase</th>
                </tr>
            </thead>
            <tbody>';

    foreach($purchase_data as $row) {
        $content .= '
                <tr>
                    <td>' . htmlspecialchars($row['supplier_code']) . '</td>
                    <td>' . htmlspecialchars($row['supplier_name']) . '</td>
                    <td class="text-end">' . $row['total_grn'] . '</td>
                    <td class="text-end">' . number_format($row['total_purchase_value'], 2) . '</td>
                    <td>' . htmlspecialchars($row['last_purchase_date'] ?? 'N/A') . '</td>
                </tr>';
    }

    $content .= '
            </tbody>
        </table>
    </div>
</div>';
} 
elseif (isset($_GET['search'])) {
    $content .= '
<div class="alert alert-info mt-4">No purchase records found for the selected criteria.</div>';
}

$content .= '
<script>
$(document).ready(function() {
    $("#selectAllBranches").on("click", function() {
        $("#branch_select option").prop("selected", true);
    });
});
</script>
';

require_once '../app/views/layouts/main.php';
?>