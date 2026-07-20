<?php
ob_start();
$i = $income ?? [];
$id = (int)($i['id'] ?? 0);
$isReversed = !empty($i['is_reversed']);
$mode = strtolower(trim((string)($i['payment_mode'] ?? 'cash')));
$receivedIn = ($mode === 'bank')
    ? trim(($i['bank_name'] ?? 'Bank') . (!empty($i['bank_account_number']) ? ' — ' . $i['bank_account_number'] : ''))
    : 'Cash';
$title = 'Other Income Slip — ' . ($i['income_code'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/other-income-slip.css">
<div class="oi-slip">
    <header class="oi-slip-head text-center">
        <h2>Remote Center ERP</h2>
        <h3>Other Income Voucher</h3>
        <p class="oi-slip-code"><?= htmlspecialchars($i['income_code'] ?? '', ENT_QUOTES) ?></p>
        <?php if ($isReversed): ?>
        <p class="oi-slip-reversed">REVERSED</p>
        <?php endif; ?>
    </header>

    <table class="oi-slip-table">
        <tr><th>Date</th><td><?= date('d M Y', strtotime($i['income_date'] ?? 'now')) ?></td></tr>
        <tr><th>Branch</th><td><?= htmlspecialchars($i['branch_name'] ?? '—', ENT_QUOTES) ?></td></tr>
        <tr><th>Income head</th><td><?= htmlspecialchars($i['ledger_name'] ?? '—', ENT_QUOTES) ?></td></tr>
        <tr><th>Amount</th><td class="amount">Tk <?= number_format((float)($i['amount'] ?? 0), 2) ?></td></tr>
        <tr><th>Received in</th><td><?= htmlspecialchars($receivedIn, ENT_QUOTES) ?> (<?= strtoupper($mode) ?>)</td></tr>
        <?php if (!empty($i['journal_entry_id'])): ?>
        <tr><th>GL journal</th><td>JE #<?= (int)$i['journal_entry_id'] ?></td></tr>
        <?php endif; ?>
        <tr><th>Narration</th><td><?= nl2br(htmlspecialchars($i['remarks'] ?? '—', ENT_QUOTES)) ?></td></tr>
        <?php if ($isReversed && !empty($i['reverse_reason'])): ?>
        <tr><th>Reversal reason</th><td><?= htmlspecialchars($i['reverse_reason'], ENT_QUOTES) ?></td></tr>
        <?php endif; ?>
    </table>

    <footer class="oi-slip-foot text-center">
        <p>Recorded by: <?= htmlspecialchars($i['created_by_name'] ?? '—', ENT_QUOTES) ?></p>
        <p class="sig">Authorized signature: _______________________</p>
        <p class="small text-muted">Printed <?= date('d M Y, h:i A') ?> · ID #<?= $id ?></p>
    </footer>
</div>
<div class="no-print text-center my-3">
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    <a href="<?= BASE_URL ?>OtherIncome/details/<?= $id ?>" class="btn btn-outline-secondary btn-sm">Details</a>
</div>
<script>window.addEventListener('load', () => { if (!window.location.search.includes('noprint')) setTimeout(() => window.print(), 300); });</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';