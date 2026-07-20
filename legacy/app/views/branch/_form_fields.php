<?php
/**
 * Shared branch form fields.
 * @var array $branch
 * @var bool $isEdit
 */
$branch = $branch ?? [];
$isEdit = !empty($isEdit);
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-building"></i></span>
        Basic information
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label" for="branch_code">Branch code <span class="text-danger">*</span></label>
            <input type="text" id="branch_code" name="branch_code" class="form-control"
                   required placeholder="e.g. BR-DHK-01"
                   value="<?= htmlspecialchars($branch['branch_code'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-8">
            <label class="form-label" for="branch_name">Branch name <span class="text-danger">*</span></label>
            <input type="text" id="branch_name" name="branch_name" class="form-control"
                   required placeholder="e.g. Dhaka Main Office"
                   value="<?= htmlspecialchars($branch['branch_name'] ?? '', ENT_QUOTES) ?>">
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap indigo"><i class="fas fa-address-book"></i></span>
        Contact
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label" for="phone">Phone</label>
            <input type="text" id="phone" name="phone" class="form-control"
                   placeholder="+880 …"
                   value="<?= htmlspecialchars($branch['phone'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="branch@company.com"
                   value="<?= htmlspecialchars($branch['email'] ?? '', ENT_QUOTES) ?>">
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap amber"><i class="fas fa-location-dot"></i></span>
        Address
    </div>
    <label class="form-label" for="address">Full address</label>
    <textarea id="address" name="address" class="form-control" rows="3"
              placeholder="Street, area, city"><?= htmlspecialchars($branch['address'] ?? '', ENT_QUOTES) ?></textarea>
</div>

<?php if ($isEdit): ?>
<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-power-off"></i></span>
        Status
    </div>
    <p class="small text-muted mb-2">Deactivating requires no active warehouses, employees, open invoices, pending demands, or active users.</p>
    <div class="branch-status-toggle">
        <div class="status-option">
            <input type="radio" name="is_active" id="branchActive" value="1"
                   <?= !empty($branch['is_active']) ? 'checked' : '' ?>>
            <label for="branchActive" class="active-opt"><i class="fas fa-circle-check"></i> Active</label>
        </div>
        <div class="status-option">
            <input type="radio" name="is_active" id="branchInactive" value="0"
                   <?= empty($branch['is_active']) ? 'checked' : '' ?>>
            <label for="branchInactive" class="inactive-opt"><i class="fas fa-circle-xmark"></i> Inactive</label>
        </div>
    </div>
</div>
<?php endif; ?>