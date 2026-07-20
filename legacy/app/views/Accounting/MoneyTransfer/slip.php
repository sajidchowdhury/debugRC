<?php
ob_start();
$t = $transfer ?? [];
$accounts = $accounts ?? ['from' => '', 'to' => ''];
$settlements = $settlements ?? [];
$typeLabel = match (strtolower((string)($t['transfer_type'] ?? ''))) {
    'cash_to_bank' => 'Cash → Bank',
    'bank_to_cash' => 'Bank → Cash',
    'cash_to_cash' => 'Cash → Cash',
    'bank_to_bank' => 'Bank → Bank',
    default => ucfirst(str_replace('_', ' ', (string)($t['transfer_type'] ?? ''))),
};
$title = 'Money Transfer Slip — ' . ($t['transfer_code'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/money-transfer-slip.css">
<div class="mt-slip">
    <header class="mt-slip-head text-center">
        <h2>Remote Center ERP</h2>
        <h3>Money Transfer Slip</h3>
        <p class="mt-slip-code"><?= htmlspecialchars($t['transfer_code'] ?? '', ENT_QUOTES) ?></p>
        <?php if (!empty($t['is_reversed'])): ?>
        <p class="mt-slip-reversed">REVERSED</p>
        <?php endif; ?>
    </header>

    <div class="mt-slip-grid">
        <div>
            <div class="label">From</div>
            <div class="value"><?= htmlspecialchars($accounts['from'] ?? '', ENT_QUOTES) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($t['from_branch_name'] ?? '', ENT_QUOTES) ?></div>
        </div>
        <div>
            <div class="label">To</div>
            <div class="value"><?= htmlspecialchars($accounts['to'] ?? '', ENT_QUOTES) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($t['to_branch_name'] ?? '', ENT_QUOTES) ?></div>
        </div>
    </div>

    <table class="mt-slip-table">
        <tr><th>Type</th><td><?= htmlspecialchars($typeLabel, ENT_QUOTES) ?></td></tr>
        <tr><th>Date</th><td><?= date('d M Y', strtotime($t['transfer_date'] ?? 'now')) ?></td></tr>
        <tr><th>Amount</th><td class="amount">Tk <?= number_format((float)($t['amount'] ?? 0), 2) ?></td></tr>
        <tr><th>Narration</th><td><?= htmlspecialchars($t['narration'] ?? '—', ENT_QUOTES) ?></td></tr>
    </table>

    <?php if (!empty($settlements)): ?>
    <div class="mt-slip-settlements">
        <div class="label">Branch demand settled</div>
        <ul>
        <?php foreach ($settlements as $s): ?>
            <li><?= htmlspecialchars($s['demand_code'] ?? ('#' . ($s['demand_id'] ?? '')), ENT_QUOTES) ?>
                — Tk <?= number_format((float)($s['settled_amount'] ?? 0), 2) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <footer class="mt-slip-foot text-center">
        <p>Created by: <?= htmlspecialchars($t['created_by_name'] ?? '—', ENT_QUOTES) ?></p>
        <p class="sig">Authorized signature: _______________________</p>
    </footer>
</div>
<script>window.addEventListener('load', () => window.print());</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>