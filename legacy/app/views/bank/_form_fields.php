<?php
/** @var array $bank @var bool $isEdit @var bool $canDeactivate */
$bank = $bank ?? [];
$isEdit = !empty($isEdit);
$canDeactivate = $canDeactivate ?? true;
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-building-columns"></i></span>
        Account details
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label" for="bank_name">Bank name <span class="text-danger">*</span></label>
            <input type="text" id="bank_name" name="bank_name" class="form-control" required
                   placeholder="e.g. Dutch-Bangla Bank"
                   value="<?= htmlspecialchars($bank['bank_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="account_number">Account number <span class="text-danger">*</span></label>
            <input type="text" id="account_number" name="account_number" class="form-control" required
                   value="<?= htmlspecialchars($bank['account_number'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="branch_name">Branch name</label>
            <input type="text" id="branch_name" name="branch_name" class="form-control"
                   placeholder="e.g. Gulshan"
                   value="<?= htmlspecialchars($bank['branch_name'] ?? '', ENT_QUOTES) ?>">
        </div>
    </div>
</div>

<?php if ($isEdit): ?>
<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap amber"><i class="fas fa-power-off"></i></span>
        Status
    </div>
    <p class="small text-muted mb-2">Deactivation is blocked while balance is non-zero or linked transactions exist.</p>
    <div class="branch-status-toggle">
        <div class="status-option">
            <input type="radio" name="is_active" id="bankActive" value="1"
                   <?= !empty($bank['is_active']) ? 'checked' : '' ?>>
            <label for="bankActive" class="active-opt"><i class="fas fa-circle-check"></i> Active</label>
        </div>
        <div class="status-option">
            <input type="radio" name="is_active" id="bankInactive" value="0"
                   <?= empty($bank['is_active']) ? 'checked' : '' ?>
                   <?= ($canDeactivate || empty($bank['is_active'])) ? '' : 'disabled' ?>>
            <label for="bankInactive" class="inactive-opt<?= (!$canDeactivate && !empty($bank['is_active'])) ? ' opacity-50' : '' ?>">
                <i class="fas fa-circle-xmark"></i> Inactive
            </label>
        </div>
    </div>
</div>
<?php endif; ?>