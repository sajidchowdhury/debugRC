<?php
ob_start();
$transaction = $transaction ?? [];
$ledger = $ledger ?? [];
$journalEntry = $journal_entry ?? null;
$supplierDue = (float)($supplier_due ?? 0);
$canReverse = !empty($can_reverse);

$t = $transaction;
$id = (int)($t['id'] ?? 0);
$isReversed = !empty($t['is_reversed']);
$type = (string)($t['transaction_type'] ?? 'payment');

function suppTxnDetailTypeLabel(string $type): string {
    return match ($type) {
        'payment' => 'Payment to supplier',
        'advance' => 'Advance payment',
        'receive' => 'Receive from supplier',
        default => ucfirst($type),
    };
}
$typeCls = preg_replace('/[^a-z_]/', '', strtolower($type)) ?: 'payment';

$title = 'Payment — ' . ($t['payment_code'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-transaction-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub supp-txn-theme acct-money-app container-fluid py-2" id="supplierTransactionDetails">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-receipt me-2"></i><?= htmlspecialchars($t['payment_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                <?= htmlspecialchars($t['supplier_name'] ?? '', ENT_QUOTES) ?>
                · <span class="supp-txn-type-pill <?= htmlspecialchars($typeCls, ENT_QUOTES) ?>"><?= htmlspecialchars(suppTxnDetailTypeLabel($type), ENT_QUOTES) ?></span>
            </p>
            <span class="supp-txn-hero-amount supp-txn-amount <?= htmlspecialchars($typeCls, ENT_QUOTES) ?>">
                Tk <?= number_format((float)($t['amount'] ?? 0), 2) ?>
            </span>
            <span class="hero-badge ms-2">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Active' ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <?php if ($canReverse): ?>
            <button type="button" class="btn btn-warning btn-sm js-supp-reverse"
                    data-payment-id="<?= $id ?>"
                    data-payment-code="<?= htmlspecialchars($t['payment_code'] ?? '', ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse payment
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>SupplierTransaction/slip/<?= $id ?>" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Slip
            </a>
            <a href="<?= BASE_URL ?>SupplierTransaction" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="supp-txn-reversed-banner mb-3">
        <i class="fas fa-triangle-exclamation text-danger me-2"></i>
        <strong>This payment was reversed.</strong> Supplier ledger, GL, and bank movements were undone.
        <?php if (!empty($t['reverse_reason'])): ?>
        <div class="small mt-1"><strong>Reason:</strong> <?= htmlspecialchars($t['reverse_reason'], ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if (!empty($t['reversed_at'])): ?>
        <div class="small text-muted">
            <?= date('d M Y, h:i A', strtotime($t['reversed_at'])) ?>
            <?php if (!empty($t['reversed_by_name'])): ?> · <?= htmlspecialchars($t['reversed_by_name'], ENT_QUOTES) ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="supp-txn-detail-grid mb-3">
        <div class="supp-txn-detail-item">
            <div class="label">Date</div>
            <div class="value"><?= date('d M Y', strtotime($t['payment_date'] ?? 'now')) ?></div>
        </div>
        <div class="supp-txn-detail-item">
            <div class="label">Branch</div>
            <div class="value"><?= htmlspecialchars($t['branch_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="supp-txn-detail-item">
            <div class="label">Mode</div>
            <div class="value"><?= strtoupper(htmlspecialchars($t['payment_mode'] ?? '', ENT_QUOTES)) ?></div>
        </div>
        <div class="supp-txn-detail-item">
            <div class="label">Bank</div>
            <div class="value"><?= htmlspecialchars($t['bank_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="supp-txn-detail-item">
            <div class="label">Paid by</div>
            <div class="value"><?= htmlspecialchars($t['collected_by_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="supp-txn-detail-item">
            <div class="label">Payable now</div>
            <div class="value">Tk <?= number_format($supplierDue, 2) ?></div>
        </div>
        <div class="supp-txn-detail-item">
            <div class="label">GL journal</div>
            <div class="value"><?php if (!empty($t['journal_entry_id'])): ?>
                <a href="<?= BASE_URL ?>Report/JournalEntries?search=1&amp;reference_type=supplier_payment&amp;from_date=<?= urlencode((string)($t['payment_date'] ?? date('Y-m-01'))) ?>&amp;to_date=<?= urlencode((string)($t['payment_date'] ?? date('Y-m-d'))) ?>&amp;q=<?= urlencode((string)($journalEntry['entry_no'] ?? $t['journal_entry_id'])) ?>">
                    <?= htmlspecialchars($journalEntry['entry_no'] ?? ('JE #' . (int)$t['journal_entry_id']), ENT_QUOTES) ?>
                </a>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?></div>
        </div>
        <div class="supp-txn-detail-item">
            <div class="label">Created by</div>
            <div class="value"><?= htmlspecialchars($t['created_by_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
    </div>

    <?php if (!empty($t['remarks'])): ?>
    <div class="branch-form-section mb-3 p-3">
        <div class="branch-form-section-head mb-2">
            <span class="icon-wrap teal"><i class="fas fa-comment"></i></span> Narration
        </div>
        <p class="mb-0 small"><?= nl2br(htmlspecialchars($t['remarks'], ENT_QUOTES)) ?></p>
    </div>
    <?php endif; ?>

    <?php
    $journal_entry = $journalEntry;
    $journal_card_show_report_link = true;
    $journal_card_reference_type = 'supplier_payment';
    $journal_card_reference_id = $id;
    include __DIR__ . '/../../partials/journal_entry_card.php';
    ?>

    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-book-open me-1"></i> Supplier ledger</div>
        <div class="table-responsive">
            <table class="table table-sm supp-txn-ledger-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($ledger)): ?>
                    <tr><td colspan="6" class="text-muted text-center py-3">No ledger rows</td></tr>
                <?php else: ?>
                    <?php foreach ($ledger as $le): ?>
                    <tr class="<?= !empty($le['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td class="small"><?= !empty($le['transaction_date']) ? date('d M Y', strtotime($le['transaction_date'])) : '' ?></td>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($le['reference_type'] ?? '', ENT_QUOTES) ?></span></td>
                        <td class="text-end"><?= (float)($le['debit'] ?? 0) > 0 ? number_format((float)$le['debit'], 2) : '—' ?></td>
                        <td class="text-end"><?= (float)($le['credit'] ?? 0) > 0 ? number_format((float)$le['credit'], 2) : '—' ?></td>
                        <td class="text-end running-bal"><?= number_format((float)($le['running_balance'] ?? 0), 2) ?></td>
                        <td><?= !empty($le['is_reversed']) ? '<span class="badge bg-secondary">Rev</span>' : '<span class="badge bg-success">OK</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="acct-sticky-actions d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>supplier/edit/<?= (int)($t['supplier_id'] ?? 0) ?>" class="btn btn-outline-primary btn-sm">Supplier profile</a>
        <a href="<?= BASE_URL ?>SupplierTransaction" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>
</div>

<script>window.ST_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/SupplierTransaction.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';