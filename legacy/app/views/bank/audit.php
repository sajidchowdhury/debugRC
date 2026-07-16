<?php
ob_start();
require_once __DIR__ . '/../../helpers/MasterDataAuditHelper.php';

$title = $title ?? 'Bank Audit Logs';
$logs = $logs ?? [];
$countCreated = $countUpdated = $countStatus = 0;
foreach ($logs as $log) {
    $a = (string)($log['action'] ?? '');
    if (str_contains($a, 'created')) $countCreated++;
    elseif (str_contains($a, 'updated')) $countUpdated++;
    else $countStatus++;
}
function bankAuditClass(string $action): string {
    if (str_contains($action, 'created')) return 'created';
    if (str_contains($action, 'updated')) return 'updated';
    if (str_contains($action, 'status') || str_contains($action, 'deactivated') || str_contains($action, 'restored')) return 'status';
    return 'other';
}
function bankAuditLabel(string $action): string {
    return match (true) {
        str_contains($action, 'created') => 'Created',
        str_contains($action, 'updated') => 'Updated',
        str_contains($action, 'status_changed') => 'Status changed',
        str_contains($action, 'deactivated') => 'Deactivated',
        str_contains($action, 'restored') => 'Restored',
        default => $action,
    };
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bank-theme.css">

<div class="branch-hub bank-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-clock-rotate-left me-2"></i>Bank audit trail</h1>
            <p>Creates, updates, status changes, deactivations, and restores — last 300 bank events.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>bank/create" class="btn btn-outline-light btn-sm"><i class="fas fa-plus me-1"></i> New account</a>
            <a href="<?= BASE_URL ?>bank" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Accounts</a>
        </div>
    </header>

    <div class="branch-audit-summary">
        <span class="branch-audit-chip"><i class="fas fa-list"></i> <?= count($logs) ?> entries</span>
        <span class="branch-audit-chip created"><i class="fas fa-plus"></i> <?= $countCreated ?> created</span>
        <span class="branch-audit-chip updated"><i class="fas fa-pen"></i> <?= $countUpdated ?> updated</span>
        <span class="branch-audit-chip status"><i class="fas fa-toggle-on"></i> <?= $countStatus ?> status</span>
    </div>

    <div class="branch-hub-panel branch-audit-panel">
        <div class="table-responsive">
            <table class="table table-borderless mb-0 w-100" id="auditTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Bank ID</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 opacity-50 d-block"></i>
                            No bank audit logs yet.
                        </td>
                    </tr>
                    <?php else: foreach ($logs as $log):
                        $action = (string)($log['action'] ?? '');
                        $cls = bankAuditClass($action);
                        $targetId = $log['target_user_id'] ?? '—';
                    ?>
                    <tr>
                        <td><small class="text-nowrap"><?= htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES) ?></small></td>
                        <td><?= htmlspecialchars($log['performed_by_name'] ?? ('#' . (int)($log['performed_by'] ?? 0)), ENT_QUOTES) ?></td>
                        <td><span class="branch-audit-action <?= $cls ?>"><?= htmlspecialchars(bankAuditLabel($action), ENT_QUOTES) ?></span></td>
                        <td>
                            <?php if (is_numeric($targetId) && (int)$targetId > 0): ?>
                            <a href="<?= BASE_URL ?>bank/show/<?= (int)$targetId ?>" class="fw-semibold text-decoration-none">
                                #<?= (int)$targetId ?>
                            </a>
                            <?php else: ?>
                            <strong><?= htmlspecialchars((string)$targetId, ENT_QUOTES) ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><?= MasterDataAuditHelper::renderDetailsHtml($log['details'] ?? []) ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars($log['ip'] ?? 'unknown', ENT_QUOTES) ?></small></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="branch-audit-meta-foot">
            <i class="fas fa-file-lines me-1"></i>
            Stored in <code>logs/user_audit.log</code> · Filter prefix <code>bank_</code>
        </div>
    </div>
</div>

<script>
$(function() {
    const hasRows = $('#auditTable tbody tr').length > 0 && $('#auditTable tbody tr td').length > 1;
    if (!hasRows) return;
    $('#auditTable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        language: { emptyTable: 'No bank audit logs found yet.', search: 'Filter logs:' }
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
