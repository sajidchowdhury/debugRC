<?php
/** @var array $product @var array $categories @var array $groups @var array $units @var bool $isEdit */
$product = $product ?? [];
$categories = $categories ?? [];
$groups = $groups ?? [];
$defaultGroupId = $defaultGroupId ?? 1;
$units = $units ?? ['Pcs', 'Carton', 'KG', 'Bag', 'Dobe', 'Set'];
$isEdit = !empty($isEdit);
$publicUrl = $publicUrl ?? (defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL);
$selectedGroupId = (int)($product['group_id'] ?? $defaultGroupId);
?>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-box"></i></span>
        Product identity
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label">Product code</label>
            <input type="text" class="form-control bg-light" readonly
                   value="<?= $isEdit ? htmlspecialchars($product['product_code'] ?? '', ENT_QUOTES) : 'Auto generated' ?>">
        </div>
        <div class="col-12 col-md-8">
            <label class="form-label" for="product_name">Product name <span class="text-danger">*</span></label>
            <input type="text" id="product_name" name="product_name" class="form-control" required
                   placeholder="Enter product name"
                   value="<?= htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="category_id">Category</label>
            <select id="category_id" name="category_id" class="form-select">
                <option value="">— No category —</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"
                        <?= (int)($product['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="group_id">Group <span class="text-danger">*</span></label>
            <select id="group_id" name="group_id" class="form-select" required>
                <?php foreach ($groups as $grp): ?>
                    <option value="<?= (int)$grp['id'] ?>"
                        <?= $selectedGroupId === (int)$grp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($grp['group_name'] ?? '', ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Default for new products: China</div>
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap indigo"><i class="fas fa-scale-balanced"></i></span>
        Unit &amp; packaging
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label" for="unit">Unit <span class="text-danger">*</span></label>
            <select id="unit" name="unit" class="form-select" required>
                <?php foreach ($units as $u): ?>
                    <option value="<?= htmlspecialchars($u, ENT_QUOTES) ?>"
                        <?= ($product['unit'] ?? 'Pcs') === $u ? 'selected' : '' ?>><?= htmlspecialchars($u, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="pcs_per_carton">PCS per carton</label>
            <input type="number" id="pcs_per_carton" name="pcs_per_carton" class="form-control" min="0"
                   value="<?= htmlspecialchars((string)($product['pcs_per_carton'] ?? '0'), ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="safety_stock">Safety stock</label>
            <input type="number" id="safety_stock" name="safety_stock" class="form-control" step="0.01" min="0"
                   value="<?= htmlspecialchars((string)($product['safety_stock'] ?? '0'), ENT_QUOTES) ?>">
            <div class="form-text">Reorder alert threshold</div>
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap amber"><i class="fas fa-image"></i></span>
        Product image
    </div>
    <?php
    $imagePrefix = $isEdit ? 'Edit' : 'Create';
    $existingImage = $isEdit ? ($product['image'] ?? '') : '';
    require __DIR__ . '/_image_field.php';
    ?>
</div>