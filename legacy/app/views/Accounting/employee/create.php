<?php
ob_start();
$title = $title ?? 'New employee transaction';
$employees = $employees ?? [];
$banks = $banks ?? [];
$today = $today ?? date('Y-m-d');
$branch_name = $branch_name ?? 'Branch';
$employeePayableStatus = $employee_payable_status ?? ['configured' => true, 'count' => 1];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/employee-transaction-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">

<div class="branch-hub emp-txn-theme acct-money-app container-fluid py-2" id="employeeTransactionCreate">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus me-2"></i>New employee transaction</h1>
            <p>Record advance, loan, salary, repayment, or deduction — manual voucher only.</p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($branch_name, ENT_QUOTES) ?> · Tk</span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>EmployeeTransaction" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All transactions
            </a>
        </div>
    </header>

    <?php if (empty($employeePayableStatus['configured'])): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-3">
        <i class="fas fa-triangle-exclamation me-1"></i>
        <?php if ((int)($employeePayableStatus['count'] ?? 0) === 0): ?>
        No active chart account with nature <code>employee_payable</code> — GL posting will fail until one is configured.
        <?php else: ?>
        Multiple active <code>employee_payable</code> ledgers found (<?= (int)$employeePayableStatus['count'] ?>).
        Keep exactly one control account in the chart of accounts.
        <?php endif; ?>
        <a href="<?= BASE_URL ?>ledger" class="alert-link ms-1">Open chart of accounts</a>
    </div>
    <?php endif; ?>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form id="employeeTransactionForm" class="needs-validation" novalidate aria-label="New employee transaction">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-user"></i></span>
                        Employee
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="employee_id">Employee <span class="text-danger">*</span></label>
                            <select name="employee_id" id="employee_id" class="form-select" required>
                                <option value="">— Select employee —</option>
                                <?php
                                $pre = (int)($_GET['employee_id'] ?? 0);
                                foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['id'] ?>"<?= $pre === (int)$e['id'] ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES) ?>
                                    <?php if (!empty($e['employee_code'])): ?> (<?= htmlspecialchars($e['employee_code'], ENT_QUOTES) ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="dueSummary" class="emp-txn-due-banner d-none mt-3" role="status" aria-live="polite"></div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap indigo"><i class="fas fa-file-invoice-dollar"></i></span>
                        Transaction
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="transaction_type">Type <span class="text-danger">*</span></label>
                            <select name="transaction_type" id="transaction_type" class="form-select" required>
                                <option value="">— Select —</option>
                                <option value="advance">Advance</option>
                                <option value="loan">Loan</option>
                                <option value="salary">Salary payment</option>
                                <option value="repayment">Repayment</option>
                                <option value="deduction">Deduction</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                            <div id="typeHint" class="emp-txn-type-hint mt-1"></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="amount">Amount (Tk) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="amount" step="0.01" min="0.01" class="form-control" required aria-required="true" inputmode="decimal" autocomplete="off">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="transaction_date">Date</label>
                            <input type="date" name="transaction_date" id="transaction_date" value="<?= htmlspecialchars($today, ENT_QUOTES) ?>" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap slate"><i class="fas fa-wallet"></i></span>
                        Payment mode
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="payment_mode">Mode</label>
                            <select name="payment_mode" id="payment_mode" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank transfer</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6" id="bank_section" style="display:none;">
                            <label class="form-label" for="bank_id">Bank account</label>
                            <select name="bank_id" id="bank_id" class="form-select">
                                <?php foreach ($banks as $b): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['bank_name'] ?? '', ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-comment"></i></span>
                        Remarks
                    </div>
                    <textarea name="narration" class="form-control" rows="3" placeholder="Optional narration…"></textarea>
                </div>

                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Save transaction
                    </button>
                    <a href="<?= BASE_URL ?>EmployeeTransaction" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">GL preview</div>
            <div id="accounting_preview" class="branch-preview-card" role="region" aria-label="GL posting preview" aria-live="polite">
                <p class="text-muted small mb-0">Select employee, type, and amount to preview double-entry.</p>
            </div>
            <div class="branch-aside-tip mt-3">
                <i class="fas fa-arrow-up-right-from-square me-1"></i>
                <strong>Outflow</strong> (advance, loan, salary, adjustment) — Dr employee payable, Cr cash/bank.
            </div>
            <div class="branch-aside-tip mt-2">
                <i class="fas fa-arrow-down me-1"></i>
                <strong>Inflow</strong> (repayment, deduction) — Dr cash/bank, Cr employee payable.
            </div>
            <div class="branch-aside-tip mt-2 text-muted">
                <i class="fas fa-clock me-1"></i>
                Future HR phase: salary sheets from attendance, late deductions, and loan/advance recovery will post through this same control account — not built in this phase.
            </div>
            <div class="branch-preview-card mt-3">
                <div class="aside-title mb-2">Preview</div>
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewEmployee">Employee</div>
                <div class="preview-code" id="previewType">Type</div>
                <div class="mt-2 emp-txn-amount advance" id="previewAmount">Tk 0</div>
            </div>
        </aside>
    </div>
</div>

<script>window.ET_BOOT = {
    baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>,
    glLabels: <?= json_encode($gl_preview_labels ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>
};</script>
<script src="<?= BASE_URL ?>assets/js/accounting-journal-preview.js"></script>
<script src="<?= BASE_URL ?>assets/js/EmployeeTransaction.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';