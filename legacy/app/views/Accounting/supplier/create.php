<?php
ob_start();
$title = $title ?? 'New supplier payment';
$preselectSupplier = $preselectSupplier ?? null;
$banks = $banks ?? [];
$employees = $employees ?? [];
$today = $today ?? date('Y-m-d');
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-transaction-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">

<div class="branch-hub supp-txn-theme acct-money-app container-fluid py-2" id="supplierTransactionCreate">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus me-2"></i>New supplier payment</h1>
            <p>Record payment, advance, or receive against supplier payables.</p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($branch_name ?? 'Branch', ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>SupplierTransaction" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All payments
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form id="supplierTransactionForm" class="needs-validation" novalidate aria-label="New supplier payment">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-truck"></i></span>
                        Supplier &amp; payer
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <input type="hidden" name="supplier_id" id="supplier_id" value="<?= (int)($preselectSupplier['id'] ?? 0) ?>">
                            <label class="form-label" for="suppTxnSupplierSearch" id="suppTxnSupplierSearchLabel">
                                Supplier <span class="text-danger">*</span>
                            </label>
                            <div class="supp-txn-supplier-picker">
                                <div class="position-relative flex-grow-1">
                                    <input type="text" id="suppTxnSupplierSearch" class="form-control supp-txn-search-input"
                                           placeholder="Search name, mobile, or code…" autocomplete="off"
                                           value="<?= htmlspecialchars($preselectSupplier['supplier_name'] ?? '', ENT_QUOTES) ?>">
                                    <div id="suppTxnSupplierSuggestions" class="supp-txn-suggest-list"></div>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="suppTxnChangeSupplier" title="Change supplier">
                                    Change
                                </button>
                            </div>
                            <div id="suppTxnSupplierRecents" class="supp-txn-recents mt-2"></div>
                            <div id="suppTxnSupplierHubLink" class="small mt-1<?= empty($preselectSupplier) ? ' d-none' : '' ?>">
                                <a href="<?= BASE_URL ?>supplier/show/<?= (int)($preselectSupplier['id'] ?? 0) ?>" id="suppTxnSupplierHubAnchor">
                                    <i class="fas fa-circle-info me-1"></i> Supplier hub
                                </a>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="collected_by">Paid by <span class="text-danger">*</span></label>
                            <select name="collected_by" id="collected_by" class="form-select" required>
                                <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="dueSummary" class="supp-txn-due-banner d-none mt-3" role="status" aria-live="polite"></div>
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
                                <option value="payment">Payment to supplier</option>
                                <option value="advance">Advance payment</option>
                                <option value="receive">Receive from supplier</option>
                            </select>
                            <div id="typeHint" class="supp-txn-type-hint mt-1" role="note"></div>
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
                    <textarea name="narration" class="form-control" rows="3" placeholder="Optional narration…"></textarea>
                </div>

                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Save payment
                    </button>
                    <a href="<?= BASE_URL ?>SupplierTransaction" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">GL preview</div>
            <div id="accounting_preview" class="branch-preview-card" role="region" aria-label="GL posting preview" aria-live="polite">
                <p class="text-muted small mb-0">Select supplier, type, and amount to preview double-entry.</p>
            </div>
            <div class="branch-aside-tip mt-3">
                <i class="fas fa-hand-holding-dollar me-1"></i>
                <strong>Payment / advance</strong> — Dr AP, Cr cash/bank (same control as GRN payable).
            </div>
            <div class="branch-aside-tip mt-2">
                <i class="fas fa-arrow-down me-1"></i>
                <strong>Receive</strong> — Dr cash/bank, Cr AP (refund or credit from supplier).
            </div>
        </aside>
    </div>
</div>

<script>window.ST_BOOT = {
    baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>,
    preselectSupplier: <?= json_encode($preselectSupplier ?: null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>,
    glLabels: <?= json_encode($gl_preview_labels ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>
};</script>
<script src="<?= BASE_URL ?>assets/js/accounting-journal-preview.js"></script>
<script src="<?= BASE_URL ?>assets/js/SupplierTransaction.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
