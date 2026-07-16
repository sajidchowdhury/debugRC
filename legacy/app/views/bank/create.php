<?php
ob_start();
$title = $title ?? 'Create New Bank Account';
$bankGlLedgers = $bank_gl_ledgers ?? [];
$bankMappingEnabled = !empty($bank_mapping_enabled);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bank-theme.css">

<div class="branch-hub bank-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus me-2"></i>New bank account</h1>
            <p>Add a cash book account for customer payments, transfers, and other income/expense.</p>
            <span class="hero-badge"><i class="fas fa-wallet"></i> Balance starts at Tk 0</span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>bank" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>bank/store" id="bankForm">
                <?php $isEdit = false; require __DIR__ . '/_form_fields.php'; ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create account
                    </button>
                    <a href="<?= BASE_URL ?>bank" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Live preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewName">Bank name</div>
                <div class="preview-code" id="previewAccount">Account number</div>
                <div class="mt-2 small text-muted" id="previewBranch">Branch</div>
            </div>
            <?php if ($bankMappingEnabled && $bankGlLedgers !== []): ?>
            <div class="aside-title mt-3">GL posting account</div>
            <p class="small text-muted mb-2">Optional — assign a cash/bank ledger now instead of defaulting to bank control on first edit.</p>
            <select name="gl_ledger_id" class="form-select form-select-sm" form="bankForm">
                <option value="">— Default bank control —</option>
                <?php foreach ($bankGlLedgers as $ledger): ?>
                <option value="<?= (int)$ledger['id'] ?>">
                    <?= htmlspecialchars(($ledger['ledger_code'] ?? '') . ' — ' . ($ledger['ledger_name'] ?? ''), ENT_QUOTES) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                New accounts are <strong>active</strong> by default. Balance updates when you post payments, transfers, or accounting entries — not on this screen.
            </div>
        </aside>
    </div>
</div>

<script>
(function() {
    const nameEl = document.getElementById('bank_name');
    const accountEl = document.getElementById('account_number');
    const branchEl = document.getElementById('branch_name');
    const previewName = document.getElementById('previewName');
    const previewAccount = document.getElementById('previewAccount');
    const previewBranch = document.getElementById('previewBranch');
    const previewAvatar = document.getElementById('previewAvatar');

    function updatePreview() {
        const name = (nameEl?.value || '').trim();
        const account = (accountEl?.value || '').trim();
        const branch = (branchEl?.value || '').trim();
        if (previewName) previewName.textContent = name || 'Bank name';
        if (previewAccount) previewAccount.textContent = account || 'Account number';
        if (previewBranch) previewBranch.textContent = branch || 'Branch (optional)';
        if (previewAvatar) previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
    }

    nameEl?.addEventListener('input', updatePreview);
    accountEl?.addEventListener('input', updatePreview);
    branchEl?.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
