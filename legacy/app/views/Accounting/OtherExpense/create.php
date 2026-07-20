<?php
ob_start();
$title = 'Record Other Expense';
$today = $today ?? date('Y-m-d');
$ledgers = $ledgers ?? [];
$banks = $banks ?? [];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/other-expense-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">

<div class="branch-hub other-expense-theme acct-money-app container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-arrow-up me-2"></i>Record Other Expense</h1>
            <p>Debit expense head · Credit cash/bank</p>
            <span class="hero-badge"><?= htmlspecialchars($branch_name ?? 'Branch', ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>OtherExpense" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form id="otherExpenseForm" novalidate aria-label="Record other expense">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

                <div class="branch-form-section">
                    <div class="branch-form-section-head"><span class="icon-wrap red"><i class="fas fa-file-invoice"></i></span> Voucher</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="expense_date">Date <span class="text-danger">*</span></label>
                            <?php
                            $minPosting = $min_posting_date ?? null;
                            $expenseDate = $today;
                            if ($minPosting && $expenseDate < $minPosting) {
                                $expenseDate = $minPosting;
                            }
                            ?>
                            <input type="date" name="expense_date" id="expense_date" class="form-control"
                                   value="<?= htmlspecialchars($expenseDate, ENT_QUOTES) ?>"
                                   <?= $minPosting ? 'min="' . htmlspecialchars($minPosting, ENT_QUOTES) . '"' : '' ?>
                                   required aria-required="true">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="amount">Amount (Tk) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="amount" step="0.01" min="0.01" class="form-control" required aria-required="true" inputmode="decimal" autocomplete="off">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Expense head <span class="text-danger">*</span></label>
                            <select name="ledger_id" id="ledger_id" class="form-select" required>
                                <option value="">— Select expense account —</option>
                                <?php foreach ($ledgers as $l): ?>
                                <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['ledger_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Narration</label>
                            <textarea name="narration" class="form-control" rows="2" placeholder="Purpose of payment…"></textarea>
                        </div>
                    </div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head"><span class="icon-wrap slate"><i class="fas fa-wallet"></i></span> Paid from</div>
                    <div class="btn-group w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="payment_mode" id="mode_cash" value="cash" checked>
                        <label class="btn btn-outline-danger" for="mode_cash">Cash</label>
                        <input type="radio" class="btn-check" name="payment_mode" id="mode_bank" value="bank">
                        <label class="btn btn-outline-danger" for="mode_bank">Bank</label>
                    </div>
                    <div id="bank_section" style="display:none">
                        <label class="form-label">Bank account <span class="text-danger">*</span></label>
                        <select name="bank_id" class="form-select">
                            <option value="">— Select bank —</option>
                            <?php foreach ($banks as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars(($b['bank_name'] ?? '') . ' — ' . ($b['account_number'] ?? ''), ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="branch-form-footer">
                    <a href="<?= BASE_URL ?>OtherExpense" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i> Save Expense</button>
                </div>
            </form>
        </div>
        <aside class="branch-form-aside">
            <div class="aside-title">GL preview</div>
            <div id="accounting_preview" class="branch-preview-card"><p class="text-muted small mb-0">Select head and amount.</p></div>
        </aside>
    </div>
</div>
<script>window.OE_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/OtherExpense.js"></script>
<?php $content = ob_get_clean(); require_once '../app/views/layouts/main.php'; ?>