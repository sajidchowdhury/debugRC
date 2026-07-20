<?php
ob_start();
$title = $title ?? 'Create New Product';
$categories = $categories ?? [];
$units = $units ?? ['Pcs', 'Carton', 'KG', 'Bag', 'Dobe', 'Set'];
$publicUrl = $publicUrl ?? BASE_URL;
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus-circle me-2"></i>New product</h1>
            <p>Add a SKU to the catalog — code auto-generates; set price from price history after save.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>product" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Catalog</a>
            <a href="<?= BASE_URL ?>product/categories" class="btn btn-outline-light btn-sm"><i class="fas fa-tags me-1"></i> Categories</a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>product/store" enctype="multipart/form-data">
                <?php $product = ['group_id' => $defaultGroupId ?? 1]; $isEdit = false; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-check me-1"></i> Save product</button>
                    <a href="<?= BASE_URL ?>product" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <aside class="branch-form-aside">
            <div class="aside-title">Preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar"><i class="fas fa-box"></i></div>
                <div class="preview-name" id="previewName">Product name</div>
                <div class="preview-code" id="previewCode">Auto code</div>
                <div class="mt-2 small text-muted" id="previewMeta">Pcs · No category</div>
            </div>
            <div class="branch-aside-tip">
                <i class="fas fa-tag me-1"></i> After creating, open <strong>Price history</strong> to set min / max / default selling rates.
            </div>
        </aside>
    </div>
</div>
<script>
(function() {
    const name = document.getElementById('product_name');
    const unit = document.getElementById('unit');
    const cat = document.getElementById('category_id');
    const grp = document.getElementById('group_id');
    function upd() {
        const n = (name?.value || '').trim();
        document.getElementById('previewName').textContent = n || 'Product name';
        document.getElementById('previewAvatar').innerHTML = n ? n.charAt(0).toUpperCase() : '<i class="fas fa-box"></i>';
        const u = unit?.value || 'Pcs';
        const c = cat?.selectedOptions?.[0]?.text?.trim() || 'No category';
        const g = grp?.selectedOptions?.[0]?.text?.trim() || 'China';
        document.getElementById('previewMeta').textContent = u + ' · ' + g + ' · ' + c;
    }
    name?.addEventListener('input', upd);
    unit?.addEventListener('change', upd);
    cat?.addEventListener('change', upd);
    grp?.addEventListener('change', upd);
})();
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';