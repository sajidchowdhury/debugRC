<?php
ob_start();
$e = $expense ?? [];
$id = (int)($e['id'] ?? 0);
$isReversed = !empty($e['is_reversed']);
$mode = strtolower(trim((string)($e['payment_mode'] ?? 'cash')));
$paidFrom = ($mode === 'bank')
    ? trim(($e['bank_name'] ?? 'Bank') . (!empty($e['bank_account_number']) ? ' — ' . $e['bank_account_number'] : ''))
    : 'Cash';
$title = 'Other Expense Slip — ' . ($e['expense_code'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/other-expense-slip.css">
<div class="oe-slip">
    <header class="oe-slip-head text-center">
        <h2>Remote Center ERP</h2>
        <h3>Other Expense Voucher</h3>
        <p class="oe-slip-code"><?= htmlspecialchars($e['expense_code'] ?? '', ENT_QUOTES) ?></p>
        <?php if ($isReversed): ?>
        <p class="oe-slip-reversed">REVERSED</p>
        <?php endif; ?>
    </header>

    <table class="oe-slip-table">
        <tr><th>Date</th><td><?= date('d M Y', strtotime($e['expense_date'] ?? 'now')) ?></td></tr>
        <tr><th>Branch</th><td><?= htmlspecialchars($e['branch_name'] ?? '—', ENT_QUOTES) ?></td></tr>
        <tr><th>Expense head</th><td><?= htmlspecialchars($e['ledger_name'] ?? '—', ENT_QUOTES) ?></td></tr>
        <tr><th>Amount</th><td class="amount">Tk <?= number_format((float)($e['amount'] ?? 0), 2) ?></td></tr>
        <tr><th>Paid from</th><td><?= htmlspecialchars($paidFrom, ENT_QUOTES) ?> (<?= strtoupper($mode) ?>)</td></tr>
        <?php if (!empty($e['journal_entry_id'])): ?>
        <tr><th>GL journal</th><td>JE #<?= (int)$e['journal_entry_id'] ?></td></tr>
        <?php endif; ?>
        <tr><th>Narration</th><td><?= nl2br(htmlspecialchars($e['remarks'] ?? '—', ENT_QUOTES)) ?></td></tr>
        <?php if ($isReversed && !empty($e['reverse_reason'])): ?>
        <tr><th>Reversal reason</th><td><?= htmlspecialchars($e['reverse_reason'], ENT_QUOTES) ?></td></tr>
        <?php endif; ?>
    </table>

    <footer class="oe-slip-foot text-center">
        <p>Recorded by: <?= htmlspecialchars($e['created_by_name'] ?? '—', ENT_QUOTES) ?></p>
        <p class="sig">Authorized signature: _______________________</p>
        <p class="small text-muted">Printed <?= date('d M Y, h:i A') ?> · ID #<?= $id ?></p>
    </footer>
</div>
<div class="no-print text-center my-3">
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    <a href="<?= BASE_URL ?>OtherExpense/details/<?= $id ?>" class="btn btn-outline-secondary btn-sm">Details</a>
</div>
<script>window.addEventListener('load', () => { if (!window.location.search.includes('noprint')) setTimeout(() => window.print(), 300); });</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';