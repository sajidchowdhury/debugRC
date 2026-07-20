<?php
ob_start();
$title = 'Purchase Return Audit Logs';
?>
<div class="container-fluid py-3">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <div>
                <h4 class="card-title mb-0 fw-semibold">
                    <i class="fas fa-undo text-danger me-2"></i>
                    Purchase Return Audit Logs
                </h4>
                <small class="text-muted">Creations, reversals and key changes (last 300 entries)</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>PurchaseReturn" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to Returns
                </a>
                <a href="<?= BASE_URL ?>PurchaseReturn?reversed=1" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-undo me-1"></i> View Reversed Returns
                </a>
                <a href="<?= BASE_URL ?>PurchaseReturn/audit" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-sync me-1"></i> Refresh
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
                            <tr><td colspan="6" class="text-center text-muted py-4">No Purchase Return audit entries yet.</td></tr>
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
                                    if (!empty($details['return_code'])) {
                                        $parts[] = '<strong>Code:</strong> ' . htmlspecialchars($details['return_code']);
                                    }
                                    if (!empty($details['receive_code'])) {
                                        $parts[] = '<strong>From GRN:</strong> ' . htmlspecialchars($details['receive_code']);
                                    }
                                    if (!empty($details['supplier_name'])) {
                                        $parts[] = '<strong>Supplier:</strong> ' . htmlspecialchars($details['supplier_name']);
                                    }
                                    if (!empty($details['total_amount'])) {
                                        $parts[] = '<strong>Amt:</strong> ৳ ' . number_format((float)$details['total_amount'], 2);
                                    }
                                    if (!empty($details['item_count'])) {
                                        $parts[] = htmlspecialchars($details['item_count']) . ' item(s)';
                                    }
                                    $detailsHtml = '<small>' . implode(' &nbsp;|&nbsp; ', $parts) . '</small>';

                                    if (!empty($details['reason'])) {
                                        $detailsHtml .= '<br><small class="text-danger"><strong>Reason:</strong> ' . htmlspecialchars($details['reason']) . '</small>';
                                    }
                                } else {
                                    $detailsHtml = '<small class="text-muted">—</small>';
                                }

                                // Prominent journal entry badge (Phase 5 ready)
                                if (!empty($details['journal_entry_id'])) {
                                    $jeBadge = '<span class="badge bg-primary me-1">JE #' . (int)$details['journal_entry_id'] . '</span>';
                                    $detailsHtml = $jeBadge . ' ' . $detailsHtml;
                                }

                                $actionBadge = match(true) {
                                    str_contains($action, 'created')   => '<span class="badge bg-success">Created</span>',
                                    str_contains($action, 'reversed')  => '<span class="badge bg-danger">Reversed</span>',
                                    default => '<span class="badge bg-secondary">' . htmlspecialchars($action) . '</span>'
                                };
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
            Logs stored in <code>logs/user_audit.log</code>. 
            Purchase Return reversals restore stock via <strong>StockTransactionModel</strong> and decrement <code>returned_qty</code> on the original GRN items.
            <br>After Phase 5 GL integration, every entry will include the linked <code>journal_entry_id</code>.
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layouts/main.php';
?>