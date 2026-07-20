<?php
$title = $title ?? 'Sales Audit Logs';
$logs = $logs ?? [];
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-pos.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-audit.css">
<meta name="theme-color" content="#4f46e5">

<div id="sales-audit-app" class="sales-audit-app container-fluid py-2">
    <header class="sales-audit-hero">
        <div>
            <h1><i class="fas fa-clipboard-list me-2"></i>Sales Audit Logs</h1>
            <p>Invoices, godown, challan, payments, credit overrides</p>
            <span class="sales-today-branch-tag"><i class="fas fa-history me-1"></i>Last <?= count($logs) ?> entries</span>
        </div>
        <div class="sales-audit-hero-actions d-flex gap-2 flex-shrink-0 flex-wrap">
            <a href="<?= BASE_URL ?>sales/today" class="btn btn-light btn-sm">
                <i class="fas fa-receipt"></i> Today's Sales
            </a>
            <a href="<?= BASE_URL ?>challan" class="btn btn-outline-light btn-sm">
                <i class="fas fa-warehouse"></i> Godown
            </a>
            <a href="<?= BASE_URL ?>sales/audit" class="btn btn-outline-light btn-sm">
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
                        <th style="width:90px;">Ref ID</th>
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
                                    <p class="mb-0">No sales audit entries yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $action = htmlspecialchars($log['action'] ?? '', ENT_QUOTES);
                            $details = $log['details'] ?? [];
                            $parts = [];
                            foreach (['invoice_code', 'challan_code', 'return_code', 'payment_code', 'total_amount', 'amount', 'items_reversed', 'transport_adjustment', 'override_reason', 'reason'] as $k) {
                                if (!empty($details[$k])) {
                                    $parts[] = '<span class="text-muted">' . htmlspecialchars($k, ENT_QUOTES) . ':</span> '
                                        . htmlspecialchars((string)$details[$k], ENT_QUOTES);
                                }
                            }
                            if (!empty($details['branch_id'])) {
                                $parts[] = 'branch ' . (int)$details['branch_id'];
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
        <div class="sales-audit-footer">
            <i class="fas fa-file-alt me-1"></i>
            Append-only log: <code>logs/user_audit.log</code>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';