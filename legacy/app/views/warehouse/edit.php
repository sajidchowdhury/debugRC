<?php
ob_start();
$title = $title ?? 'Edit Warehouse';
$warehouse = $warehouse ?? [];
$branches = $branches ?? [];
$usage = $usage ?? ['total_qty' => 0, 'product_lines' => 0, 'has_stock' => false, 'pending_dispatches' => 0, 'active_stock_takes' => 0];
$warehouseId = (int)($warehouse['id'] ?? 0);
$isActive = !empty($warehouse['is_active']);
$branchName = '';
foreach ($branches as $b) {
    if ((int)($b['id'] ?? 0) === (int)($warehouse['branch_id'] ?? 0)) {
        $branchName = $b['branch_name'] ?? '';
        break;
    }
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/warehouse-theme.css">

<div class="branch-hub warehouse-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit warehouse</h1>
            <p>Update <strong><?= htmlspecialchars($warehouse['warehouse_name'] ?? '', ENT_QUOTES) ?></strong> — branch, address, and status.</p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($warehouse['warehouse_code'] ?? '', ENT_QUOTES) ?>
                <?php if ($branchName): ?>
                    · <?= htmlspecialchars($branchName, ENT_QUOTES) ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <?php if (Auth::isAdmin()): ?>
            <a href="<?= BASE_URL ?>warehouse/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>warehouse/show/<?= $warehouseId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-warehouse me-1"></i> Hub
            </a>
            <a href="<?= BASE_URL ?>warehouse" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>warehouse/update/<?= $warehouseId ?>" id="warehouseForm">
                <?php $isEdit = true; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-1"></i> Save changes
                    </button>
                    <a href="<?= BASE_URL ?>warehouse" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Warehouse snapshot</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">
                    <?= htmlspecialchars(substr($warehouse['warehouse_name'] ?? '?', 0, 1), ENT_QUOTES) ?>
                </div>
                <div class="preview-name" id="previewName"><?= htmlspecialchars($warehouse['warehouse_name'] ?? '', ENT_QUOTES) ?></div>
                <div class="preview-code" id="previewCode"><?= htmlspecialchars($warehouse['warehouse_code'] ?? '', ENT_QUOTES) ?></div>
                <div class="mt-2"><?= $isActive
                    ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
                    : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>' ?></div>
            </div>

            <div class="aside-title">On-hand stock</div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-cubes text-muted me-1"></i> Total qty</span>
                <strong><?= number_format((float)($usage['total_qty'] ?? 0), 2) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-barcode text-muted me-1"></i> Product lines</span>
                <strong><?= (int)($usage['product_lines'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-truck text-muted me-1"></i> Pending dispatches</span>
                <strong><?= (int)($usage['pending_dispatches'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-clipboard-check text-muted me-1"></i> Active stock takes</span>
                <strong><?= (int)($usage['active_stock_takes'] ?? 0) ?></strong>
            </div>

            <?php
            $whBlocked = !empty($usage['has_stock'])
                || ((int)($usage['pending_dispatches'] ?? 0) > 0)
                || ((int)($usage['active_stock_takes'] ?? 0) > 0);
            if ($whBlocked):
            ?>
            <div class="branch-aside-tip">
                Clear stock, resolve pending dispatches, and finish active stock takes before deactivating or reassigning branch.
            </div>
            <?php endif; ?>

            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>StockAdjustment" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-sliders me-1"></i> Stock adjustment
                </a>
                <a href="<?= BASE_URL ?>WarehouseTransfer" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-right-left me-1"></i> Transfer stock
                </a>
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const nameEl = document.getElementById('warehouse_name');
    const codeEl = document.getElementById('warehouse_code');
    const previewName = document.getElementById('previewName');
    const previewCode = document.getElementById('previewCode');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const name = (nameEl?.value || '').trim();
        const code = (codeEl?.value || '').trim();
        if (previewName) previewName.textContent = name || 'Warehouse name';
        if (previewCode) previewCode.textContent = code || 'WH-CODE';
        if (previewAvatar) previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
    }

    nameEl?.addEventListener('input', updatePreview);
    codeEl?.addEventListener('input', updatePreview);
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';