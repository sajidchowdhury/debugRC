<?php
ob_start();
$transfer = $transfer ?? [];
$journalEntry = $journal_entry ?? null;
$settlements = $settlements ?? [];
$cashLedger = $cash_ledger ?? [];
$branchLedger = $branch_ledger ?? [];
$accounts = $accounts ?? ['from' => '', 'to' => ''];
$canReverse = !empty($can_reverse);

$t = $transfer;
$id = (int)($t['id'] ?? 0);
$isReversed = !empty($t['is_reversed']);
$type = (string)($t['transfer_type'] ?? '');
function mtDetailTypeLabel(string $type): string {
    return match (strtolower(trim($type))) {
        'cash_to_bank' => 'Cash → Bank',
        'bank_to_cash' => 'Bank → Cash',
        'cash_to_cash' => 'Cash → Cash (inter-branch)',
        'bank_to_bank' => 'Bank → Bank',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
}
$typeLabel = mtDetailTypeLabel($type);
$typeCls = preg_replace('/[^a-z_]/', '', strtolower($type)) ?: 'transfer';

$title = 'Transfer — ' . ($t['transfer_code'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/money-transfer-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub money-transfer-theme acct-money-app container-fluid py-2" id="moneyTransferDetails">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-exchange-alt me-2"></i><?= htmlspecialchars($t['transfer_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                <span class="mt-txn-type-pill <?= htmlspecialchars($typeCls, ENT_QUOTES) ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES) ?></span>
                · <?= htmlspecialchars($accounts['from'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($accounts['to'] ?? '', ENT_QUOTES) ?>
            </p>
            <span class="mt-txn-amount mt-txn-hero-amount">
                Tk <?= number_format((float)($t['amount'] ?? 0), 2) ?>
            </span>
            <span class="hero-badge ms-2">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Active' ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <?php if ($canReverse): ?>
            <button type="button" class="btn btn-warning btn-sm js-mt-reverse"
                    data-transfer-id="<?= $id ?>"
                    data-transfer-code="<?= htmlspecialchars($t['transfer_code'] ?? '', ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse transfer
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>MoneyTransfer/slip/<?= $id ?>" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Slip
            </a>
            <a href="<?= BASE_URL ?>MoneyTransfer" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="mt-txn-reversed-banner mb-3">
        <i class="fas fa-triangle-exclamation text-danger me-2"></i>
        <strong>This transfer was reversed.</strong> GL, cash/bank, and branch demand settlements were undone.
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

    <div class="mt-txn-detail-grid mb-3">
        <div class="mt-txn-detail-item">
            <div class="label">Date</div>
            <div class="value"><?= date('d M Y', strtotime($t['transfer_date'] ?? 'now')) ?></div>
        </div>
        <div class="mt-txn-detail-item">
            <div class="label">Recorded at</div>
            <div class="value"><?= htmlspecialchars($t['session_branch_name'] ?? $t['from_branch_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="mt-txn-detail-item">
            <div class="label">From branch</div>
            <div class="value"><?= htmlspecialchars($t['from_branch_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="mt-txn-detail-item">
            <div class="label">To branch</div>
            <div class="value"><?= htmlspecialchars($t['to_branch_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
        <div class="mt-txn-detail-item">
            <div class="label">GL journal</div>
            <div class="value"><?= !empty($t['journal_entry_id'])
                ? 'JE #' . (int)$t['journal_entry_id']
                : '<span class="text-muted">—</span>' ?></div>
        </div>
        <div class="mt-txn-detail-item">
            <div class="label">Created by</div>
            <div class="value"><?= htmlspecialchars($t['created_by_name'] ?? '—', ENT_QUOTES) ?></div>
        </div>
    </div>

    <?php if (!empty($t['narration'])): ?>
    <div class="branch-form-section mb-3 p-3">
        <div class="branch-form-section-head mb-2">
            <span class="icon-wrap orange"><i class="fas fa-comment"></i></span> Narration
        </div>
        <p class="mb-0 small"><?= nl2br(htmlspecialchars($t['narration'], ENT_QUOTES)) ?></p>
    </div>
    <?php endif; ?>

    <?php
    $journal_entry = $journalEntry;
    $journal_card_title = 'General ledger (transfer)';
    $journal_card_show_report_link = true;
    include __DIR__ . '/../../partials/journal_entry_card.php';
    ?>

    <?php if (!empty($settlements)): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-share-alt me-1"></i> Branch demand settlement</div>
        <p class="small text-muted mb-2">FIFO allocation from this transfer (debtor → creditor).</p>
        <table class="table table-sm mb-0">
            <thead><tr><th>Demand</th><th class="text-end">Settled (Tk)</th><th>Demand status</th></tr></thead>
            <tbody>
            <?php foreach ($settlements as $s): ?>
            <tr>
                <td>
                    <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($s['demand_id'] ?? 0) ?>">
                        <?= htmlspecialchars($s['demand_code'] ?? ('#' . ($s['demand_id'] ?? '')), ENT_QUOTES) ?>
                    </a>
                </td>
                <td class="text-end"><?= number_format((float)($s['settled_amount'] ?? 0), 2) ?></td>
                <td><?= htmlspecialchars($s['demand_status'] ?? '', ENT_QUOTES) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if (!empty($cashLedger)): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-money-bill-wave me-1"></i> Cash ledger</div>
        <div class="table-responsive">
            <table class="table table-sm mt-txn-ledger-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Branch</th>
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
                    <td class="small">#<?= (int)($le['branch_id'] ?? 0) ?> · <?= htmlspecialchars($le['cash_point'] ?? '', ENT_QUOTES) ?></td>
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

    <?php if (!empty($branchLedger)): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-code-branch me-1"></i> Inter-branch ledger (settlement)</div>
        <div class="table-responsive">
            <table class="table table-sm mt-txn-ledger-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Pair</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Running</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($branchLedger as $bl): ?>
                <tr class="<?= !empty($bl['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                    <td class="small"><?= !empty($bl['transaction_date']) ? date('d M Y', strtotime($bl['transaction_date'])) : '' ?></td>
                    <td class="small"><?= htmlspecialchars($bl['from_branch_name'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($bl['to_branch_name'] ?? '', ENT_QUOTES) ?></td>
                    <td class="text-end"><?= (float)($bl['debit'] ?? 0) > 0 ? number_format((float)$bl['debit'], 2) : '—' ?></td>
                    <td class="text-end"><?= (float)($bl['credit'] ?? 0) > 0 ? number_format((float)$bl['credit'], 2) : '—' ?></td>
                    <td class="text-end"><?= number_format((float)($bl['running_balance'] ?? 0), 2) ?></td>
                    <td><?= !empty($bl['is_reversed']) ? '<span class="badge bg-secondary">Rev</span>' : '<span class="badge bg-success">OK</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <div class="acct-sticky-actions d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-outline-primary btn-sm">Branch demands</a>
        <a href="<?= BASE_URL ?>MoneyTransfer" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>
</div>

<script>window.MT_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/MoneyTransfer.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>