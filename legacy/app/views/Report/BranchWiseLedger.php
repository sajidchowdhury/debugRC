<?php
$title = 'Branch Wise Ledger';

$content = '
<div class="container-fluid py-3">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h5><i class="fas fa-book"></i> Branch Wise Ledger</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 mb-4">
                <div class="col-md-3">
                    <label>Select Branch</label>
                    <select name="branch_id" class="form-select" required>';
foreach ($branches as $b) {
    $sel = ($branch_id == $b['id']) ? 'selected' : '';
    $content .= '<option value="'.$b['id'].'" '.$sel.'>'.htmlspecialchars($b['branch_name']).'</option>';
}
$content .= '
                    </select>
                </div>
                <div class="col-md-2">
                    <label>From Date</label>
                    <input type="date" name="from_date" class="form-control" value="'.htmlspecialchars($from_date ?? date('Y-m-d', strtotime('-30 days'))).'">
                </div>
                <div class="col-md-2">
                    <label>To Date</label>
                    <input type="date" name="to_date" class="form-control" value="'.htmlspecialchars($to_date ?? date('Y-m-d')).'">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" name="search" class="btn btn-primary">Show Ledger</button>
                    <button type="submit" name="export" class="btn btn-success">Export CSV</button>
                </div>
            </form>
        </div>
    </div>';

if (!empty($ledger_data)) {
    $branchName = $ledger_data[0]['branch_name'] ?? '';

    $content .= '
    <div class="card shadow mt-4">
        <div class="card-header bg-dark text-white">
            Ledger for: <strong>' . htmlspecialchars($branchName) . '</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Direction</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Running Balance</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>';

    foreach ($ledger_data as $row) {
        $amount = $row['debit'] > 0 ? $row['debit'] : $row['credit'];
        $isDebit = $row['debit'] > 0;
        $color = $isDebit ? 'text-danger' : 'text-success';
        $sign = $isDebit ? '-' : '+';

        $dirClass = ($row['direction'] === 'Incoming') ? 'text-success' : 'text-danger';

        $content .= '
                <tr>
                    <td>' . $row['transaction_date'] . '</td>
                    <td><span class="' . $dirClass . ' fw-bold">' . $row['direction'] . '</span></td>
                    <td>' . htmlspecialchars($row['reference_type'] . ' #' . $row['reference_id']) . '</td>
                    <td class="text-end ' . $color . ' fw-bold">' . $sign . ' ' . number_format($amount, 2) . '</td>
                    <td class="text-end fw-bold">' . number_format($row['running_balance'] ?? 0, 2) . '</td>
                    <td>' . htmlspecialchars($row['remarks'] ?? '-') . '</td>
                </tr>';
    }

    $content .= '
                </tbody>
            </table>
        </div>
    </div>';
} elseif (isset($_GET['search'])) {
    $content .= '<div class="alert alert-info mt-4">No records found.</div>';
}

$content .= '</div>';

require_once '../app/views/layouts/main.php';
?>