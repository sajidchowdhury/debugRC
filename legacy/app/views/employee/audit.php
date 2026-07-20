<?php
ob_start();
$title = $title ?? 'Employee Audit Logs';
$logs = $logs ?? [];
$countCreated = $countUpdated = $countStatus = 0;
foreach ($logs as $log) {
    $a = (string)($log['action'] ?? '');
    if (str_contains($a, 'created')) $countCreated++;
    elseif (str_contains($a, 'updated')) $countUpdated++;
    else $countStatus++;
}
function empAuditClass(string $action): string {
    if (str_contains($action, 'created')) return 'created';
    if (str_contains($action, 'updated')) return 'updated';
    if (str_contains($action, 'status') || str_contains($action, 'deleted') || str_contains($action, 'restored')) return 'status';
    return 'other';
}
function empAuditLabel(string $action): string {
    return match (true) {
        str_contains($action, 'created') => 'Created',
        str_contains($action, 'updated') => 'Updated',
        str_contains($action, 'status') => 'Status',
        str_contains($action, 'soft_deleted') => 'Deleted',
        str_contains($action, 'restored') => 'Restored',
        default => $action,
    };
}
function empAuditDetails($details): string {
    if (empty($details) || !is_array($details)) return '<span class="text-muted">—</span>';
    $parts = [];
    if (!empty($details['name'])) $parts[] = '<strong>Name:</strong> ' . htmlspecialchars((string)$details['name'], ENT_QUOTES);
    if (empty($parts)) return '<span class="branch-audit-details">' . htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '</span>';
    return '<div class="branch-audit-details">' . implode(' · ', $parts) . '</div>';
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/employee-theme.css">
<div class="branch-hub employee-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-clock-rotate-left me-2"></i>Employee audit trail</h1>
            <p>Creates, updates, status, delete, restore — last 300 employee events.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>employee/create" class="btn btn-outline-light btn-sm"><i class="fas fa-plus me-1"></i> New</a>
            <a href="<?= BASE_URL ?>user/security_audit" class="btn btn-light btn-sm"><i class="fas fa-shield-halved me-1"></i> Unified audit</a>
            <a href="<?= BASE_URL ?>employee" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Directory</a>
        </div>
    </header>
    <div class="branch-audit-summary">
        <span class="branch-audit-chip"><i class="fas fa-list"></i> <?= count($logs) ?> entries</span>
        <span class="branch-audit-chip created"><i class="fas fa-plus"></i> <?= $countCreated ?> created</span>
        <span class="branch-audit-chip updated"><i class="fas fa-pen"></i> <?= $countUpdated ?> updated</span>
        <span class="branch-audit-chip status"><i class="fas fa-toggle-on"></i> <?= $countStatus ?> other</span>
    </div>
    <div class="branch-hub-panel branch-audit-panel">
        <div class="table-responsive">
            <table class="table table-borderless mb-0 w-100" id="auditTable">
                <thead><tr><th>When</th><th>Performed by</th><th>Action</th><th>Employee ID</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">No employee audit logs yet.</td></tr>
                <?php else: foreach ($logs as $log):
                    $action = (string)($log['action'] ?? ''); ?>
                <tr>
                    <td><small class="text-nowrap"><?= htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES) ?></small></td>
                    <td><span class="badge rounded-pill bg-light text-dark border"><?= htmlspecialchars((string)($log['performed_by_label'] ?? ('#' . (int)($log['performed_by'] ?? 0))), ENT_QUOTES) ?></span></td>
                    <td><span class="branch-audit-action <?= empAuditClass($action) ?>"><?= htmlspecialchars(empAuditLabel($action), ENT_QUOTES) ?></span></td>
                    <td><strong><?= htmlspecialchars((string)($log['target_user_id'] ?? '—'), ENT_QUOTES) ?></strong></td>
                    <td><?= empAuditDetails($log['details'] ?? []) ?></td>
                    <td><small class="text-muted"><?= htmlspecialchars($log['ip'] ?? 'unknown', ENT_QUOTES) ?></small></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="branch-audit-meta-foot"><code>logs/user_audit.log</code> · prefix <code>employee_</code></div>
    </div>
</div>
<script>
$(function() {
    if ($('#auditTable tbody tr').length < 1 || ($('#auditTable tbody tr').length === 1 && $('#auditTable tbody tr td').length === 1)) return;
    $('#auditTable').DataTable({ pageLength: 50, order: [[0,'desc']], dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>', buttons: ['copy','excel','pdf'] });
});
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';