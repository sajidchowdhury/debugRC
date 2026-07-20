<?php
ob_start();
$title = $title ?? 'Create New Customer';
$salesPersons = $salesPersons ?? [];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/customer-theme.css">

<div class="branch-hub customer-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-user-plus me-2"></i>New customer</h1>
            <p>Add a shop account for invoices, challan, and customer_ledger AR tracking.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>customer" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>customer/store" id="customerForm">
                <?php $isEdit = false; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create customer
                    </button>
                    <a href="<?= BASE_URL ?>customer" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Live preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewName">Shop name</div>
                <div class="preview-code" id="previewContact">Contact person</div>
                <div class="mt-2 small text-muted" id="previewMobile">Mobile</div>
            </div>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                Customer code is assigned automatically. Mobile must be unique across active accounts.
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const shopEl = document.getElementById('shop_name');
    const personEl = document.getElementById('customer_name');
    const mobileEl = document.getElementById('mobile');
    const previewName = document.getElementById('previewName');
    const previewContact = document.getElementById('previewContact');
    const previewMobile = document.getElementById('previewMobile');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const shop = (shopEl?.value || '').trim();
        const person = (personEl?.value || '').trim();
        const mobile = (mobileEl?.value || '').trim();
        if (previewName) previewName.textContent = shop || 'Shop name';
        if (previewContact) previewContact.textContent = person || 'Contact person';
        if (previewMobile) previewMobile.textContent = mobile || 'Mobile';
        if (previewAvatar) previewAvatar.textContent = shop ? shop.charAt(0).toUpperCase() : '?';
    }

    shopEl?.addEventListener('input', updatePreview);
    personEl?.addEventListener('input', updatePreview);
    mobileEl?.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';