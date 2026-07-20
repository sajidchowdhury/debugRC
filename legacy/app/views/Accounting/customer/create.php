<?php
ob_start();
$title = $title ?? 'New customer payment';
$preselectCustomer = $preselectCustomer ?? null;
$banks = $banks ?? [];
$employees = $employees ?? [];
$today = $today ?? date('Y-m-d');
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/customer-transaction-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">

<div class="branch-hub cust-txn-theme acct-money-app container-fluid py-2" id="customerTransactionCreate">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus me-2"></i>New customer payment</h1>
            <p>Record receive, refund, discount, or write-off against customer AR.</p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($branch_name ?? 'Branch', ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>CustomerTransaction" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All payments
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form id="customerTransactionForm" class="needs-validation" novalidate aria-label="New customer payment">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-user"></i></span>
                        Customer &amp; collector
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <input type="hidden" name="customer_id" id="customer_id" value="<?= (int)($preselectCustomer['id'] ?? 0) ?>">
                            <label class="form-label" for="custTxnCustomerSearch" id="custTxnCustomerSearchLabel">
                                Customer <span class="text-danger">*</span>
                            </label>
                            <div class="cust-txn-customer-picker">
                                <div class="position-relative flex-grow-1">
                                    <input type="text" id="custTxnCustomerSearch" class="form-control cust-txn-search-input"
                                           placeholder="Search shop, name, mobile, or code…" autocomplete="off"
                                           value="<?= htmlspecialchars($preselectCustomer['shop_name'] ?? '', ENT_QUOTES) ?>">
                                    <div id="custTxnCustomerSuggestions" class="cust-txn-suggest-list"></div>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="custTxnChangeCustomer" title="Change customer">
                                    Change
                                </button>
                            </div>
                            <div id="custTxnCustomerRecents" class="cust-txn-recents mt-2"></div>
                            <div id="custTxnCustomerHubLink" class="small mt-1<?= empty($preselectCustomer) ? ' d-none' : '' ?>">
                                <a href="<?= BASE_URL ?>customer/show/<?= (int)($preselectCustomer['id'] ?? 0) ?>" id="custTxnCustomerHubAnchor">
                                    <i class="fas fa-circle-info me-1"></i> Customer hub
                                </a>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="collected_by">Collected by <span class="text-danger">*</span></label>
                            <select name="collected_by" id="collected_by" class="form-select" required>
                                <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="dueSummary" class="cust-txn-due-banner d-none mt-3" role="status" aria-live="polite"></div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap indigo"><i class="fas fa-file-invoice-dollar"></i></span>
                        Transaction
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="transaction_type">Type <span class="text-danger">*</span></label>
                            <select name="transaction_type" id="transaction_type" class="form-select" required aria-required="true" aria-describedby="typeHint">
                                <option value="">— Select —</option>
                                <option value="receive">Receive (customer payment)</option>
                                <option value="payment">Payment (refund / advance)</option>
                                <option value="discount">Discount given</option>
                                <option value="write_off">Write-off (bad debt)</option>
                            </select>
                            <div id="typeHint" class="cust-txn-type-hint mt-1" role="note"></div>
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
                            <label class="form-label" for="mode">Mode</label>
                            <select name="mode" id="mode" class="form-select">
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
                    <label class="form-label" for="narration">Remarks</label>
                    <textarea name="narration" id="narration" class="form-control" rows="3" placeholder="Optional narration…"></textarea>
                </div>

                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Save payment
                    </button>
                    <a href="<?= BASE_URL ?>CustomerTransaction" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">GL preview</div>
            <div id="accounting_preview" class="branch-preview-card" role="region" aria-label="GL posting preview" aria-live="polite">
                <p class="text-muted small mb-0">Select customer, type, and amount to preview double-entry.</p>
            </div>
            <div class="branch-aside-tip mt-3">
                <i class="fas fa-hand-holding-dollar me-1"></i>
                <strong>Receive</strong> — Dr cash/bank, Cr AR. <strong>Refund</strong> — Dr AR, Cr cash/bank.
            </div>
            <div class="branch-aside-tip mt-2">
                <i class="fas fa-percent me-1"></i>
                <strong>Discount</strong> — Dr sales discount, Cr AR. <strong>Write-off</strong> — Dr bad debt expense, Cr AR.
            </div>
        </aside>
    </div>
</div>

<script>window.CT_BOOT = {
    baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>,
    preselectCustomer: <?= json_encode($preselectCustomer ?: null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>,
    glLabels: <?= json_encode($gl_preview_labels ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>
};</script>
<script src="<?= BASE_URL ?>assets/js/accounting-journal-preview.js"></script>
<script src="<?= BASE_URL ?>assets/js/CustomerTransaction.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';