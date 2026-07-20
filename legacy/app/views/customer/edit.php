<?php
ob_start();
$title = $title ?? 'Edit Customer';
$customer = $customer ?? [];
$salesPersons = $salesPersons ?? [];
$usage = $usage ?? [
    'outstanding_balance' => 0,
    'sales_count' => 0,
    'can_deactivate' => true,
    'has_outstanding' => false,
    'has_sales_history' => false,
];
$customerId = (int)($customer['id'] ?? 0);
$isActive = !empty($customer['is_active']);
$balance = (float)($usage['outstanding_balance'] ?? 0);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/customer-theme.css">

<div class="branch-hub customer-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit customer</h1>
            <p>Update <strong><?= htmlspecialchars($customer['shop_name'] ?? '', ENT_QUOTES) ?></strong> — contact, credit, and status.</p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($customer['customer_code'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>customer/show/<?= $customerId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-circle-info me-1"></i> Hub
            </a>
            <a href="<?= BASE_URL ?>customer" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>customer/update/<?= $customerId ?>" id="customerForm">
                <?php
                $isEdit = true;
                $canDeactivate = !empty($usage['can_deactivate']);
                require __DIR__ . '/_form_fields.php';
                ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-1"></i> Save changes
                    </button>
                    <a href="<?= BASE_URL ?>customer" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Customer snapshot</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">
                    <?= htmlspecialchars(substr($customer['shop_name'] ?? '?', 0, 1), ENT_QUOTES) ?>
                </div>
                <div class="preview-name" id="previewName"><?= htmlspecialchars($customer['shop_name'] ?? '', ENT_QUOTES) ?></div>
                <div class="preview-code" id="previewContact"><?= htmlspecialchars($customer['customer_name'] ?? '—', ENT_QUOTES) ?></div>
                <div class="mt-2"><?= $isActive
                    ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
                    : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>' ?></div>
            </div>

            <div class="aside-title">Accounts receivable</div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-hand-holding-dollar text-muted me-1"></i> Balance due</span>
                <strong class="<?= $balance > 0.009 ? 'customer-ar-due' : '' ?>">
                    Tk <?= number_format($balance, 2) ?>
                </strong>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-file-invoice text-muted me-1"></i> Sales invoices</span>
                <strong><?= (int)($usage['sales_count'] ?? 0) ?></strong>
            </div>

            <?php if (!empty($usage['has_outstanding']) || !empty($usage['has_sales_history'])): ?>
            <div class="branch-aside-tip">
                <?php if (!empty($usage['has_outstanding'])): ?>
                    Clear outstanding AR before deactivating from the list.
                <?php elseif (!empty($usage['has_sales_history'])): ?>
                    Posted sales history prevents deactivation — keep as active or archive via policy.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>CustomerTransaction/create?customer_id=<?= $customerId ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-money-bill-wave me-1"></i> Record payment
                </a>
                <a href="<?= BASE_URL ?>sales/create" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-file-invoice me-1"></i> New invoice
                </a>
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const shopEl = document.getElementById('shop_name');
    const personEl = document.getElementById('customer_name');
    const previewName = document.getElementById('previewName');
    const previewContact = document.getElementById('previewContact');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const shop = (shopEl?.value || '').trim();
        const person = (personEl?.value || '').trim();
        if (previewName) previewName.textContent = shop || 'Shop';
        if (previewContact) previewContact.textContent = person || '—';
        if (previewAvatar) previewAvatar.textContent = shop ? shop.charAt(0).toUpperCase() : '?';
    }

    shopEl?.addEventListener('input', updatePreview);
    personEl?.addEventListener('input', updatePreview);
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';