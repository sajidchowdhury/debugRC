<?php
ob_start();
$title = $title ?? 'Edit Bank Account';
$bank = $bank ?? [];
$usage = $usage ?? ['balance' => 0, 'is_active' => true, 'can_deactivate' => true, 'transaction_count' => 0];
$glLedgerId = (int)($gl_ledger_id ?? 0);
$bankGlLedgers = $bank_gl_ledgers ?? [];
$bankMappingEnabled = !empty($bank_mapping_enabled);
$bankId = (int)($bank['id'] ?? 0);
$isActive = !empty($bank['is_active']);
$balance = (float)($usage['balance'] ?? $bank['balance'] ?? 0);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bank-theme.css">

<div class="branch-hub bank-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Edit bank account</h1>
            <p><strong><?= htmlspecialchars($bank['bank_name'] ?? '', ENT_QUOTES) ?></strong> · <?= htmlspecialchars($bank['account_number'] ?? '', ENT_QUOTES) ?></p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>bank/show/<?= $bankId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-circle-info me-1"></i> Hub
            </a>
            <a href="<?= BASE_URL ?>bank" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
            <a href="<?= BASE_URL ?>bank/audit" class="btn btn-outline-light btn-sm"><i class="fas fa-clock-rotate-left me-1"></i> Audit</a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form id="bank-edit-form" method="POST" action="<?= BASE_URL ?>bank/update/<?= $bankId ?>">
                <?php
                $isEdit = true;
                $canDeactivate = !empty($usage['can_deactivate']);
                require __DIR__ . '/_form_fields.php';
                ?>
                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i> Save changes</button>
                    <a href="<?= BASE_URL ?>bank" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Account snapshot</div>
            <div class="branch-preview-card">
                <div class="branch-avatar"><?= htmlspecialchars(substr(trim($bank['bank_name'] ?? '?'), 0, 1), ENT_QUOTES) ?></div>
                <div class="preview-name"><?= htmlspecialchars($bank['bank_name'] ?? '', ENT_QUOTES) ?></div>
                <div class="preview-code"><?= htmlspecialchars($bank['account_number'] ?? '', ENT_QUOTES) ?></div>
                <div class="mt-2"><?= $isActive
                    ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
                    : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>' ?></div>
            </div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-link text-muted me-1"></i> Linked transactions</span>
                <strong><?= (int)($usage['transaction_count'] ?? 0) ?></strong>
            </div>

            <?php if (!empty($usage['has_balance']) || !empty($usage['has_transaction_history'])): ?>
            <div class="branch-aside-tip">
                <?php if (!empty($usage['has_balance'])): ?>
                    Zero the cash book balance before deactivating.
                <?php elseif (!empty($usage['has_transaction_history'])): ?>
                    Linked payment/transfer history prevents deactivation from the list toggle.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="aside-title">Ledger balance</div>
            <div class="branch-aside-stat">
                <span><i class="fas fa-wallet text-muted me-1"></i> Current balance</span>
                <strong class="bank-balance">Tk <?= number_format($balance, 2) ?></strong>
            </div>
            <?php if ($bankMappingEnabled && $bankGlLedgers !== []): ?>
            <div class="aside-title mt-3">GL posting account</div>
            <p class="small text-muted mb-2">Customer payments and transfers for this bank post to the selected ledger (Phase 5). If unset, Bank Accounts (Control) is used.</p>
            <select name="gl_ledger_id" class="form-select form-select-sm" form="bank-edit-form">
                <option value="">— Default bank control —</option>
                <?php foreach ($bankGlLedgers as $ledger): ?>
                    <option value="<?= (int)$ledger['id'] ?>" <?= $glLedgerId === (int)$ledger['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(($ledger['ledger_code'] ?? '') . ' — ' . ($ledger['ledger_name'] ?? ''), ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                Balance is updated by customer payments, money transfers, and other income/expense — not edited here.
            </div>
            <div class="mt-3 d-grid gap-2">
                <a href="<?= BASE_URL ?>MoneyTransfer/create" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-right-left me-1"></i> Money transfer
                </a>
            </div>
        </aside>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';