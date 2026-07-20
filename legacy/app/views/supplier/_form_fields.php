<?php
/**
 * Shared supplier form fields.
 * @var array $supplier
 * @var bool $isEdit
 * @var bool $canDeactivate
 */
$supplier = $supplier ?? [];
$isEdit = !empty($isEdit);
$canDeactivate = $canDeactivate ?? true;
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap amber"><i class="fas fa-truck"></i></span>
        Supplier details
    </div>
    <div class="row g-3">
        <?php if ($isEdit): ?>
        <div class="col-12 col-md-4">
            <label class="form-label">Supplier code</label>
            <input type="text" class="form-control bg-light" readonly
                   value="<?= htmlspecialchars($supplier['supplier_code'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-8">
        <?php else: ?>
        <div class="col-12">
        <?php endif; ?>
            <label class="form-label" for="supplier_name">Supplier name <span class="text-danger">*</span></label>
            <input type="text" id="supplier_name" name="supplier_name" class="form-control" required
                   placeholder="e.g. ABC Traders"
                   value="<?= htmlspecialchars($supplier['supplier_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <?php if (!$isEdit): ?>
        <div class="col-12">
            <p class="small text-muted mb-0"><i class="fas fa-barcode me-1"></i> Code is assigned automatically on save.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-phone"></i></span>
        Contact
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label" for="mobile">Mobile <span class="text-danger">*</span></label>
            <input type="text" id="mobile" name="mobile" class="form-control" required
                   placeholder="01XXXXXXXXX" inputmode="tel"
                   value="<?= htmlspecialchars($supplier['mobile'] ?? '', ENT_QUOTES) ?>">
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap indigo"><i class="fas fa-location-dot"></i></span>
        Address
    </div>
    <textarea id="address" name="address" class="form-control" rows="3"
              placeholder="Area, city"><?= htmlspecialchars($supplier['address'] ?? '', ENT_QUOTES) ?></textarea>
</div>

<?php if ($isEdit): ?>
<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-power-off"></i></span>
        Status
    </div>
    <p class="small text-muted mb-2">Deactivation is blocked while payable balance or purchase receive history exists.</p>
    <div class="branch-status-toggle">
        <div class="status-option">
            <input type="radio" name="is_active" id="supActive" value="1"
                   <?= !empty($supplier['is_active']) ? 'checked' : '' ?>>
            <label for="supActive" class="active-opt"><i class="fas fa-circle-check"></i> Active</label>
        </div>
        <div class="status-option">
            <input type="radio" name="is_active" id="supInactive" value="0"
                   <?= empty($supplier['is_active']) ? 'checked' : '' ?>
                   <?= ($canDeactivate || empty($supplier['is_active'])) ? '' : 'disabled' ?>>
            <label for="supInactive" class="inactive-opt<?= (!$canDeactivate && !empty($supplier['is_active'])) ? ' opacity-50' : '' ?>">
                <i class="fas fa-circle-xmark"></i> Inactive
            </label>
        </div>
    </div>
</div>
<?php endif; ?>