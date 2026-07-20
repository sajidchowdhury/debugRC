<?php
ob_start();
$title = $title ?? 'Manual Journal Audit Logs';
?>
<div class="container-fluid py-3">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <div>
                <h4 class="card-title mb-0 fw-semibold">Manual Journal Audit Logs</h4>
                <small class="text-muted">Create and reverse actions (last 300 entries)</small>
            </div>
            <a href="<?= BASE_URL ?>ManualJournal" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:160px">When</th>
                            <th style="width:80px">User</th>
                            <th>Action</th>
                            <th style="width:90px">Target</th>
                            <th>Details</th>
                            <th style="width:120px">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No audit entries yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <?php
                        $details = $log['details'] ?? [];
                        $detailsHtml = '';
                        if (!empty($details['entry_no'])) {
                            $detailsHtml .= '<small><strong>Entry:</strong> ' . htmlspecialchars($details['entry_no'], ENT_QUOTES) . '</small>';
                        }
                        if (!empty($details['journal_entry_id'])) {
                            $detailsHtml .= ' <span class="badge bg-primary">JE #' . (int)$details['journal_entry_id'] . '</span>';
                        }
                        if (!empty($details['reason'])) {
                            $detailsHtml .= '<br><small><strong>Reason:</strong> ' . htmlspecialchars($details['reason'], ENT_QUOTES) . '</small>';
                        }
                        $action = (string)($log['action'] ?? '');
                        $badge = str_contains($action, 'reversed')
                            ? '<span class="badge bg-danger">Reversed</span>'
                            : (str_contains($action, 'created') ? '<span class="badge bg-success">Created</span>' : '<span class="badge bg-secondary">' . htmlspecialchars($action, ENT_QUOTES) . '</span>');
                        ?>
                        <tr>
                            <td class="small text-nowrap"><?= htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES) ?></td>
                            <td>#<?= (int)($log['performed_by'] ?? 0) ?></td>
                            <td><?= $badge ?></td>
                            <td><?= (int)($log['target_user_id'] ?? 0) ?: '—' ?></td>
                            <td><?= $detailsHtml ?: '<span class="text-muted">—</span>' ?></td>
                            <td class="small"><?= htmlspecialchars($log['ip'] ?? '—', ENT_QUOTES) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
