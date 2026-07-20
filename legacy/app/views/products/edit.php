<?php
ob_start();
$title = $title ?? 'Edit Product';
$product = $product ?? [];
$categories = $categories ?? [];
$units = $units ?? ['Pcs', 'Carton', 'KG', 'Bag', 'Dobe', 'Set'];
$snapshot = $snapshot ?? ['total_stock' => 0, 'current_price' => 0, 'min_rate' => 0, 'max_rate' => 0, 'default_rate' => 0, 'has_price' => false];
$publicUrl = $publicUrl ?? BASE_URL;
$productId = (int)($product['id'] ?? 0);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit product</h1>
            <p><?= htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES) ?> · <?= htmlspecialchars($product['product_code'] ?? '', ENT_QUOTES) ?></p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>product/price_history/<?= $productId ?>" class="btn btn-outline-light btn-sm"><i class="fas fa-tags me-1"></i> Prices</a>
            <a href="<?= BASE_URL ?>product" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Catalog</a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>product/update/<?= $productId ?>" enctype="multipart/form-data">
                <?php $isEdit = true; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Save changes</button>
                    <a href="<?= BASE_URL ?>product" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <aside class="branch-form-aside">
            <div class="aside-title">Snapshot</div>
            <?php if (!empty($product['image'])): ?>
            <div class="text-center mb-2">
                <img src="<?= htmlspecialchars($publicUrl . $product['image'], ENT_QUOTES) ?>" class="product-img-preview-lg mx-auto" alt="">
            </div>
            <?php endif; ?>
            <div class="branch-aside-stat">
                <span><i class="fas fa-cubes me-1 text-muted"></i> Stock (all WH)</span>
                <strong><?= number_format((float)$snapshot['total_stock'], 2) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-tag me-1 text-muted"></i> Selling range</span>
                <?php if (!empty($snapshot['has_price'])): ?>
                <strong class="product-price-tag d-block">Tk <?= number_format((float)$snapshot['min_rate'], 2) ?> – <?= number_format((float)$snapshot['max_rate'], 2) ?></strong>
                <small class="text-muted">Default: Tk <?= number_format((float)$snapshot['default_rate'], 2) ?></small>
                <?php else: ?>
                <strong class="text-muted">Not set</strong>
                <?php endif; ?>
            </div>
            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>product/price_history/<?= $productId ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-line me-1"></i> Price history</a>
                <a href="<?= BASE_URL ?>report/branch-wise-stock?product_id=<?= $productId ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-warehouse me-1"></i> Branch stock</a>
                <a href="<?= BASE_URL ?>report/product-movement?product_id=<?= $productId ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-right-left me-1"></i> Movement</a>
            </div>
        </aside>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';