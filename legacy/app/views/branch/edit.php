<?php
ob_start();
$title = $title ?? 'Edit Branch';
$branch = $branch ?? [];
$usage = $usage ?? ['warehouses' => 0, 'employees' => 0, 'open_invoices' => 0, 'pending_demands' => 0, 'active_users' => 0];
$branchId = (int)($branch['id'] ?? 0);
$isActive = !empty($branch['is_active']);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="branch-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit branch</h1>
            <p>Update location details and status for <strong><?= htmlspecialchars($branch['branch_name'] ?? '', ENT_QUOTES) ?></strong>.</p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($branch['branch_code'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>branch/show/<?= $branchId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sitemap me-1"></i> Hub
            </a>
            <a href="<?= BASE_URL ?>branch/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="<?= BASE_URL ?>branch" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>branch/update/<?= $branchId ?>" id="branchForm">
                <?php $isEdit = true; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-1"></i> Save changes
                    </button>
                    <a href="<?= BASE_URL ?>branch" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Branch snapshot</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">
                    <?= htmlspecialchars(substr($branch['branch_name'] ?? '?', 0, 1), ENT_QUOTES) ?>
                </div>
                <div class="preview-name" id="previewName"><?= htmlspecialchars($branch['branch_name'] ?? '', ENT_QUOTES) ?></div>
                <div class="preview-code" id="previewCode"><?= htmlspecialchars($branch['branch_code'] ?? '', ENT_QUOTES) ?></div>
                <div class="mt-2"><?= $isActive
                    ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
                    : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>' ?></div>
            </div>

            <div class="aside-title">Linked records</div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-warehouse text-muted me-1"></i> Warehouses</span>
                <strong><?= (int)($usage['warehouses'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-users text-muted me-1"></i> Employees</span>
                <strong><?= (int)($usage['employees'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-receipt text-muted me-1"></i> Open invoices</span>
                <strong><?= (int)($usage['open_invoices'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-truck-ramp-box text-muted me-1"></i> Pending demands</span>
                <strong><?= (int)($usage['pending_demands'] ?? 0) ?></strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-user-shield text-muted me-1"></i> Active users</span>
                <strong><?= (int)($usage['active_users'] ?? 0) ?></strong>
            </div>

            <?php
            $hasBlockers = ((int)($usage['warehouses'] ?? 0) > 0)
                || ((int)($usage['employees'] ?? 0) > 0)
                || ((int)($usage['open_invoices'] ?? 0) > 0)
                || ((int)($usage['pending_demands'] ?? 0) > 0)
                || ((int)($usage['active_users'] ?? 0) > 0);
            if ($hasBlockers):
            ?>
            <div class="branch-aside-tip">
                Resolve linked warehouses, employees, open invoices, pending demands, and active users before deactivating this branch.
            </div>
            <?php endif; ?>

            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>branch/show/<?= $branchId ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-sitemap me-1"></i> Branch hub
                </a>
                <a href="<?= BASE_URL ?>warehouse?branch=<?= $branchId ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-warehouse me-1"></i> Warehouses (filtered)
                </a>
                <?php if (Auth::isAdmin()): ?>
                <a href="<?= BASE_URL ?>warehouse/create?branch=<?= $branchId ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus me-1"></i> Add warehouse
                </a>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const nameEl = document.getElementById('branch_name');
    const codeEl = document.getElementById('branch_code');
    const previewName = document.getElementById('previewName');
    const previewCode = document.getElementById('previewCode');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const name = (nameEl?.value || '').trim();
        const code = (codeEl?.value || '').trim();
        if (previewName) previewName.textContent = name || 'Branch name';
        if (previewCode) previewCode.textContent = code || 'BR-CODE';
        if (previewAvatar) previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
    }

    nameEl?.addEventListener('input', updatePreview);
    codeEl?.addEventListener('input', updatePreview);
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';