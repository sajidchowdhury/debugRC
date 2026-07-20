<?php
// Receive payment modal body (loaded via AJAX into #receiveModalContent)
$invoice = $invoice ?? [];
$banks = $banks ?? [];
$payments = $payments ?? [];
$grandTotal = (float)($invoice['total_amount'] ?? $invoice['grand_total'] ?? 0);
$amountPaid = (float)($paidTotal ?? $invoice['receive_amount'] ?? $invoice['amount_paid'] ?? 0);
$balance = isset($balanceDue) ? (float)$balanceDue : max(0, round($grandTotal - $amountPaid, 2));
$invoiceCode = htmlspecialchars($invoice['invoice_code'] ?? '—', ENT_QUOTES);
$customerName = htmlspecialchars(trim($invoice['shop_name'] ?? $invoice['customer_name'] ?? '') ?: 'Walk-in', ENT_QUOTES);
$invoiceId = (int)($invoice['id'] ?? 0);
$customerId = (int)($invoice['customer_id'] ?? 0);
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$baseUrl = BASE_URL;
?>
<div class="srp-modal"
     data-invoice-id="<?= $invoiceId ?>"
     data-customer-id="<?= $customerId ?>"
     data-balance="<?= number_format($balance, 2, '.', '') ?>"
     data-base-url="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>"
     data-csrf="<?= $csrf ?>">

    <div class="modal-header srp-modal-header border-0 pb-0">
        <div class="srp-header-main">
            <span class="srp-badge"><i class="fas fa-file-invoice-dollar"></i> <?= $invoiceCode ?></span>
            <h5 class="modal-title srp-title">Receive payment</h5>
            <p class="srp-customer mb-0"><i class="fas fa-user me-1"></i><?= $customerName ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body srp-modal-body pt-2">
        <div class="srp-summary">
            <div class="srp-stat">
                <span class="srp-stat-label">Invoice total</span>
                <span class="srp-stat-value">Tk <?= number_format($grandTotal, 2) ?></span>
            </div>
            <div class="srp-stat">
                <span class="srp-stat-label">Paid so far</span>
                <span class="srp-stat-value srp-stat-paid">Tk <?= number_format($amountPaid, 2) ?></span>
            </div>
            <div class="srp-stat srp-stat-due">
                <span class="srp-stat-label">Balance due</span>
                <span class="srp-stat-value" id="srpBalanceDisplay">Tk <?= number_format($balance, 2) ?></span>
            </div>
        </div>

        <?php if (!empty($payments)): ?>
        <div class="srp-section srp-payments-history">
            <div class="srp-payments-head">
                <span class="srp-label mb-0">Payments on this invoice</span>
                <span class="srp-payments-count"><?= count($payments) ?> recorded</span>
            </div>
            <ul class="srp-payments-list list-unstyled mb-0">
                <?php foreach ($payments as $p):
                    $pid = (int)($p['payment_id'] ?? 0);
                    $pcode = htmlspecialchars($p['payment_code'] ?? '', ENT_QUOTES);
                    $pdate = !empty($p['payment_date']) ? date('d-m-Y', strtotime($p['payment_date'])) : '—';
                    $alloc = (float)($p['allocated_amount'] ?? 0);
                    $mode = strtolower(trim($p['payment_mode'] ?? 'cash'));
                    $modeLabel = $mode === 'bank' || (int)($p['bank_id'] ?? 0) > 0
                        ? 'Bank' . (!empty($p['bank_name']) ? ' · ' . htmlspecialchars($p['bank_name'], ENT_QUOTES) : '')
                        : 'Cash';
                    $by = htmlspecialchars($p['received_by_name'] ?? '—', ENT_QUOTES);
                ?>
                <li class="srp-payment-row" data-payment-id="<?= $pid ?>">
                    <div class="srp-payment-main">
                        <strong><?= $pcode ?></strong>
                        <span class="srp-payment-amt">Tk <?= number_format($alloc, 2) ?></span>
                    </div>
                    <div class="srp-payment-meta">
                        <span><i class="fas fa-calendar-alt"></i> <?= $pdate ?></span>
                        <span><i class="fas fa-wallet"></i> <?= $modeLabel ?></span>
                        <span><i class="fas fa-user"></i> <?= $by ?></span>
                    </div>
                    <div class="srp-payment-actions">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>sales/print_receipt/<?= $invoiceId ?>?payment_id=<?= $pid ?>"
                           class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                            <i class="fas fa-print"></i>
                        </a>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger btn-reverse-payment"
                                data-payment-id="<?= $pid ?>"
                                data-payment-code="<?= $pcode ?>"
                                data-amount="<?= number_format($alloc, 2, '.', '') ?>">
                            <i class="fas fa-undo"></i> Reverse
                        </button>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="srp-section">
            <label class="srp-label" for="srpAmount">Amount to receive</label>
            <div class="srp-amount-wrap">
                <span class="srp-currency">Tk</span>
                <input type="number"
                       class="form-control srp-amount-input"
                       id="srpAmount"
                       min="0.01"
                       step="0.01"
                       max="<?= $balance > 0 ? number_format($balance, 2, '.', '') : '' ?>"
                       value="<?= $balance > 0 ? number_format($balance, 2, '.', '') : '' ?>"
                       placeholder="0.00"
                       inputmode="decimal"
                       autocomplete="off">
            </div>
            <div class="srp-quick-amounts" role="group" aria-label="Quick amounts">
                <button type="button" class="srp-chip" data-srp-quick="half">50%</button>
                <button type="button" class="srp-chip" data-srp-quick="full">Full due</button>
                <button type="button" class="srp-chip" data-srp-quick="clear">Clear</button>
            </div>
            <p class="srp-hint" id="srpAmountHint">
                <?php if ($balance <= 0): ?>
                    This invoice is already fully paid.
                <?php else: ?>
                    Max Tk <?= number_format($balance, 2) ?> for this invoice
                <?php endif; ?>
            </p>
        </div>

        <div class="srp-section">
            <span class="srp-label d-block mb-2">Payment method</span>
            <div class="srp-method-grid" role="radiogroup" aria-label="Payment method">
                <label class="srp-method-card">
                    <input type="radio" name="srp_payment_method" value="cash" checked>
                    <span class="srp-method-inner">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Cash</span>
                    </span>
                </label>
                <label class="srp-method-card">
                    <input type="radio" name="srp_payment_method" value="bank">
                    <span class="srp-method-inner">
                        <i class="fas fa-university"></i>
                        <span>Bank</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="srp-section srp-bank-panel d-none" id="srpBankPanel">
            <label class="srp-label" for="srpBankId">Bank account</label>
            <select class="form-select srp-select" id="srpBankId">
                <option value="">Select bank…</option>
                <?php foreach ($banks as $b):
                    $bankLabel = trim(($b['bank_name'] ?? '') . (!empty($b['account_number']) ? ' · ' . $b['account_number'] : ''));
                    if ($bankLabel === '') continue;
                ?>
                    <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($bankLabel, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="srp-label mt-3" for="srpReference">Reference / cheque no.</label>
            <input type="text" class="form-control srp-input" id="srpReference" placeholder="Optional reference" maxlength="120">
        </div>

        <div class="srp-section">
            <label class="srp-label" for="srpNotes">Notes</label>
            <textarea class="form-control srp-input srp-notes" id="srpNotes" rows="2" placeholder="Optional note for this payment"></textarea>
        </div>
    </div>

    <div class="modal-footer srp-modal-footer border-0">
        <button type="button" class="btn btn-light srp-btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn srp-btn-primary" id="srpSubmit" <?= $balance <= 0 ? 'disabled' : '' ?>>
            <span class="srp-btn-label"><i class="fas fa-check-circle me-1"></i> Record payment</span>
            <span class="srp-btn-loading d-none"><span class="spinner-border spinner-border-sm me-1"></span> Saving…</span>
        </button>
    </div>
</div>