<?php
ob_start();
$title = 'Supplier Payment Audit Logs';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-transaction-theme.css">
<div class="container-fluid py-3 supp-txn-theme">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <div>
                <h4 class="card-title mb-0 fw-semibold">Supplier Payment Audit Logs</h4>
                <small class="text-muted">Creates and reversals (last 300 entries)</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>SupplierTransaction" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to payments
                </a>
                <a href="<?= BASE_URL ?>SupplierTransaction?reversed=1" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-undo me-1"></i> View reversed
                </a>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 160px;">Timestamp</th>
                            <th style="width: 80px;">By user</th>
                            <th>Action</th>
                            <th style="width: 100px;">Payment ID</th>
                            <th>Details</th>
                            <th style="width: 120px;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <?php else: foreach ($logs as $log): ?>
                            <?php
                            $action = htmlspecialchars($log['action'] ?? '', ENT_QUOTES);
                            $timestamp = htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES);
                            $performedBy = (int)($log['performed_by'] ?? 0);
                            $targetId = $log['target_user_id'] ?? '-';
                            $ip = htmlspecialchars($log['ip'] ?? 'unknown', ENT_QUOTES);
                            $details = $log['details'] ?? [];

                            $detailsHtml = '';
                            if (!empty($details['payment_code'])) {
                                $detailsHtml = '<small><strong>Code:</strong> ' . htmlspecialchars((string)$details['payment_code'], ENT_QUOTES);
                                if (!empty($details['transaction_type'])) {
                                    $detailsHtml .= ' · <strong>Type:</strong> ' . htmlspecialchars((string)$details['transaction_type'], ENT_QUOTES);
                                }
                                if (!empty($details['amount'])) {
                                    $detailsHtml .= ' · <strong>Amount:</strong> Tk ' . number_format((float)$details['amount'], 2);
                                }
                                if (!empty($details['reason'])) {
                                    $detailsHtml .= '<br><strong>Reason:</strong> ' . htmlspecialchars((string)$details['reason'], ENT_QUOTES);
                                }
                                $detailsHtml .= '</small>';
                            } elseif (!empty($details)) {
                                $detailsHtml = '<small class="text-muted">' . htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '</small>';
                            }
                            ?>
                            <tr>
                                <td><small><?= $timestamp ?></small></td>
                                <td><small>#<?= $performedBy ?></small></td>
                                <td><span class="badge bg-light text-dark"><?= $action ?></span></td>
                                <td>
                                    <?php if (is_numeric($targetId) && (int)$targetId > 0): ?>
                                    <a href="<?= BASE_URL ?>SupplierTransaction/details/<?= (int)$targetId ?>">#<?= (int)$targetId ?></a>
                                    <?php else: ?>
                                    <?= htmlspecialchars((string)$targetId, ENT_QUOTES) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $detailsHtml ?></td>
                                <td><small class="text-muted"><?= $ip ?></small></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        $('#auditTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 50,
            language: { emptyTable: 'No audit entries yet.' },
        });
    }
});
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
