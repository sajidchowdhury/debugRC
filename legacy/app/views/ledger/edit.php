<?php
ob_start();
$title = $title ?? 'Edit ledger';
$ledger = $ledger ?? [];
$parentOptions = $parentOptions ?? [];
$usage = $usage ?? ['journal_lines' => 0, 'children' => 0, 'is_active' => true, 'is_system' => false];
$isSystem = !empty($isSystem);
$ledgerId = (int)($ledger['id'] ?? 0);
$isActive = !empty($ledger['is_active']);
$currentNature = (string)($ledger['ledger_nature'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/ledger-theme.css">

<div class="branch-hub ledger-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i><?= $isSystem ? 'View system ledger' : 'Edit ledger' ?></h1>
            <p><strong><?= htmlspecialchars($ledger['ledger_name'] ?? '', ENT_QUOTES) ?></strong> · <span class="branch-code-pill"><?= htmlspecialchars($ledger['ledger_code'] ?? '', ENT_QUOTES) ?></span></p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                <?= $isSystem ? ' · <i class="fas fa-shield-halved"></i> Protected' : '' ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>ledger/show/<?= $ledgerId ?>" class="btn btn-outline-light btn-sm"><i class="fas fa-book-open me-1"></i> Hub</a>
            <a href="<?= BASE_URL ?>ledger" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
            <a href="<?= BASE_URL ?>ledger/audit" class="btn btn-outline-light btn-sm"><i class="fas fa-clock-rotate-left me-1"></i> Audit</a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">

            <?php if ($isSystem): ?>
            <div class="ledger-protected-banner" role="alert">
                <i class="fas fa-shield-halved text-danger me-2"></i>
                <strong>Protected system ledger</strong> — used by JournalPostingService and core reports. Critical fields, status, and delete are blocked server-side.
            </div>
            <?php endif; ?>

            <form method="POST" action="<?= BASE_URL ?>ledger/update/<?= (int)$ledger['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                <div class="row g-4">

                    <!-- Basic Information -->
                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-light py-3">
                                <h5 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2"></i> Basic Information</h5>
                            </div>
                            <div class="card-body">

                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label fw-medium">Ledger Name <span class="text-danger">*</span></label>
                                        <input type="text" name="ledger_name" class="form-control" required 
                                               value="<?= htmlspecialchars($ledger['ledger_name'] ?? '') ?>"
                                               <?= !empty($isSystem) ? 'readonly' : '' ?>>
                                        <?php if (!empty($isSystem)): ?>
                                            <div class="form-text text-danger small"><i class="fas fa-lock me-1"></i> System ledger name is protected</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Account Type</label>
                                        <select name="account_type" class="form-select" <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                            <?php 
                                            $types = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
                                            foreach ($types as $type): 
                                                $selected = ($ledger['account_type'] ?? '') === $type ? 'selected' : '';
                                            ?>
                                                <option value="<?= $type ?>" <?= $selected ?>><?= $type ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!empty($isSystem)): ?>
                                            <input type="hidden" name="account_type" value="<?= htmlspecialchars($ledger['account_type'] ?? '') ?>">
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label fw-medium">Ledger Nature</label>
                                        <select name="ledger_nature" class="form-select" <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                            <option value="">— Select Nature —</option>
                                            <?php
                                            $selectedNature = $currentNature;
                                            require __DIR__ . '/_nature_options.php';
                                            ?>
                                        </select>
                                        <?php if (!empty($isSystem)): ?>
                                            <input type="hidden" name="ledger_nature" value="<?= htmlspecialchars($ledger['ledger_nature'] ?? '') ?>">
                                            <div class="form-text text-danger small"><i class="fas fa-lock me-1"></i> Protected for system ledger</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Accounting Behavior -->
                    <div class="col-lg-5">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-light py-3">
                                <h5 class="mb-0 fw-semibold"><i class="fas fa-balance-scale me-2"></i> Accounting Behavior</h5>
                            </div>
                            <div class="card-body">

                                <div class="mb-3">
                                    <label class="form-label fw-medium">Normal Balance</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="normal_balance" id="nb_debit" value="debit" 
                                               <?= ($ledger['normal_balance'] ?? 'debit') === 'debit' ? 'checked' : '' ?>
                                               <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                        <label class="btn btn-outline-primary <?= !empty($isSystem) ? 'disabled' : '' ?>" for="nb_debit">Debit</label>

                                        <input type="radio" class="btn-check" name="normal_balance" id="nb_credit" value="credit"
                                               <?= ($ledger['normal_balance'] ?? '') === 'credit' ? 'checked' : '' ?>
                                               <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                        <label class="btn btn-outline-primary <?= !empty($isSystem) ? 'disabled' : '' ?>" for="nb_credit">Credit</label>
                                    </div>
                                    <?php if (!empty($isSystem)): ?>
                                        <input type="hidden" name="normal_balance" value="<?= htmlspecialchars($ledger['normal_balance'] ?? 'debit') ?>">
                                        <div class="form-text text-danger small mt-1"><i class="fas fa-lock me-1"></i> Normal balance is protected</div>
                                    <?php endif; ?>
                                </div>

                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_control_account" value="1" id="is_control"
                                           <?= !empty($ledger['is_control_account']) ? 'checked' : '' ?>
                                           <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="is_control">Control Account</label>
                                </div>
                                <?php if (!empty($isSystem)): ?>
                                    <input type="hidden" name="is_control_account" value="<?= !empty($ledger['is_control_account']) ? '1' : '0' ?>">
                                <?php endif; ?>

                                <div class="mb-3" id="control_type_section" style="display: <?= !empty($ledger['is_control_account']) ? 'block' : 'none' ?>;">
                                    <label class="form-label fw-medium">Control Account Type</label>
                                    <select name="control_account_type" class="form-select" <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                        <option value="">— Select Type —</option>
                                        <?php 
                                        $controlTypes = ['customer', 'supplier', 'employee', 'bank', 'other'];
                                        foreach ($controlTypes as $type):
                                            $selected = ($ledger['control_account_type'] ?? '') === $type ? 'selected' : '';
                                        ?>
                                            <option value="<?= $type ?>" <?= $selected ?>><?= ucfirst($type) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($isSystem)): ?>
                                        <input type="hidden" name="control_account_type" value="<?= htmlspecialchars($ledger['control_account_type'] ?? '') ?>">
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>

                <!-- Advanced -->
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-header bg-light py-3">
                        <h5 class="mb-0 fw-semibold"><i class="fas fa-cog me-2"></i> Advanced Settings</h5>
                    </div>
                    <div class="card-body">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Parent Ledger</label>
                                <select name="parent_id" class="form-select" <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                    <?php
                                    $selectedParentId = (int)($ledger['parent_id'] ?? 0);
                                    require __DIR__ . '/_parent_options.php';
                                    ?>
                                </select>
                                <?php if (!empty($isSystem)): ?>
                                    <input type="hidden" name="parent_id" value="<?= (int)($ledger['parent_id'] ?? 0) ?>">
                                    <div class="form-text text-danger small"><i class="fas fa-lock me-1"></i> Hierarchy protected</div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-medium">Sort Order</label>
                                <input type="number" name="sort_order" class="form-control" value="<?= (int)($ledger['sort_order'] ?? 0) ?>"
                                    <?= !empty($isSystem) ? 'readonly' : '' ?>>
                                <?php if (!empty($isSystem)): ?>
                                    <div class="form-text text-muted small">Protected on system ledgers</div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-medium">Status</label>
                                <div class="mt-2">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="is_active" value="1" 
                                               <?= !empty($ledger['is_active']) ? 'checked' : '' ?>
                                               <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                        <label class="form-check-label">Active</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="is_active" value="0" 
                                               <?= empty($ledger['is_active']) ? 'checked' : '' ?>
                                               <?= !empty($isSystem) ? 'disabled' : '' ?>>
                                        <label class="form-check-label">Inactive</label>
                                    </div>
                                </div>
                                <?php if (!empty($isSystem)): ?>
                                    <input type="hidden" name="is_active" value="<?= !empty($ledger['is_active']) ? '1' : '0' ?>">
                                    <div class="form-text text-danger small"><i class="fas fa-lock me-1"></i> Use toggle blocked</div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-medium">Description</label>
                                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($ledger['description'] ?? '') ?></textarea>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="branch-form-footer">
                    <?php if ($isSystem): ?>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-save me-1"></i> Save description
                        </button>
                        <span class="text-muted small ms-2 align-self-center">Critical fields remain locked.</span>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-save me-1"></i> Save changes
                        </button>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>ledger/show/<?= $ledgerId ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>

            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Account snapshot</div>
            <div class="branch-preview-card">
                <div class="branch-avatar"><?= htmlspecialchars(substr(trim($ledger['ledger_name'] ?? '?'), 0, 1), ENT_QUOTES) ?></div>
                <div class="preview-name"><?= htmlspecialchars($ledger['ledger_name'] ?? '', ENT_QUOTES) ?></div>
                <div class="preview-code"><?= htmlspecialchars($ledger['ledger_code'] ?? '', ENT_QUOTES) ?></div>
                <div class="mt-2"><?= $isActive
                    ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
                    : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>' ?></div>
            </div>
            <div class="aside-title">Usage</div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-file-invoice text-muted me-1"></i> Journal lines</span>
                <strong><?= (int)($usage['journal_lines'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-sitemap text-muted me-1"></i> Child accounts</span>
                <strong><?= (int)($usage['children'] ?? 0) ?></strong>
            </div>
            <?php if (!empty($usage['is_control_account'])): ?>
            <div class="branch-aside-tip mt-2">
                <i class="fas fa-diagram-project me-1"></i> Control account — sub-ledgers may post here.
            </div>
            <?php endif; ?>
            <?php if ($isSystem): ?>
            <div class="branch-aside-tip mt-2">
                <i class="fas fa-lock me-1"></i> Do not deactivate or rename without a migration plan.
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const controlCheckbox = document.getElementById('is_control');
    const controlSection = document.getElementById('control_type_section');

    if (controlCheckbox && controlSection) {
        controlCheckbox.addEventListener('change', function() {
            controlSection.style.display = this.checked ? 'block' : 'none';
        });
    }
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>