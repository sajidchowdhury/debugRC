<?php
ob_start();
$title = $title ?? 'Product Audit Logs';
$logs = $logs ?? [];

$countCreated = $countUpdated = $countPrice = $countBulk = 0;
foreach ($logs as $log) {
    $a = (string)($log['action'] ?? '');
    if (str_contains($a, 'created')) $countCreated++;
    elseif (str_contains($a, 'updated')) $countUpdated++;
    elseif (str_contains($a, 'price')) $countPrice++;
    elseif (str_contains($a, 'bulk')) $countBulk++;
}

function productAuditClass(string $action): string {
    if (str_contains($action, 'created')) return 'created';
    if (str_contains($action, 'updated')) return 'updated';
    if (str_contains($action, 'price')) return 'status';
    if (str_contains($action, 'deactivated') || str_contains($action, 'deleted')) return 'other';
    return 'other';
}
function productAuditLabel(string $action): string {
    return match (true) {
        str_contains($action, 'product_created') => 'Created',
        str_contains($action, 'product_updated') => 'Updated',
        str_contains($action, 'product_deactivated') => 'Deactivated',
        str_contains($action, 'product_restored') => 'Restored',
        str_contains($action, 'product_price_added') => 'Price range added',
        str_contains($action, 'product_price_deleted') => 'Price deleted',
        str_contains($action, 'product_bulk') => 'Bulk action',
        str_contains($action, 'product_group') => 'Group',
        str_contains($action, 'category') => 'Category',
        default => $action,
    };
}
function productAuditDetails($details): string {
    if (empty($details) || !is_array($details)) return '<span class="text-muted">—</span>';
    $parts = [];
    if (!empty($details['product_name'])) $parts[] = '<strong>Product:</strong> ' . htmlspecialchars((string)$details['product_name'], ENT_QUOTES);
    if (!empty($details['product_code'])) $parts[] = '<strong>Code:</strong> ' . htmlspecialchars((string)$details['product_code'], ENT_QUOTES);
    if (!empty($details['group_name'])) $parts[] = '<strong>Group:</strong> ' . htmlspecialchars((string)$details['group_name'], ENT_QUOTES);
    if (!empty($details['category_name'])) $parts[] = '<strong>Category:</strong> ' . htmlspecialchars((string)$details['category_name'], ENT_QUOTES);
    if (isset($details['min_rate'], $details['max_rate'], $details['default_rate'])) {
        $parts[] = '<strong>Range:</strong> Tk ' . number_format((float)$details['min_rate'], 2)
            . ' – ' . number_format((float)$details['max_rate'], 2)
            . ' (def. ' . number_format((float)$details['default_rate'], 2) . ')';
    } elseif (!empty($details['sales_rate'])) {
        $parts[] = '<strong>Rate:</strong> Tk ' . number_format((float)$details['sales_rate'], 2);
    }
    if (isset($details['previous_default_rate'])) {
        $parts[] = '<strong>Was:</strong> Tk ' . number_format((float)$details['previous_min_rate'], 2)
            . '–' . number_format((float)$details['previous_max_rate'], 2)
            . ' (def. ' . number_format((float)$details['previous_default_rate'], 2) . ')';
    }
    if (!empty($details['count'])) $parts[] = '<strong>Count:</strong> ' . (int)$details['count'];
    if (empty($parts)) {
        return '<span class="branch-audit-details">' . htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '</span>';
    }
    return '<div class="branch-audit-details">' . implode(' · ', $parts) . '</div>';
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-clock-rotate-left me-2"></i>Product audit trail</h1>
            <p>Creates, updates, price ranges, groups, categories, bulk actions — last 300 product events.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>product/create" class="btn btn-outline-light btn-sm"><i class="fas fa-plus me-1"></i> New product</a>
            <a href="<?= BASE_URL ?>product" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Catalog</a>
        </div>
    </header>

    <div class="branch-audit-summary">
        <span class="branch-audit-chip"><i class="fas fa-list"></i> <?= count($logs) ?> entries</span>
        <span class="branch-audit-chip created"><i class="fas fa-plus"></i> <?= $countCreated ?> created</span>
        <span class="branch-audit-chip updated"><i class="fas fa-pen"></i> <?= $countUpdated ?> updated</span>
        <span class="branch-audit-chip status"><i class="fas fa-tag"></i> <?= $countPrice ?> price</span>
    </div>

    <div class="branch-hub-panel branch-audit-panel">
        <div class="table-responsive">
            <table class="table table-borderless mb-0 w-100" id="auditTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">No product audit logs yet.</td></tr>
                    <?php else: foreach ($logs as $log):
                        $action = (string)($log['action'] ?? '');
                        $cls = productAuditClass($action);
                        $userLabel = htmlspecialchars($log['performed_by_name'] ?? ('#' . (int)($log['performed_by'] ?? 0)), ENT_QUOTES);
                    ?>
                    <tr>
                        <td><small class="text-nowrap"><?= htmlspecialchars($log['timestamp'] ?? '', ENT_QUOTES) ?></small></td>
                        <td><span class="badge rounded-pill bg-light text-dark border"><?= $userLabel ?></span></td>
                        <td><span class="branch-audit-action <?= $cls ?>"><?= htmlspecialchars(productAuditLabel($action), ENT_QUOTES) ?></span></td>
                        <td><strong><?= htmlspecialchars((string)($log['target_user_id'] ?? '—'), ENT_QUOTES) ?></strong></td>
                        <td><?= productAuditDetails($log['details'] ?? []) ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars($log['ip'] ?? 'unknown', ENT_QUOTES) ?></small></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="branch-audit-meta-foot">
            <code>logs/user_audit.log</code> · prefix <code>product_</code>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const rows = $('#auditTable tbody tr').length;
    if (rows < 1 || ($('#auditTable tbody tr td').length === 1 && rows === 1)) return;
    $('#auditTable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf']
    });
});
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
