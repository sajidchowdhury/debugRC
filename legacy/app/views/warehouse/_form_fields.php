<?php
/**
 * Shared warehouse form fields.
 * @var array $warehouse
 * @var array $branches
 * @var bool $isEdit
 */
$warehouse = $warehouse ?? [];
$branches = $branches ?? [];
$isEdit = !empty($isEdit);
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$selectedBranch = (int)($warehouse['branch_id'] ?? 0);
?>
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap amber"><i class="fas fa-warehouse"></i></span>
        Warehouse details
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label" for="warehouse_code">Warehouse code <span class="text-danger">*</span></label>
            <input type="text" id="warehouse_code" name="warehouse_code" class="form-control"
                   required placeholder="e.g. WH-DHK-01"
                   value="<?= htmlspecialchars($warehouse['warehouse_code'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-8">
            <label class="form-label" for="warehouse_name">Warehouse name <span class="text-danger">*</span></label>
            <input type="text" id="warehouse_name" name="warehouse_name" class="form-control"
                   required placeholder="e.g. Main Godown"
                   value="<?= htmlspecialchars($warehouse['warehouse_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="branch_id">Branch <span class="text-danger">*</span></label>
            <select id="branch_id" name="branch_id" class="form-select" required>
                <option value="">Select branch</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>"
                        <?= (int)$branch['id'] === $selectedBranch ? 'selected' : '' ?>>
                        <?= htmlspecialchars($branch['branch_name'] ?? '', ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap indigo"><i class="fas fa-location-dot"></i></span>
        Location
    </div>
    <label class="form-label" for="address">Address</label>
    <textarea id="address" name="address" class="form-control" rows="3"
              placeholder="Street, area, city"><?= htmlspecialchars($warehouse['address'] ?? '', ENT_QUOTES) ?></textarea>
</div>

<?php if ($isEdit): ?>
<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-power-off"></i></span>
        Status
    </div>
    <p class="small text-muted mb-2">Cannot deactivate while stock, pending dispatches, or an active stock take remain. Branch cannot be changed while stock or pending dispatches exist.</p>
    <div class="branch-status-toggle">
        <div class="status-option">
            <input type="radio" name="is_active" id="whActive" value="1"
                   <?= !empty($warehouse['is_active']) ? 'checked' : '' ?>>
            <label for="whActive" class="active-opt"><i class="fas fa-circle-check"></i> Active</label>
        </div>
        <div class="status-option">
            <input type="radio" name="is_active" id="whInactive" value="0"
                   <?= empty($warehouse['is_active']) ? 'checked' : '' ?>>
            <label for="whInactive" class="inactive-opt"><i class="fas fa-circle-xmark"></i> Inactive</label>
        </div>
    </div>
</div>
<?php endif; ?>