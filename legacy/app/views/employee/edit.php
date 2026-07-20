<?php
ob_start();
$title = $title ?? 'Edit Employee';
$employee = $employee ?? [];
$branches = $branches ?? [];
$roles = $roles ?? [];
$usage = $usage ?? [];
$employeeId = (int)($employee['id'] ?? 0);
$isActive = !empty($employee['is_active']);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/employee-theme.css">

<div class="branch-hub employee-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit employee</h1>
            <p>Update <strong><?= htmlspecialchars($employee['name'] ?? '', ENT_QUOTES) ?></strong>.</p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($employee['employee_code'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>employee" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>employee/update/<?= $employeeId ?>" enctype="multipart/form-data">
                <?php $isEdit = true; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Save changes</button>
                    <a href="<?= BASE_URL ?>employee" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
        <aside class="branch-form-aside">
            <div class="aside-title">Snapshot</div>
            <div class="branch-preview-card">
                <div class="branch-avatar"><?= htmlspecialchars(substr($employee['name'] ?? '?', 0, 1), ENT_QUOTES) ?></div>
                <div class="preview-name"><?= htmlspecialchars($employee['name'] ?? '', ENT_QUOTES) ?></div>
                <div class="preview-code"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $employee['role'] ?? '')), ENT_QUOTES) ?></div>
            </div>
            <div class="aside-title">Linked data</div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-user-shield text-muted me-1"></i> User account</span>
                <strong><?= !empty($usage['has_active_user']) ? 'Active' : (!empty($usage['has_user_account']) ? 'Inactive' : 'None') ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-file-invoice text-muted me-1"></i> Sales invoices</span>
                <strong><?= (int)($usage['sales_invoices'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-users text-muted me-1"></i> Customers (sales)</span>
                <strong><?= (int)($usage['customers'] ?? 0) ?></strong>
            </div>
            <?php if (!empty($usage['has_user_account']) && !empty($usage['user_id'])): ?>
            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>employee/account/<?= $employeeId ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-id-card-clip me-1"></i> Employee &amp; account
                </a>
                <a href="<?= BASE_URL ?>user/edit/<?= (int)$usage['user_id'] ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-user-shield me-1"></i> Edit login
                </a>
                <a href="<?= BASE_URL ?>user/permission/<?= (int)$usage['user_id'] ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-shield-halved me-1"></i> Permissions
                </a>
            </div>
            <?php else: ?>
            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>employee/account/<?= $employeeId ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-id-card-clip me-1"></i> Employee &amp; account
                </a>
                <a href="<?= BASE_URL ?>user/create?employee_id=<?= $employeeId ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-user-plus me-1"></i> Create login
                </a>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';