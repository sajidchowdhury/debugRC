<?php
ob_start();
$title = $title ?? 'User Audit Logs';
$logs = $logs ?? [];
$countCreated = $countUpdated = $countPerm = $countOther = 0;
foreach ($logs as $log) {
    $a = (string)($log['action'] ?? '');
    if (str_contains($a, 'created')) $countCreated++;
    elseif (str_contains($a, 'updated') || str_contains($a, 'password')) $countUpdated++;
    elseif (str_contains($a, 'permission') || str_contains($a, '2fa')) $countPerm++;
    else $countOther++;
}
function userAuditClass(string $action): string {
    if (str_contains($action, 'created')) return 'created';
    if (str_contains($action, 'updated') || str_contains($action, 'password')) return 'updated';
    if (str_contains($action, 'permission') || str_contains($action, '2fa') || str_contains($action, 'status') || str_contains($action, 'deleted')) return 'status';
    return 'other';
}
function userAuditLabel(string $action): string {
    return match (true) {
        str_contains($action, 'created') => 'Created',
        str_contains($action, 'updated') => 'Updated',
        str_contains($action, 'status') => 'Status changed',
        str_contains($action, 'permission') => 'Permissions',
        str_contains($action, 'user_2fa_enabled') => '2FA enabled',
        str_contains($action, 'user_2fa_disabled') => '2FA disabled',
        str_contains($action, 'user_2fa_admin_disabled') => '2FA admin recovery',
        str_contains($action, 'soft_deleted') => 'Deleted',
        str_contains($action, 'password') => 'Password',
        default => $action,
    };
}
function userAuditDetails($details): string {
    if (empty($details) || !is_array($details)) return '<span class="text-muted">—</span>';
    $parts = [];
    if (!empty($details['username'])) $parts[] = '<strong>User:</strong> ' . htmlspecialchars((string)$details['username'], ENT_QUOTES);
    if (!empty($details['menu_count'])) $parts[] = '<strong>Menus:</strong> ' . (int)$details['menu_count'];
    if (empty($parts)) return '<span class="branch-audit-details">' . htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '</span>';
    return '<div class="branch-audit-details">' . implode(' · ', $parts) . '</div>';
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/user-theme.css">
<div class="branch-hub user-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-clock-rotate-left me-2"></i>User audit trail</h1>
            <p>Account creates, updates, permissions, status — last 300 user events.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>user/create" class="btn btn-outline-light btn-sm"><i class="fas fa-plus me-1"></i> New user</a>
            <a href="<?= BASE_URL ?>user/security_audit" class="btn btn-light btn-sm"><i class="fas fa-shield-halved me-1"></i> Unified audit</a>
            <a href="<?= BASE_URL ?>user" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Users</a>
        </div>
    </header>
    <div class="branch-audit-summary">
        <span class="branch-audit-chip"><i class="fas fa-list"></i> <?= count($logs) ?> entries</span>
        <span class="branch-audit-chip created"><i class="fas fa-plus"></i> <?= $countCreated ?> created</span>
        <span class="branch-audit-chip updated"><i class="fas fa-pen"></i> <?= $countUpdated ?> updated</span>
        <span class="branch-audit-chip status"><i class="fas fa-shield-halved"></i> <?= $countPerm + $countOther ?> access</span>
    </div>
    <div class="branch-hub-panel branch-audit-panel">
        <div class="table-responsive">
            <table class="table table-borderless mb-0 w-100" id="auditTable">
                <thead><tr><th>When</th><th>Performed by</th><th>Action</th><th>Target ID</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">No user audit logs yet.</td></tr>
                <?php else: foreach ($logs as $log):
                    $action = (string)($log['action'] ?? ''); ?>
                <tr>
                    <td><small class="text-nowrap"><?= htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES) ?></small></td>
                    <td><span class="badge rounded-pill bg-light text-dark border"><?= htmlspecialchars((string)($log['performed_by_label'] ?? ('#' . (int)($log['performed_by'] ?? 0))), ENT_QUOTES) ?></span></td>
                    <td><span class="branch-audit-action <?= userAuditClass($action) ?>"><?= htmlspecialchars(userAuditLabel($action), ENT_QUOTES) ?></span></td>
                    <td><strong><?= htmlspecialchars((string)($log['target_user_id'] ?? '—'), ENT_QUOTES) ?></strong></td>
                    <td><?= userAuditDetails($log['details'] ?? []) ?></td>
                    <td><small class="text-muted"><?= htmlspecialchars($log['ip'] ?? 'unknown', ENT_QUOTES) ?></small></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="branch-audit-meta-foot"><code>logs/user_audit.log</code> · prefix <code>user_</code></div>
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