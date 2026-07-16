<?php
ob_start();
$title = $title ?? 'Create Category';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus-circle me-2"></i>New category</h1>
            <p>Used in product forms and catalog filters.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>product/categories" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Categories</a>
        </div>
    </header>

    <div class="branch-form-layout">
        <div class="branch-form-panel" style="max-width: 640px;">
            <form method="POST" action="<?= BASE_URL ?>product/categoryStore">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-tag"></i></span> Category name
                    </div>
                    <label class="form-label" for="category_name">Name <span class="text-danger">*</span></label>
                    <input type="text" id="category_name" name="category_name" class="form-control" required
                           placeholder="e.g. Remote — Universal">
                    <div class="form-text">Shown on product create/edit and list filters.</div>
                </div>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-check me-1"></i> Create</button>
                    <a href="<?= BASE_URL ?>product/categories" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layouts/main.php';