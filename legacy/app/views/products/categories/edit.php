<?php
ob_start();
$title = $title ?? 'Edit Category';
$category = $category ?? [];
$catId = (int)($category['id'] ?? 0);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit category</h1>
            <p>Update label used across the product catalog.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>product/categories" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Categories</a>
        </div>
    </header>

    <div class="branch-form-layout">
        <div class="branch-form-panel" style="max-width: 640px;">
            <form method="POST" action="<?= BASE_URL ?>product/categoryUpdate/<?= $catId ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-tag"></i></span> Category name
                    </div>
                    <label class="form-label" for="category_name">Name <span class="text-danger">*</span></label>
                    <input type="text" id="category_name" name="category_name" class="form-control" required
                           value="<?= htmlspecialchars($category['category_name'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Save</button>
                    <a href="<?= BASE_URL ?>product/categories" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layouts/main.php';