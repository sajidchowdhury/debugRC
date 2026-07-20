<?php
ob_start();
$title = 'Purchase Receive Audit Logs';
?>
<div class="container-fluid py-3">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <div>
                <h4 class="card-title mb-0 fw-semibold">
                    <i class="fas fa-truck-loading text-success me-2"></i>
                    Purchase Receive (GRN) Audit Logs
                </h4>
                <small class="text-muted">Goods receipts (including Direct Purchases) — last 300 entries</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>PurchaseReceive" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to Receives
                </a>
                <a href="<?= BASE_URL ?>PurchaseReceive?returned=1" class="btn btn-outline-warning btn-sm">
                    <i class="fas fa-undo me-1"></i> View Returned/Cancelled
                </a>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 155px;">Timestamp</th>
                            <th style="width: 70px;">By</th>
                            <th>Action</th>
                            <th style="width: 95px;">Target ID</th>
                            <th>Details</th>
                            <th style="width: 105px;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No Purchase Receive audit entries yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $action = htmlspecialchars($log['action'] ?? '');
                                $timestamp = htmlspecialchars($log['timestamp'] ?? '');
                                $performedBy = (int)($log['performed_by'] ?? 0);
                                $targetId = $log['target_user_id'] ?? '-';
                                $ip = htmlspecialchars($log['ip'] ?? 'unknown');
                                $details = $log['details'] ?? [];

                                $detailsHtml = '';
                                if (!empty($details)) {
                                    $parts = [];
                                    if (isset($details['is_direct_purchase']) && $details['is_direct_purchase']) {
                                        $parts[] = '<span class="badge bg-info text-dark">Direct</span>';
                                    }
                                    if (!empty($details['purchase_order_id'])) {
                                        $parts[] = 'PO #' . (int)$details['purchase_order_id'];
                                    }
                                    if (!empty($details['total_amount'])) {
                                        $parts[] = '<strong>Amt:</strong> ৳ ' . number_format((float)$details['total_amount'], 2);
                                    }
                                    if (!empty($details['item_count'])) {
                                        $parts[] = (int)$details['item_count'] . ' items';
                                    }
                                    $detailsHtml = '<small>' . implode(' &nbsp;|&nbsp; ', $parts) . '</small>';

                                    if (!empty($details['accounting_impact'])) {
                                        $detailsHtml .= '<br><small class="text-muted">' . htmlspecialchars($details['accounting_impact']) . '</small>';
                                    }
                                }

                                if (!empty($details['journal_entry_id'])) {
                                    $jeBadge = '<span class="badge bg-primary me-1">JE #' . (int)$details['journal_entry_id'] . '</span>';
                                    $detailsHtml = $jeBadge . ' ' . ($detailsHtml ?: '');
                                }

                                $actionBadge = str_contains($action, 'created')
                                    ? '<span class="badge bg-success">Created</span>'
                                    : '<span class="badge bg-secondary">' . htmlspecialchars($action) . '</span>';
                                ?>
                                <tr>
                                    <td><small><?= $timestamp ?></small></td>
                                    <td><span class="badge bg-light text-dark"><?= $performedBy ?></span></td>
                                    <td><?= $actionBadge ?></td>
                                    <td><strong>#<?= htmlspecialchars($targetId) ?></strong></td>
                                    <td><?= $detailsHtml ?></td>
                                    <td><small class="text-muted"><?= $ip ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-white small text-muted">
            <strong>Accounting note (Phase 5):</strong> Every GRN will post 
            <code>Dr Inventory (moving avg) / Cr Supplier Payable</code>. 
            Audit entries will carry the <code>journal_entry_id</code> once integrated.
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layouts/main.php';
?>