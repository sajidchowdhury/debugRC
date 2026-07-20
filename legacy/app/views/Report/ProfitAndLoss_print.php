<?php
/** Standalone print / PDF view for Profit & Loss */
$pl = $reportData ?? ($profit_and_loss ?? []);
$summary = $pl['summary'] ?? [];
$sections = $pl['sections'] ?? [];
$hasCompare = !empty($pl['compare']);
$autoPrint = !empty($pl['_auto_print']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profit &amp; Loss — <?= htmlspecialchars($pl['to_date'] ?? '', ENT_QUOTES) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; }
        th { text-align: left; background: #f5f5f5; }
        .text-end { text-align: right; }
        .section-head { font-weight: bold; background: #eee; }
        .subtotal { font-weight: bold; }
        .net { font-size: 14px; font-weight: bold; border-top: 2px solid #333; }
        @media print { body { margin: 12px; } }
    </style>
    <?php if ($autoPrint): ?>
    <script>window.onload = function () { window.print(); };</script>
    <?php endif; ?>
</head>
<body>
    <h1>Profit &amp; Loss Statement</h1>
    <p class="meta">
        Period: <?= htmlspecialchars($pl['from_date'] ?? '', ENT_QUOTES) ?> to <?= htmlspecialchars($pl['to_date'] ?? '', ENT_QUOTES) ?>
        <?php if ($hasCompare): ?>
        · Compare: <?= htmlspecialchars($pl['compare']['from_date'] ?? '', ENT_QUOTES) ?> to <?= htmlspecialchars($pl['compare']['to_date'] ?? '', ENT_QUOTES) ?>
        <?php endif; ?>
        · Generated <?= date('d M Y H:i') ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th>Nature</th>
                <th class="text-end">Amount</th>
                <?php if ($hasCompare): ?>
                <th class="text-end">Compare</th>
                <th class="text-end">Variance</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sections as $section): ?>
            <tr class="section-head"><td colspan="<?= $hasCompare ? 5 : 3 ?>"><?= htmlspecialchars($section['label'] ?? '', ENT_QUOTES) ?></td></tr>
            <?php foreach ($section['rows'] ?? [] as $row): ?>
            <tr>
                <td><?= htmlspecialchars(($row['ledger_code'] ?? '') . ' ' . ($row['ledger_name'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['nature_label'] ?? '', ENT_QUOTES) ?></td>
                <td class="text-end"><?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                <?php if ($hasCompare): ?>
                <td class="text-end"><?= number_format((float)($row['compare_amount'] ?? 0), 2) ?></td>
                <td class="text-end"><?= number_format((float)($row['variance'] ?? 0), 2) ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="2"><?= htmlspecialchars($section['label'] ?? '', ENT_QUOTES) ?> total</td>
                <td class="text-end"><?= number_format((float)($section['total'] ?? 0), 2) ?></td>
                <?php if ($hasCompare): ?>
                <td class="text-end"><?= number_format((float)($section['compare_total'] ?? 0), 2) ?></td>
                <td class="text-end"><?= number_format((float)($section['variance'] ?? 0), 2) ?></td>
                <?php endif; ?>
            </tr>
            <?php if (($section['key'] ?? '') === 'cost_of_sales'): ?>
            <tr class="subtotal">
                <td colspan="2">Gross profit</td>
                <td class="text-end"><?= number_format((float)($summary['gross_profit'] ?? 0), 2) ?></td>
                <?php if ($hasCompare): ?>
                <td class="text-end"><?= number_format((float)($summary['compare_gross_profit'] ?? 0), 2) ?></td>
                <td class="text-end"><?= number_format((float)($summary['gross_profit'] ?? 0) - (float)($summary['compare_gross_profit'] ?? 0), 2) ?></td>
                <?php endif; ?>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
            <tr class="net">
                <td colspan="2">Net profit</td>
                <td class="text-end"><?= number_format((float)($summary['net_profit'] ?? 0), 2) ?></td>
                <?php if ($hasCompare): ?>
                <td class="text-end"><?= number_format((float)($summary['compare_net_profit'] ?? 0), 2) ?></td>
                <td class="text-end"><?= number_format((float)($summary['net_profit_variance'] ?? 0), 2) ?></td>
                <?php endif; ?>
            </tr>
        </tbody>
    </table>
</body>
</html>
