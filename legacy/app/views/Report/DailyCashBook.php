<?php
$title = 'Day Book Report';

$data = $daybook_data ?? ['opening_cash' => 0, 'opening_bank' => 0, 'transactions' => []];

$content = '
<div class="container-fluid py-3">
    <div class="card shadow">
        <div class="card-header bg-success text-white text-center">
            <h5>Day Book Report • ' . date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date)) . '</h5>
        </div>
        <div class="card-body">

            <!-- Filter -->
            <form method="GET" class="row g-3 mb-4">
                <div class="col-md-3">
                    <label>Branch</label>
                    <select name="branch_id" class="form-select">';
foreach ($branches as $b) {
    $sel = ($branch_id == $b['id']) ? 'selected' : '';
    $content .= '<option value="'.$b['id'].'" '.$sel.'>'.htmlspecialchars($b['branch_name']).'</option>';
}
$content .= '
                    </select>
                </div>
                <div class="col-md-2"><label>From</label><input type="date" name="from_date" value="'.htmlspecialchars($from_date).'" class="form-control"></div>
                <div class="col-md-2"><label>To</label><input type="date" name="to_date" value="'.htmlspecialchars($to_date).'" class="form-control"></div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" name="search" class="btn btn-primary">Show Report</button>
                    <button type="submit" name="export" class="btn btn-success">Export CSV</button>
                </div>
            </form>

            <!-- Opening Balance -->
            <div class="alert alert-info mb-4">
                <strong>Opening Balance:</strong> 
                Cash: ৳ ' . number_format($data['opening_cash'], 2) . ' | 
                Bank: ৳ ' . number_format($data['opening_bank'], 2) . '
            </div>

            <div class="row">

                <!-- LEFT - INFLOWS -->
                <div class="col-lg-6">
                    <h4 class="bg-success text-white text-center py-2">INFLOWS (RECEIPTS)</h4>

                    <h5>Invoice Wise Payment</h5>' . renderSection($data['transactions'], 'Invoice Wise Payment') . '

                    <h5 class="mt-4">Customer / Sales Receive</h5>' . renderSection($data['transactions'], 'Customer Transaction') . '

                    <h5 class="mt-4">Other Income</h5>' . renderSection($data['transactions'], 'Add Income') . '

                    <h5 class="mt-4">Money Received (Inter Branch)</h5>' . renderSection($data['transactions'], 'Money Transfer') . '
                </div>

                <!-- RIGHT - OUTFLOWS -->
                <div class="col-lg-6">
                    <h4 class="bg-danger text-white text-center py-2">OUTFLOWS (PAYMENTS)</h4>

                    <h5>Add Expense</h5>' . renderSection($data['transactions'], 'Add Expense') . '

                    <h5 class="mt-4">Customer Payment / Discount</h5>' . renderSection($data['transactions'], 'Customer Transaction') . '

                    <h5 class="mt-4">Supplier Transaction</h5>' . renderSection($data['transactions'], 'Supplier Transaction') . '

                    <h5 class="mt-4">Money Transfer (Out)</h5>' . renderSection($data['transactions'], 'Money Transfer') . '
                </div>
            </div>

            <!-- Grand Total -->
            <div class="alert alert-dark text-center mt-4 fs-5">
                <strong>Overall Net Movement:</strong> 
                ৳ ' . number_format(calculateNet($data['transactions']), 2) . '
            </div>

        </div>
    </div>
</div>';

require_once '../app/views/layouts/main.php';

// ==================== IMPROVED HELPER ====================
function renderSection($transactions, $section) {
    $html = '<div class="row g-3">';

    $cashRows = [];
    $bankRows = [];

    foreach ($transactions as $row) {
        $refType = $row['section'] ?? '';
        if (strpos($refType, $section) !== false || $refType === $section) {
            $side = in_array(strtolower($row['cash_or_bank'] ?? ''), ['cash','main_cash']) ? 'Cash' : 'Bank';
            $amount = ($row['debit'] ?? 0) > 0 ? ($row['debit'] ?? 0) : ($row['credit'] ?? 0);

            if ($side === 'Cash') {
                $cashRows[] = $row;
            } else {
                $bankRows[] = $row;
            }
        }
    }

    // Render Cash Column
    $html .= '<div class="col-6">';
    $html .= '<h6 class="bg-warning text-center py-1">Cash</h6>';
    if (empty($cashRows)) {
        $html .= '<div class="text-muted text-center py-3">No Cash transactions</div>';
    } else {
        foreach ($cashRows as $row) {
            $html .= '
                <table class="table table-bordered table-sm mb-2">
                    <tr>
                        <td>' . htmlspecialchars($row['particulars'] ?? 'N/A') . '</td>
                        <td class="text-end text-success">' . number_format($row['debit'] ?? 0, 2) . '</td>
                        <td class="text-end fw-bold">' . number_format($row['running_balance'] ?? 0, 2) . '</td>
                    </tr>
                </table>';
        }
    }
    $html .= '</div>';

    // Render Bank Column
    $html .= '<div class="col-6">';
    $html .= '<h6 class="bg-info text-white text-center py-1">Bank</h6>';
    if (empty($bankRows)) {
        $html .= '<div class="text-muted text-center py-3">No Bank transactions</div>';
    } else {
        foreach ($bankRows as $row) {
            $html .= '
                <table class="table table-bordered table-sm mb-2">
                    <tr>
                        <td>' . htmlspecialchars($row['particulars'] ?? 'N/A') . '</td>
                        <td class="text-end text-success">' . number_format($row['debit'] ?? 0, 2) . '</td>
                        <td class="text-end fw-bold">' . number_format($row['running_balance'] ?? 0, 2) . '</td>
                    </tr>
                </table>';
        }
    }
    $html .= '</div></div>';

    return $html;
}

function calculateNet($transactions) {
    $net = 0;
    foreach ($transactions as $row) {
        $net += (($row['debit'] ?? 0) - ($row['credit'] ?? 0));
    }
    return $net;
}
?>