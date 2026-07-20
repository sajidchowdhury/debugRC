<?php
ob_start();
$title = 'New Money Transfer';
$banks = $banks ?? [];
$branches = $branches ?? [];
$current_branch_id = $current_branch_id ?? 1;
$current_branch_name = $current_branch_name ?? 'Head Office';
$today = $today ?? date('Y-m-d');
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/money-transfer-theme.css">

<input type="hidden" id="base_url" value="<?= BASE_URL ?>">

<div class="branch-hub money-transfer-theme acct-money-app container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-exchange-alt me-2"></i>New Money Transfer</h1>
            <p>Move funds between cash points and bank accounts across branches</p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($current_branch_name, ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>MoneyTransfer" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All Transfers
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form id="moneyTransferForm" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap orange"><i class="fas fa-exchange-alt"></i></span>
                        Transfer Type
                    </div>
                    <select name="transfer_type" id="transfer_type" class="form-select" required>
                        <option value="">— Select Transfer Type —</option>
                        <option value="cash_to_bank">Cash → Bank (Deposit)</option>
                        <option value="bank_to_cash">Bank → Cash (Withdrawal)</option>
                        <option value="cash_to_cash">Cash → Cash (Inter-Branch)</option>
                        <option value="bank_to_bank">Bank → Bank</option>
                    </select>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-arrows-alt-h"></i></span>
                        From &amp; To
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6" id="from_section">
                            <p class="text-muted small mb-0">Select a transfer type above.</p>
                        </div>
                        <div class="col-md-6" id="to_section"></div>
                    </div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap indigo"><i class="fas fa-coins"></i></span>
                        Amount &amp; Date
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="amount">Amount (Tk) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">৳</span>
                                <input type="number" name="amount" id="amount" step="0.01" min="0.01" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="transfer_date">Date</label>
                            <input type="date" name="transfer_date" id="transfer_date" value="<?= htmlspecialchars($today, ENT_QUOTES) ?>" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap slate"><i class="fas fa-comment"></i></span>
                        Narration
                    </div>
                    <textarea name="narration" class="form-control" rows="3" placeholder="Reason for transfer or additional notes…"></textarea>
                </div>

                <div class="branch-form-footer">
                    <a href="<?= BASE_URL ?>MoneyTransfer" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Confirm Transfer
                    </button>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Accounting impact</div>
            <div id="accounting_preview" class="branch-preview-card">
                <div class="text-center text-muted py-4">
                    <i class="fas fa-balance-scale fa-2x mb-3"></i>
                    <p class="small mb-0">Select transfer type and amount to see double-entry effect</p>
                </div>
            </div>

            <div class="aside-title mt-4">Branch demand (FIFO)</div>
            <div id="demand_preview" class="mt-demand-preview">
                <p class="text-muted small mb-0">Inter-branch cash or cash→bank to another branch can auto-settle open demands.</p>
            </div>

            <div class="aside-title mt-4">Tips</div>
            <div class="branch-aside-tip">
                <strong>Cash → Cash:</strong> Primary path to remit cash and settle inter-branch demands.
            </div>
            <div class="branch-aside-tip mt-2">
                <strong>Cash → Bank (other branch):</strong> Deposits at creditor branch; may settle demands FIFO.
            </div>
            <div class="branch-aside-tip mt-2">
                Reversal undoes GL, cash/bank, and <em>all</em> linked demand settlement journals.
            </div>
        </aside>
    </div>
</div>

<script>
window.MT_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };
window.MT_CURRENT_BRANCH_ID = <?= (int)$current_branch_id ?>;
window.MT_CURRENT_BRANCH_NAME = <?= json_encode($current_branch_name, JSON_THROW_ON_ERROR) ?>;
window.MT_BANKS_OPTIONS = <?= json_encode(array_map(static function ($b) {
    return ['id' => $b['id'], 'label' => ($b['bank_name'] ?? '') . ' - ' . ($b['account_number'] ?? '')];
}, $banks), JSON_THROW_ON_ERROR) ?>.map(b => '<option value="'+b.id+'">'+b.label.replace(/</g,'&lt;')+'</option>').join('');
window.MT_BRANCHES_OPTIONS = <?= json_encode(array_map(static function ($br) {
    return ['id' => $br['id'], 'label' => $br['branch_name'] ?? ''];
}, $branches), JSON_THROW_ON_ERROR) ?>.map(b => '<option value="'+b.id+'">'+b.label.replace(/</g,'&lt;')+'</option>').join('');
</script>
<script src="<?= BASE_URL ?>assets/js/MoneyTransfer.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>