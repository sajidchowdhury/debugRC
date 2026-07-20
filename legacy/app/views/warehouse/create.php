<?php
ob_start();
$title = $title ?? 'Create New Warehouse';
$branches = $branches ?? [];
$preselectBranch = (int)($preselectBranch ?? $_GET['branch'] ?? 0);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/warehouse-theme.css">

<div class="branch-hub warehouse-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus-circle me-2"></i>New warehouse</h1>
            <p>Add a stock location under a branch — used for godown, challan OUT, transfers, and adjustments.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>warehouse" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
            <a href="<?= BASE_URL ?>branch" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sitemap me-1"></i> Branches
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>warehouse/store" id="warehouseForm">
                <?php
                $warehouse = $preselectBranch > 0 ? ['branch_id' => $preselectBranch] : [];
                $isEdit = false;
                require __DIR__ . '/_form_fields.php';
                ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create warehouse
                    </button>
                    <a href="<?= BASE_URL ?>warehouse" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Live preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewName">Warehouse name</div>
                <div class="preview-code" id="previewCode">WH-CODE</div>
                <div class="mt-2 small text-muted" id="previewBranch">Select a branch</div>
            </div>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                Each warehouse belongs to one <strong>branch</strong>. Sales godown and challan stock OUT use this location as SSOT.
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const nameEl = document.getElementById('warehouse_name');
    const codeEl = document.getElementById('warehouse_code');
    const branchEl = document.getElementById('branch_id');
    const previewName = document.getElementById('previewName');
    const previewCode = document.getElementById('previewCode');
    const previewAvatar = document.getElementById('previewAvatar');
    const previewBranch = document.getElementById('previewBranch');

    function updatePreview() {
        const name = (nameEl?.value || '').trim();
        const code = (codeEl?.value || '').trim();
        const branchText = branchEl?.selectedOptions?.[0]?.text?.trim() || 'Select a branch';
        if (previewName) previewName.textContent = name || 'Warehouse name';
        if (previewCode) previewCode.textContent = code || 'WH-CODE';
        if (previewAvatar) previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
        if (previewBranch) previewBranch.textContent = branchText;
    }

    nameEl?.addEventListener('input', updatePreview);
    codeEl?.addEventListener('input', updatePreview);
    branchEl?.addEventListener('change', updatePreview);
    updatePreview();
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';