<?php
ob_start();
require_once __DIR__ . '/../../helpers/MasterDataAuditHelper.php';

$title = $title ?? 'Ledger Audit Logs';
$logs = $logs ?? [];
$ledgerNames = $ledgerNames ?? [];

$countCreated = $countUpdated = $countStatus = 0;
foreach ($logs as $log) {
    $a = (string)($log['action'] ?? '');
    if (str_contains($a, 'created')) {
        $countCreated++;
    } elseif (str_contains($a, 'updated')) {
        $countUpdated++;
    } else {
        $countStatus++;
    }
}

function ledgerAuditClass(string $action): string
{
    if (str_contains($action, 'created')) {
        return 'created';
    }
    if (str_contains($action, 'updated')) {
        return 'updated';
    }
    if (str_contains($action, 'status') || str_contains($action, 'deleted')) {
        return 'status';
    }
    return 'other';
}

function ledgerAuditActionLabel(string $action): string
{
    return match (true) {
        str_contains($action, 'created') => 'Created',
        str_contains($action, 'updated') => 'Updated',
        str_contains($action, 'status_changed') => 'Status changed',
        str_contains($action, 'deleted') => 'Deactivated',
        default => $action,
    };
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/ledger-theme.css">

<div class="branch-hub ledger-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-clock-rotate-left me-2"></i>Ledger audit trail</h1>
            <p>Creates, updates, status changes, and deactivations — last 300 ledger events with field-level detail.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>ledger/create" class="btn btn-outline-light btn-sm"><i class="fas fa-plus me-1"></i> New ledger</a>
            <a href="<?= BASE_URL ?>ledger" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Chart of accounts</a>
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
                        <th>Ledger</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 opacity-50 d-block"></i>
                            No ledger audit logs yet.
                        </td>
                    </tr>
                    <?php else: foreach ($logs as $log):
                        $action = (string)($log['action'] ?? '');
                        $cls = ledgerAuditClass($action);
                        $targetId = (int)($log['target_user_id'] ?? 0);
                        $userLabel = htmlspecialchars(
                            $log['performed_by_name'] ?? ('#' . (int)($log['performed_by'] ?? 0)),
                            ENT_QUOTES
                        );
                        $ledgerLabel = $targetId > 0
                            ? htmlspecialchars($ledgerNames[$targetId] ?? ('Ledger #' . $targetId), ENT_QUOTES)
                            : '—';
                    ?>
                    <tr>
                        <td><small class="text-nowrap"><?= htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES) ?></small></td>
                        <td><span class="badge rounded-pill bg-light text-dark border"><?= $userLabel ?></span></td>
                        <td><span class="branch-audit-action <?= $cls ?>"><?= htmlspecialchars(ledgerAuditActionLabel($action), ENT_QUOTES) ?></span></td>
                        <td>
                            <?php if ($targetId > 0): ?>
                            <a href="<?= BASE_URL ?>ledger/show/<?= $targetId ?>" class="text-decoration-none fw-semibold">
                                <?= $ledgerLabel ?>
                            </a>
                            <div class="small text-muted">#<?= $targetId ?></div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
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
            Stored in audit log · Filter prefix <code>ledger_</code>
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
        language: { emptyTable: 'No ledger audit logs found yet.', search: 'Filter logs:' }
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
