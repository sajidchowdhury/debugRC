<?php
$title = $title ?? 'Sales Return Audit Logs';
$logs = $logs ?? [];
$branchName = $session_branch_name ?? 'Branch';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-pos.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-audit.css">
<meta name="theme-color" content="#e11d48">

<div id="sales-return-audit-app" class="sales-audit-app container-fluid py-2">
    <header class="sales-audit-hero sales-return-audit-hero">
        <div>
            <h1><i class="fas fa-clipboard-list me-2"></i>Sales Return Audit</h1>
            <p>Create, warehouse confirm, reverse — return workflow only</p>
            <span class="sales-today-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
            <span class="sales-today-branch-tag ms-1"><i class="fas fa-history me-1"></i>Last <?= count($logs) ?> entries</span>
        </div>
        <div class="sales-audit-hero-actions d-flex gap-2 flex-shrink-0 flex-wrap">
            <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-light btn-sm">
                <i class="fas fa-undo-alt"></i> Returns
            </a>
            <a href="<?= BASE_URL ?>SalesReturn/create" class="btn btn-outline-light btn-sm">
                <i class="fas fa-plus"></i> Receive
            </a>
            <a href="<?= BASE_URL ?>SalesReturn/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sync"></i> Refresh
            </a>
        </div>
    </header>

    <section class="sales-audit-panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="min-width:150px;">Time</th>
                        <th style="width:72px;">User</th>
                        <th>Action</th>
                        <th style="width:90px;">Return ID</th>
                        <th>Details</th>
                        <th style="min-width:100px;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="sales-audit-empty">
                                    <i class="fas fa-inbox d-block"></i>
                                    <p class="mb-0">No sales return audit entries yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $action = htmlspecialchars($log['action'] ?? '', ENT_QUOTES);
                            $details = $log['details'] ?? [];
                            $parts = [];
                            foreach (['return_code', 'invoice_code', 'sales_invoice_id', 'total_amount', 'item_count', 'journal_entry_id', 'cogs_amount', 'was_completed', 'reason'] as $k) {
                                if (isset($details[$k]) && $details[$k] !== '' && $details[$k] !== null) {
                                    $val = $details[$k];
                                    if ($k === 'total_amount' || $k === 'cogs_amount') {
                                        $val = 'Tk ' . number_format((float)$val, 2);
                                    }
                                    $parts[] = '<span class="text-muted">' . htmlspecialchars($k, ENT_QUOTES) . ':</span> '
                                        . htmlspecialchars((string)$val, ENT_QUOTES);
                                }
                            }
                            $detailsHtml = $parts
                                ? '<small>' . implode(' <span class="text-muted">·</span> ', $parts) . '</small>'
                                : '<small class="text-muted">—</small>';
                            ?>
                            <tr>
                                <td><small class="text-nowrap"><?= htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES) ?></small></td>
                                <td><span class="badge bg-light text-dark border"><?= (int)($log['performed_by'] ?? 0) ?></span></td>
                                <td><code><?= $action ?></code></td>
                                <td><?= htmlspecialchars((string)($log['target_user_id'] ?? '—'), ENT_QUOTES) ?></td>
                                <td><?= $detailsHtml ?></td>
                                <td><small class="text-muted"><?= htmlspecialchars($log['ip'] ?? '', ENT_QUOTES) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';