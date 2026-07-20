<?php
ob_start();
$title = 'Other Income Audit Logs';
?>
<div class="container-fluid py-3">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <div>
                <h4 class="card-title mb-0 fw-semibold">Other Income Audit Logs</h4>
                <small class="text-muted">Recent changes (last 300 entries)</small>
            </div>
            <div>
                <a href="<?= BASE_URL ?>OtherIncome" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to Incomes
                </a>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 160px;">Timestamp</th>
                            <th style="width: 80px;">By User</th>
                            <th>Action</th>
                            <th style="width: 100px;">Target ID</th>
                            <th>Details</th>
                            <th style="width: 120px;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No audit logs found yet for Other Income.</td></tr>
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
                                    if (!empty($details['income_code'])) {
                                        $detailsHtml = '<small><strong>Code:</strong> ' . htmlspecialchars($details['income_code']);
                                        if (!empty($details['amount'])) {
                                            $detailsHtml .= ' &nbsp;|&nbsp; <strong>Amount:</strong> ৳ ' . number_format($details['amount'], 2);
                                        }
                                        $detailsHtml .= '</small>';
                                    } else {
                                        $detailsHtml = '<small class="text-muted">' . htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE)) . '</small>';
                                    }
                                }

                                if (!empty($details['reason'])) {
                                    if ($detailsHtml) $detailsHtml .= '<br>';
                                    $detailsHtml .= '<small><strong>Reason:</strong> ' . htmlspecialchars($details['reason']) . '</small>';
                                }

                                // Prominently display linked Journal Entry ID if present
                                if (!empty($details['journal_entry_id'])) {
                                    $jeId = (int)$details['journal_entry_id'];
                                    $jeBadge = '<span class="badge bg-primary me-1">JE #' . $jeId . '</span>';
                                    if ($detailsHtml) {
                                        $detailsHtml = $jeBadge . ' ' . $detailsHtml;
                                    } else {
                                        $detailsHtml = $jeBadge;
                                    }
                                }
                                ?>
                                <tr>
                                    <td><small><?= $timestamp ?></small></td>
                                    <td><span class="badge bg-light text-dark"><?= $performedBy ?></span></td>
                                    <td><span class="badge bg-secondary"><?= $action ?></span></td>
                                    <td><strong><?= htmlspecialchars($targetId) ?></strong></td>
                                    <td><?= $detailsHtml ?></td>
                                    <td><small class="text-muted"><?= $ip ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $("#auditTable").DataTable({
        pageLength: 50,
        order: [[0, "desc"]]
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>