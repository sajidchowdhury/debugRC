<?php
ob_start();
$title = $title ?? 'Edit Supplier';
$supplier = $supplier ?? [];
$usage = $usage ?? [
    'outstanding_balance' => 0,
    'purchase_count' => 0,
    'can_deactivate' => true,
    'has_outstanding' => false,
    'has_purchase_history' => false,
];
$supplierId = (int)($supplier['id'] ?? 0);
$isActive = !empty($supplier['is_active']);
$balance = (float)($usage['outstanding_balance'] ?? 0);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-theme.css">

<div class="branch-hub supplier-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit supplier</h1>
            <p>Update <strong><?= htmlspecialchars($supplier['supplier_name'] ?? '', ENT_QUOTES) ?></strong> — contact, address, and status.</p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($supplier['supplier_code'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>supplier/show/<?= $supplierId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-circle-info me-1"></i> Hub
            </a>
            <a href="<?= BASE_URL ?>supplier" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            <a href="<?= BASE_URL ?>supplier/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>supplier/update/<?= $supplierId ?>" id="supplierForm">
                <?php
                $isEdit = true;
                $canDeactivate = !empty($usage['can_deactivate']);
                require __DIR__ . '/_form_fields.php';
                ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-1"></i> Save changes
                    </button>
                    <a href="<?= BASE_URL ?>supplier" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Supplier snapshot</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">
                    <?= htmlspecialchars(substr($supplier['supplier_name'] ?? '?', 0, 1), ENT_QUOTES) ?>
                </div>
                <div class="preview-name" id="previewName"><?= htmlspecialchars($supplier['supplier_name'] ?? '', ENT_QUOTES) ?></div>
                <div class="preview-code" id="previewMobile"><?= htmlspecialchars($supplier['mobile'] ?? '', ENT_QUOTES) ?></div>
                <div class="mt-2"><?= $isActive
                    ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
                    : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>' ?></div>
            </div>

            <div class="aside-title">Accounts payable</div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-file-invoice-dollar text-muted me-1"></i> Payable balance</span>
                <strong class="<?= $balance > 0.009 ? 'supplier-ap-due' : '' ?>">
                    Tk <?= number_format($balance, 2) ?>
                </strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-dolly text-muted me-1"></i> Purchase receives</span>
                <strong><?= (int)($usage['purchase_count'] ?? 0) ?></strong>
            </div>

            <?php if (!empty($usage['has_outstanding']) || !empty($usage['has_purchase_history'])): ?>
            <div class="branch-aside-tip">
                <?php if (!empty($usage['has_outstanding'])): ?>
                    Clear payable balance before deactivating from the list.
                <?php elseif (!empty($usage['has_purchase_history'])): ?>
                    Purchase receive history prevents deactivation from the list toggle.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>SupplierTransaction/create?supplier_id=<?= $supplierId ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-money-bill-wave me-1"></i> Record payment
                </a>
                <a href="<?= BASE_URL ?>PurchaseOrder/create" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-cart-plus me-1"></i> New PO
                </a>
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const nameEl = document.getElementById('supplier_name');
    const mobileEl = document.getElementById('mobile');
    const previewName = document.getElementById('previewName');
    const previewMobile = document.getElementById('previewMobile');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const name = (nameEl?.value || '').trim();
        const mobile = (mobileEl?.value || '').trim();
        if (previewName) previewName.textContent = name || 'Supplier';
        if (previewMobile) previewMobile.textContent = mobile || '—';
        if (previewAvatar) previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
    }

    nameEl?.addEventListener('input', updatePreview);
    mobileEl?.addEventListener('input', updatePreview);
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';