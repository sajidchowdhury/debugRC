<?php
$title = 'Purchase Return Slip #' . htmlspecialchars($return['return_code']);

$content = '
<div class="container-fluid py-3">

    <div class="card shadow" style="max-width: 900px; margin: 0 auto;">
        
        <!-- Header -->
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="fas fa-undo"></i> Purchase Return Slip
            </h4>
            <div>
                <button onclick="printSlip()" class="btn btn-light btn-sm me-2">
                    <i class="fas fa-print"></i> Print Slip
                </button>
                <a href="' . BASE_URL . 'PurchaseReturn" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Slip Content -->
        <div class="card-body" id="printableArea">
            
            <div class="text-center mb-4">
                <h3 class="mb-1">REMOTE CENTER</h3>
                <h5 class="text-danger">PURCHASE RETURN SLIP</h5>
                <strong>Return Code: ' . htmlspecialchars($return['return_code']) . '</strong>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <strong>Supplier:</strong> ' . htmlspecialchars($return['supplier_name']) . '<br>
                    <strong>Mobile:</strong> ' . htmlspecialchars($return['mobile'] ?? 'N/A') . '<br>
                    <strong>GRN Reference:</strong> ' . htmlspecialchars($return['receive_code']) . '
                </div>
                <div class="col-md-6 text-end">
                    <strong>Branch:</strong> ' . htmlspecialchars($return['branch_name']) . '<br>
                    <strong>Date:</strong> ' . date('d-m-Y', strtotime($return['return_date'])) . '<br>
                    <strong>Created By:</strong> ' . htmlspecialchars($return['created_by_name'] ?? 'System') . '
                </div>
            </div>

            <!-- Items Table -->
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 40px">#</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th class="text-center">Return Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Amount</th>
                            <th>Condition</th>
                        </tr>
                    </thead>
                    <tbody>';

$total = 0;
foreach ($return['items'] as $i => $item) {
    $amount = $item['return_qty'] * $item['rate'];
    $total += $amount;

    $content .= '
                        <tr>
                            <td>' . ($i + 1) . '</td>
                            <td>
                                <strong>' . htmlspecialchars($item['product_name']) . '</strong><br>
                                <small>' . htmlspecialchars($item['product_code']) . '</small>
                            </td>
                            <td>' . htmlspecialchars($item['warehouse_name']) . '</td>
                            <td class="text-center">' . number_format($item['return_qty'], 2) . '</td>
                            <td class="text-end">' . number_format($item['rate'], 2) . '</td>
                            <td class="text-end">' . number_format($amount, 2) . '</td>
                            <td>' . htmlspecialchars($item['condition']) . '</td>
                        </tr>';
}

$content .= '
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end"><strong>Total Amount</strong></th>
                            <th class="text-end"><strong>' . number_format($total, 2) . '</strong></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>';

if (!empty($return['reason'])) {
    $content .= '
            <div class="mt-4 p-3 bg-light border rounded">
                <strong>Reason for Return:</strong><br>
                ' . nl2br(htmlspecialchars($return['reason'])) . '
            </div>';
}

$content .= '
            <div class="row mt-5">
                <div class="col-6 text-center">
                    <p>___________________________</p>
                    <strong>Received By (Supplier)</strong>
                </div>
                <div class="col-6 text-center">
                    <p>___________________________</p>
                    <strong>Authorized By</strong>
                </div>
            </div>

        </div>

        <div class="card-footer text-center">
            <small class="text-muted">Thank you for your business | Remote Center ERP</small>
        </div>
    </div>
</div>

<style>
    @media print {
        .sidebar, .navbar, .card-header .btn, .card-footer, 
        .no-print, .btn, nav, header, footer { 
            display: none !important; 
        }
        #printableArea { 
            margin: 0; 
            padding: 20px; 
        }
        body { 
            background: white !important; 
        }
        .card { 
            border: none !important; 
            box-shadow: none !important; 
        }
        table { 
            border-collapse: collapse !important; 
        }
        th, td {
            border: 1px solid #000 !important;
        }
    }
</style>

<script>
function printSlip() {
    window.print();
}
</script>
';

require_once '../app/views/layouts/main.php';
?>