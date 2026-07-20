<?php
$po = $po ?? [];
$items = $po['items'] ?? [];
$poCode = $po['po_code'] ?? '';
$status = (string)($po['status'] ?? 'draft');
$branchName = $po['branch_name'] ?? ($_SESSION['branch_name'] ?? 'Branch');

$statusClass = match ($status) {
    'draft' => 'draft',
    'pending' => 'pending',
    'partially_received' => 'partial',
    'received' => 'received',
    'cancelled' => 'cancelled',
    default => 'draft',
};
$statusLabel = ucwords(str_replace('_', ' ', $status));

$totalOrdered = 0.0;
$totalReceived = 0.0;
foreach ($items as $item) {
    $totalOrdered += (float)($item['qty'] ?? 0);
    $totalReceived += (float)($item['received_qty'] ?? 0);
}
$receivePct = $totalOrdered > 0 ? min(100, round(($totalReceived / $totalOrdered) * 100)) : 0;
$headerTotal = (float)($po['total_amount'] ?? 0);
$calcTotal = (float)($po['calculated_total'] ?? 0);

$formatMoney = static fn ($n) => 'Tk ' . number_format((float)$n, 2);
$formatQty = static function ($n) {
    $v = (float)$n;
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
};

$canEdit = $status === 'draft';
$canReceive = in_array($status, ['pending', 'partially_received'], true);

$title = 'Purchase Order — ' . $poCode;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-order-details.css">

<div class="purch-index-app purch-po-detail container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-file-invoice me-2"></i><?= htmlspecialchars($poCode, ENT_QUOTES) ?></h1>
            <p>Purchase order details — receipt progress and line items</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
            <span class="purch-po-status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES) ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex gap-2 flex-wrap">
            <?php if ($canReceive): ?>
            <a href="<?= BASE_URL ?>PurchaseReceive/create" class="btn btn-light btn-sm">
                <i class="fas fa-dolly me-1"></i> Receive goods
            </a>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <a href="<?= BASE_URL ?>PurchaseOrder/edit/<?= (int)($po['id'] ?? 0) ?>" class="btn btn-warning btn-sm">
                <i class="fas fa-edit me-1"></i> Edit
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>PurchaseOrder" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <div class="purch-po-detail-stats">
        <div class="purch-po-stat">
            <span class="label">Order total</span>
            <span class="value"><?= $formatMoney($headerTotal) ?></span>
            <?php if (abs($headerTotal - $calcTotal) > 0.02): ?>
            <span class="sub text-warning">Lines sum <?= $formatMoney($calcTotal) ?></span>
            <?php endif; ?>
        </div>
        <div class="purch-po-stat">
            <span class="label">Receipt progress</span>
            <span class="value"><?= (int)$receivePct ?>%</span>
            <span class="sub"><?= $formatQty($totalReceived) ?> / <?= $formatQty($totalOrdered) ?> units</span>
        </div>
        <div class="purch-po-stat">
            <span class="label">Supplier</span>
            <span class="value"><?= htmlspecialchars($po['supplier_name'] ?? '—', ENT_QUOTES) ?></span>
            <?php if (!empty($po['supplier_code'])): ?>
            <span class="sub"><?= htmlspecialchars($po['supplier_code'], ENT_QUOTES) ?></span>
            <?php endif; ?>
        </div>
        <div class="purch-po-stat">
            <span class="label">Created by</span>
            <span class="value"><?= htmlspecialchars($po['created_by_name'] ?? '—', ENT_QUOTES) ?></span>
            <span class="sub">PO date <?= !empty($po['po_date']) ? date('d M Y', strtotime($po['po_date'])) : '—' ?></span>
        </div>
    </div>

    <?php if ($totalOrdered > 0): ?>
    <div class="purch-po-progress-wrap">
        <div class="progress" style="height: 8px;">
            <div class="progress-bar bg-success" role="progressbar" style="width: <?= (int)$receivePct ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="purch-po-detail-grid">
        <section class="purch-po-detail-card">
            <h2><i class="fas fa-calendar-alt me-1"></i> Dates</h2>
            <dl>
                <dt>PO date</dt>
                <dd><?= !empty($po['po_date']) ? date('d M Y', strtotime($po['po_date'])) : '—' ?></dd>
                <dt>Expected</dt>
                <dd><?= !empty($po['expected_date']) ? date('d M Y', strtotime($po['expected_date'])) : '—' ?></dd>
            </dl>
        </section>
        <section class="purch-po-detail-card">
            <h2><i class="fas fa-info-circle me-1"></i> Notes</h2>
            <?php if (!empty($po['remarks'])): ?>
            <p class="purch-po-remarks mb-0"><?= nl2br(htmlspecialchars($po['remarks'], ENT_QUOTES)) ?></p>
            <?php else: ?>
            <p class="text-muted mb-0 small">No remarks on this order.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="purch-po-detail-items">
        <div class="purch-po-detail-items-head">
            <h2><i class="fas fa-boxes me-1"></i> Line items</h2>
            <span class="text-muted small"><?= count($items) ?> product(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Ordered</th>
                        <th class="text-center">Received</th>
                        <th class="text-center">Pending</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item):
                    $qty = (float)($item['qty'] ?? 0);
                    $recv = (float)($item['received_qty'] ?? 0);
                    $pending = max(0, $qty - $recv);
                    $amount = (float)($item['amount'] ?? ($qty * (float)($item['rate'] ?? 0)));
                    $lineDone = $qty > 0 && $recv >= $qty - 0.0001;
                    ?>
                    <tr class="<?= $lineDone ? 'table-success' : ($recv > 0 ? 'table-warning' : '') ?>">
                        <td>
                            <strong><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars($item['product_code'] ?? '', ENT_QUOTES) ?>
                                <?php if (!empty($item['unit'])): ?> · <?= htmlspecialchars($item['unit'], ENT_QUOTES) ?><?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center"><?= $formatQty($qty) ?></td>
                        <td class="text-center"><?= $formatQty($recv) ?></td>
                        <td class="text-center"><?= $formatQty($pending) ?></td>
                        <td class="text-end"><?= number_format((float)($item['rate'] ?? 0), 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($amount, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" class="text-end">Total</th>
                        <th class="text-end"><?= number_format($calcTotal, 2) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';