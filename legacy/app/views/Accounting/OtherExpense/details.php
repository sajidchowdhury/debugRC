<?php
ob_start();
$expense = $expense ?? [];
$journalEntry = $journal_entry ?? null;
$reversingJournal = $reversing_journal ?? null;
$cashLedger = $cash_ledger ?? [];
$canReverse = !empty($can_reverse);

$e = $expense;
$id = (int)($e['id'] ?? 0);
$isReversed = !empty($e['is_reversed']);
$mode = strtolower(trim((string)($e['payment_mode'] ?? 'cash')));
$paidFrom = ($mode === 'bank')
    ? trim(($e['bank_name'] ?? 'Bank') . (!empty($e['bank_account_number']) ? ' · ' . $e['bank_account_number'] : ''))
    : 'Cash';

$title = 'Expense — ' . ($e['expense_code'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/other-expense-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub other-expense-theme acct-money-app container-fluid py-2" id="otherExpenseDetails">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-arrow-up me-2"></i><?= htmlspecialchars($e['expense_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                <?= htmlspecialchars($e['ledger_name'] ?? '—', ENT_QUOTES) ?>
                · Paid from <?= htmlspecialchars($paidFrom, ENT_QUOTES) ?>
            </p>
            <span class="oe-hero-amount">
                Tk <?= number_format((float)($e['amount'] ?? 0), 2) ?>
            </span>
            <span class="hero-badge ms-2">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Active' ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <?php if ($canReverse): ?>
            <button type="button" class="btn btn-warning btn-sm js-oe-reverse"
                    data-expense-id="<?= $id ?>"
                    data-expense-code="<?= htmlspecialchars($e['expense_code'] ?? '', ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse expense
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>OtherExpense/slip/<?= $id ?>" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Slip
            </a>
            <a href="<?= BASE_URL ?>OtherExpense" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="oe-reversed-banner mb-3">
        <i class="fas fa-triangle-exclamation text-danger me-2"></i>
        <strong>This expense was reversed.</strong> GL and cash/bank balances were restored.
        <?php if (!empty($e['reverse_reason'])): ?>
        <div class="small mt-1"><strong>Reason:</strong> <?= htmlspecialchars($e['reverse_reason'], ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if (!empty($e['reversed_at'])): ?>
        <div class="small text-muted">
            <?= date('d M Y, h:i A', strtotime($e['reversed_at'])) ?>
            <?php if (!empty($e['reversed_by_name'])): ?> · <?= htmlspecialchars($e['reversed_by_name'], ENT_QUOTES) ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="oe-detail-grid mb-3">
        <div class="oe-detail-item">
            <div class="label">Date</div>
            <div class="value"><?= date('d M Y', strtotime($e['expense_date'] ?? 'now')) ?></div>
        </div>
        <div class="oe-detail-item">
            <div class="label">Branch</div>
            <div class="value"><?= htmlspecialchars($e['branch_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="oe-detail-item">
            <div class="label">Expense head</div>
            <div class="value"><?= htmlspecialchars($e['ledger_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="oe-detail-item">
            <div class="label">Mode</div>
            <div class="value"><?= strtoupper(htmlspecialchars($mode, ENT_QUOTES)) ?></div>
        </div>
        <div class="oe-detail-item">
            <div class="label">Paid from</div>
            <div class="value"><?= htmlspecialchars($paidFrom, ENT_QUOTES) ?></div>
        </div>
        <div class="oe-detail-item">
            <div class="label">GL journal</div>
            <div class="value"><?= !empty($e['journal_entry_id'])
                ? 'JE #' . (int)$e['journal_entry_id']
                : '<span class="text-muted">—</span>' ?></div>
        </div>
        <div class="oe-detail-item">
            <div class="label">Created by</div>
            <div class="value"><?= htmlspecialchars($e['created_by_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
    </div>

    <?php if (!empty($e['remarks'])): ?>
    <div class="branch-form-section mb-3 p-3">
        <div class="branch-form-section-head mb-2">
            <span class="icon-wrap red"><i class="fas fa-comment"></i></span> Narration
        </div>
        <p class="mb-0 small"><?= nl2br(htmlspecialchars($e['remarks'], ENT_QUOTES)) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($journalEntry): ?>
    <?php
    $journal_entry = $journalEntry;
    $journal_card_show_report_link = true;
    include __DIR__ . '/../../partials/journal_entry_card.php';
    ?>
    <?php elseif (!$isReversed): ?>
    <?php
    $journal_entry = null;
    $journal_card_empty_message = 'No journal entry found for this expense.';
    include __DIR__ . '/../../partials/journal_entry_card.php';
    ?>
    <?php endif; ?>

    <?php if ($isReversed && $reversingJournal): ?>
    <?php
    $journal_entry = $reversingJournal;
    $journal_card_title = 'Reversing journal';
    $journal_card_css_class = 'border border-danger';
    $journal_card_show_report_link = true;
    include __DIR__ . '/../../partials/journal_entry_card.php';
    ?>
    <?php endif; ?>

    <?php if (!empty($cashLedger)): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-money-bill-wave me-1"></i> Cash ledger</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
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
                <?php foreach ($cashLedger as $le): ?>
                <tr class="<?= !empty($le['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                    <td class="small"><?= !empty($le['transaction_date']) ? date('d M Y', strtotime($le['transaction_date'])) : '' ?></td>
                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($le['reference_type'] ?? '', ENT_QUOTES) ?></span></td>
                    <td class="text-end"><?= (float)($le['debit'] ?? 0) > 0 ? number_format((float)$le['debit'], 2) : '—' ?></td>
                    <td class="text-end"><?= (float)($le['credit'] ?? 0) > 0 ? number_format((float)$le['credit'], 2) : '—' ?></td>
                    <td class="text-end"><?= number_format((float)($le['running_balance'] ?? 0), 2) ?></td>
                    <td><?= !empty($le['is_reversed']) ? '<span class="badge bg-secondary">Rev</span>' : '<span class="badge bg-success">OK</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <div class="acct-sticky-actions d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>ledger" class="btn btn-outline-primary btn-sm">Ledgers</a>
        <a href="<?= BASE_URL ?>OtherExpense" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>
</div>

<script>window.OE_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/OtherExpense.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';