<?php
ob_start();
$income = $income ?? [];
$journalEntry = $journal_entry ?? null;
$reversingJournal = $reversing_journal ?? null;
$cashLedger = $cash_ledger ?? [];
$canReverse = !empty($can_reverse);

$i = $income;
$id = (int)($i['id'] ?? 0);
$isReversed = !empty($i['is_reversed']);
$mode = strtolower(trim((string)($i['payment_mode'] ?? 'cash')));
$receivedIn = ($mode === 'bank')
    ? trim(($i['bank_name'] ?? 'Bank') . (!empty($i['bank_account_number']) ? ' · ' . $i['bank_account_number'] : ''))
    : 'Cash';

$title = 'Income — ' . ($i['income_code'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/other-income-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub other-income-theme acct-money-app container-fluid py-2" id="otherIncomeDetails">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-arrow-down me-2"></i><?= htmlspecialchars($i['income_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                <?= htmlspecialchars($i['ledger_name'] ?? '—', ENT_QUOTES) ?>
                · Received in <?= htmlspecialchars($receivedIn, ENT_QUOTES) ?>
            </p>
            <span class="oi-hero-amount">
                Tk <?= number_format((float)($i['amount'] ?? 0), 2) ?>
            </span>
            <span class="hero-badge ms-2">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Active' ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <?php if ($canReverse): ?>
            <button type="button" class="btn btn-warning btn-sm js-oi-reverse"
                    data-income-id="<?= $id ?>"
                    data-income-code="<?= htmlspecialchars($i['income_code'] ?? '', ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse income
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>OtherIncome/slip/<?= $id ?>" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Slip
            </a>
            <a href="<?= BASE_URL ?>OtherIncome" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="oi-reversed-banner mb-3">
        <i class="fas fa-triangle-exclamation text-danger me-2"></i>
        <strong>This income was reversed.</strong> GL and cash/bank balances were restored.
        <?php if (!empty($i['reverse_reason'])): ?>
        <div class="small mt-1"><strong>Reason:</strong> <?= htmlspecialchars($i['reverse_reason'], ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if (!empty($i['reversed_at'])): ?>
        <div class="small text-muted">
            <?= date('d M Y, h:i A', strtotime($i['reversed_at'])) ?>
            <?php if (!empty($i['reversed_by_name'])): ?> · <?= htmlspecialchars($i['reversed_by_name'], ENT_QUOTES) ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="oi-detail-grid mb-3">
        <div class="oi-detail-item">
            <div class="label">Date</div>
            <div class="value"><?= date('d M Y', strtotime($i['income_date'] ?? 'now')) ?></div>
        </div>
        <div class="oi-detail-item">
            <div class="label">Branch</div>
            <div class="value"><?= htmlspecialchars($i['branch_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="oi-detail-item">
            <div class="label">Income head</div>
            <div class="value"><?= htmlspecialchars($i['ledger_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="oi-detail-item">
            <div class="label">Mode</div>
            <div class="value"><?= strtoupper(htmlspecialchars($mode, ENT_QUOTES)) ?></div>
        </div>
        <div class="oi-detail-item">
            <div class="label">Received in</div>
            <div class="value"><?= htmlspecialchars($receivedIn, ENT_QUOTES) ?></div>
        </div>
        <div class="oi-detail-item">
            <div class="label">GL journal</div>
            <div class="value"><?= !empty($i['journal_entry_id'])
                ? 'JE #' . (int)$i['journal_entry_id']
                : '<span class="text-muted">—</span>' ?></div>
        </div>
        <div class="oi-detail-item">
            <div class="label">Created by</div>
            <div class="value"><?= htmlspecialchars($i['created_by_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
    </div>

    <?php if (!empty($i['remarks'])): ?>
    <div class="branch-form-section mb-3 p-3">
        <div class="branch-form-section-head mb-2">
            <span class="icon-wrap green"><i class="fas fa-comment"></i></span> Narration
        </div>
        <p class="mb-0 small"><?= nl2br(htmlspecialchars($i['remarks'], ENT_QUOTES)) ?></p>
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
    $journal_card_empty_message = 'No journal entry found for this income.';
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
        <a href="<?= BASE_URL ?>OtherIncome" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>
</div>

<script>window.OI_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/OtherIncome.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';