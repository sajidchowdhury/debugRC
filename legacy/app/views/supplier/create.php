<?php
ob_start();
$title = $title ?? 'Create New Supplier';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-theme.css">

<div class="branch-hub supplier-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-truck me-2"></i>New supplier</h1>
            <p>Add a vendor for purchase orders, GRN/receive, and supplier_ledger AP tracking.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>supplier" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>supplier/store" id="supplierForm">
                <?php $isEdit = false; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create supplier
                    </button>
                    <a href="<?= BASE_URL ?>supplier" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Live preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewName">Supplier name</div>
                <div class="preview-code" id="previewMobile">Mobile</div>
            </div>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                Supplier code is assigned automatically. Mobile must be unique across active vendors.
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const nameEl = document.getElementById('supplier_name');
    const mobileEl = document.getElementById('mobile');
    const previewName = document.getElementById('previewName');
    const previewMobile = document.getElementById('previewMobile');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const name = (nameEl?.value || '').trim();
        const mobile = (mobileEl?.value || '').trim();
        if (previewName) previewName.textContent = name || 'Supplier name';
        if (previewMobile) previewMobile.textContent = mobile || 'Mobile';
        if (previewAvatar) previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
    }

    nameEl?.addEventListener('input', updatePreview);
    mobileEl?.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';