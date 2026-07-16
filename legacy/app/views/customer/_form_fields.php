<?php
/**
 * Shared customer form fields.
 * @var array $customer
 * @var array $salesPersons
 * @var bool $isEdit
 * @var bool $canDeactivate
 */
$customer = $customer ?? [];
$salesPersons = $salesPersons ?? [];
$isEdit = !empty($isEdit);
$canDeactivate = $canDeactivate ?? true;
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$selectedSales = (int)($customer['sales_person_id'] ?? 0);
?>
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-store"></i></span>
        Shop &amp; contact
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label" for="shop_name">Shop name <span class="text-danger">*</span></label>
            <input type="text" id="shop_name" name="shop_name" class="form-control" required
                   placeholder="e.g. Rahman Traders"
                   value="<?= htmlspecialchars($customer['shop_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="customer_name">Contact person</label>
            <input type="text" id="customer_name" name="customer_name" class="form-control"
                   placeholder="Owner or buyer name"
                   value="<?= htmlspecialchars($customer['customer_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="mobile">Mobile <span class="text-danger">*</span></label>
            <input type="text" id="mobile" name="mobile" class="form-control" required
                   placeholder="01XXXXXXXXX"
                   value="<?= htmlspecialchars($customer['mobile'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="sales_person_id">Sales person</label>
            <select id="sales_person_id" name="sales_person_id" class="form-select">
                <option value="">— Not assigned —</option>
                <?php foreach ($salesPersons as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>"
                        <?= (int)$emp['id'] === $selectedSales ? 'selected' : '' ?>>
                        <?= htmlspecialchars($emp['name'] ?? $emp['employee_name'] ?? '', ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap indigo"><i class="fas fa-location-dot"></i></span>
        Address
    </div>
    <textarea id="address" name="address" class="form-control" rows="3"
              placeholder="Area, city"><?= htmlspecialchars($customer['address'] ?? '', ENT_QUOTES) ?></textarea>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap amber"><i class="fas fa-credit-card"></i></span>
        Credit
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label" for="credit_limit">Credit limit (Tk)</label>
            <input type="number" step="0.01" min="0" id="credit_limit" name="credit_limit" class="form-control"
                   placeholder="0 = no limit set"
                   value="<?= htmlspecialchars((string)($customer['credit_limit'] ?? ''), ENT_QUOTES) ?>">
        </div>
    </div>
</div>

<?php if ($isEdit): ?>
<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-power-off"></i></span>
        Status
    </div>
    <p class="small text-muted mb-2">Deactivation is blocked while there is outstanding AR or posted sales invoices.</p>
    <div class="branch-status-toggle">
        <div class="status-option">
            <input type="radio" name="is_active" id="custActive" value="1"
                   <?= !empty($customer['is_active']) ? 'checked' : '' ?>>
            <label for="custActive" class="active-opt"><i class="fas fa-circle-check"></i> Active</label>
        </div>
        <div class="status-option">
            <input type="radio" name="is_active" id="custInactive" value="0"
                   <?= empty($customer['is_active']) ? 'checked' : '' ?>
                   <?= ($canDeactivate || empty($customer['is_active'])) ? '' : 'disabled' ?>>
            <label for="custInactive" class="inactive-opt<?= (!$canDeactivate && !empty($customer['is_active'])) ? ' opacity-50' : '' ?>">
                <i class="fas fa-circle-xmark"></i> Inactive
            </label>
        </div>
    </div>
</div>
<?php endif; ?>