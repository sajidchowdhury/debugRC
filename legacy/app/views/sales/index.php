<?php
// app/views/sales/index.php
$title = 'Sales Invoices';

$content = '
<div class="d-flex justify-content-between mb-4">
    <h2>Sales Invoices</h2>
    <a href="' . BASE_URL . 'sales/create" class="btn btn-primary">+ New Sales Invoice</a>
</div>

<!-- Intelligent Sales Cockpit Quick Links -->
<div class="mb-3 p-2 bg-light border rounded d-flex flex-wrap gap-2 align-items-center">
    <span class="small fw-semibold me-2 text-muted">Intelligent Sales Cockpit:</span>
    <a href="' . BASE_URL . 'Report/revenueOverview" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-line me-1"></i>Revenue Overview</a>
    <a href="' . BASE_URL . 'Report/salesFunnelPipeline" class="btn btn-sm btn-outline-primary"><i class="fas fa-filter me-1"></i>Sales Funnel &amp; Pipeline</a>
    <a href="' . BASE_URL . 'Report/customerPerformance" class="btn btn-sm btn-outline-primary"><i class="fas fa-users me-1"></i>Customer Performance</a>
</div>

<table class="table table-bordered">
    <thead class="table-dark">
        <tr>
            <th>Invoice No</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Salesman</th>
            <th>Total</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>';

foreach ($invoices as $inv) {
    $content .= '
        <tr>
            <td>' . htmlspecialchars($inv['invoice_code']) . '</td>
            <td>' . $inv['invoice_date'] . '</td>
            <td>' . htmlspecialchars($inv['shop_name'] ?? $inv['customer_name']) . '</td>
            <td>' . htmlspecialchars($inv['salesman_name']) . '</td>
            <td class="text-end">৳ ' . number_format($inv['total_amount'], 2) . '</td>
            <td><span class="badge bg-warning">Draft</span></td>
            <td>
                <a href="' . BASE_URL . 'sales/view/' . $inv['id'] . '" class="btn btn-sm btn-info">View</a>
            </td>
        </tr>';
}

$content .= '</tbody></table>';

require_once '../app/views/layouts/main.php';
