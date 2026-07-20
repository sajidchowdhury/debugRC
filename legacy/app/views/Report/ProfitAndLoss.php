<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';

ob_start();
$title = 'Profit & Loss';
$rpt = ReportsCatalog::get('profit_and_loss');
$rpt_has_run = !empty($profit_and_loss);
$includeZero = !empty($include_zero);
$comparePrior = !empty($compare_prior);
$hasCompare = !empty($profit_and_loss['compare']);
$canOverrideBranch = !empty($can_override_branch);
$exportQuery = array_merge($_GET, ['search' => 1]);
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/ProfitAndLoss?' . http_build_query(array_merge($exportQuery, ['export' => 'csv']))
    : '';
$rpt_export_pdf_url = $rpt_has_run
    ? BASE_URL . 'Report/ProfitAndLoss?' . http_build_query(array_merge($exportQuery, ['export' => 'pdf']))
    : '';
?>
<form method="GET" action="<?= BASE_URL ?>Report/ProfitAndLoss" class="row g-3 align-items-end">
    <input type="hidden" name="search" value="1">
    <div class="col-md-2">
        <label class="form-label small fw-semibold">From date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date ?? date('Y-m-01'), ENT_QUOTES) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">To date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date ?? date('Y-m-d'), ENT_QUOTES) ?>">
    </div>
    <?php if ($canOverrideBranch): ?>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Branch</label>
        <select name="branch_id" class="form-select form-select-sm">
            <option value="">All branches</option>
            <?php foreach ($branches ?? [] as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)($branch_id ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-md-2">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="compare_prior" value="1" id="plComparePrior" <?= $comparePrior ? 'checked' : '' ?>>
            <label class="form-check-label small" for="plComparePrior">Compare prior period</label>
        </div>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Compare from</label>
        <input type="date" name="compare_from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($compare_from_date ?? '', ENT_QUOTES) ?>" <?= $comparePrior ? 'readonly' : '' ?>>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Compare to</label>
        <input type="date" name="compare_to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($compare_to_date ?? '', ENT_QUOTES) ?>" <?= $comparePrior ? 'readonly' : '' ?>>
    </div>
    <div class="col-md-2">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="include_zero" value="1" id="plIncludeZero" <?= $includeZero ? 'checked' : '' ?>>
            <label class="form-check-label small" for="plIncludeZero">Include zero lines</label>
        </div>
    </div>
    <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $pl = $profit_and_loss;
    $summary = $pl['summary'] ?? [];
    $sections = $pl['sections'] ?? [];
    $compare = $pl['compare'] ?? null;
    $netProfit = (float)($summary['net_profit'] ?? 0);
    $isProfit = $netProfit >= 0;
?>
<div class="rpt-status-banner <?= $isProfit ? 'balanced' : 'unbalanced' ?>">
    <div class="fs-2">
        <i class="fas <?= $isProfit ? 'fa-circle-check text-success' : 'fa-chart-line text-danger' ?>"></i>
    </div>
    <div>
        <h4 class="mb-1 fw-bold"><?= $isProfit ? 'Net profit' : 'Net loss' ?> for the period</h4>
        <p class="mb-0 small">
            <?= htmlspecialchars($pl['from_date'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($pl['to_date'] ?? '', ENT_QUOTES) ?>
            · <strong>Tk <?= number_format(abs($netProfit), 2) ?></strong>
            <?php if ($hasCompare): ?>
            · Prior net Tk <?= number_format((float)($summary['compare_net_profit'] ?? 0), 2) ?>
            · Variance <strong>Tk <?= number_format((float)($summary['net_profit_variance'] ?? 0), 2) ?></strong>
            <?php endif; ?>
        </p>
        <?php if ($hasCompare): ?>
        <p class="mb-0 small text-muted mt-1">Compare: <?= htmlspecialchars($compare['from_date'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($compare['to_date'] ?? '', ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php
    ob_start();
?>
<div class="rpt-kpi success">
    <div class="kpi-label">Revenue</div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['total_revenue'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Gross profit</div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['gross_profit'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi <?= $isProfit ? 'success' : 'danger' ?>">
    <div class="kpi-label">Net profit</div>
    <div class="kpi-value">Tk <?= number_format($netProfit, 2) ?></div>
</div>
<?php $rpt_kpis = ob_get_clean(); ?>

<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2><i class="fas fa-file-invoice-dollar me-1"></i> Income statement</h2>
        <span class="badge bg-<?= $isProfit ? 'success' : 'danger' ?>">Net <?= number_format($netProfit, 2) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table rpt-data-table mb-0">
            <thead>
                <tr>
                    <th>Ledger</th>
                    <th>Nature</th>
                    <th class="text-end">Period</th>
                    <?php if ($hasCompare): ?>
                    <th class="text-end">Compare</th>
                    <th class="text-end">Variance</th>
                    <?php endif; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $renderSubtotal = static function (string $label, float $amount, ?float $compareAmt = null, ?float $variance = null) use ($hasCompare): void {
                ?>
                <tr class="table-secondary fw-semibold">
                    <td colspan="2"><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                    <td class="text-end">Tk <?= number_format($amount, 2) ?></td>
                    <?php if ($hasCompare): ?>
                    <td class="text-end"><?= $compareAmt !== null ? 'Tk ' . number_format($compareAmt, 2) : '—' ?></td>
                    <td class="text-end"><?= $variance !== null ? 'Tk ' . number_format($variance, 2) : '—' ?></td>
                    <?php endif; ?>
                    <td></td>
                </tr>
                <?php
            };

            foreach ($sections as $section):
                $sectionKey = $section['key'] ?? '';
            ?>
                <tr class="table-light">
                    <td colspan="<?= $hasCompare ? 6 : 4 ?>" class="fw-bold pt-3"><?= htmlspecialchars($section['label'] ?? '', ENT_QUOTES) ?></td>
                </tr>
                <?php foreach ($section['rows'] ?? [] as $row):
                    $glUrl = BASE_URL . 'Report/GeneralLedger?' . http_build_query([
                        'search'    => 1,
                        'ledger_id' => (int)($row['ledger_id'] ?? 0),
                        'from_date' => $pl['from_date'] ?? $from_date,
                        'to_date'   => $pl['to_date'] ?? $to_date,
                    ]);
                    $amt = (float)($row['amount'] ?? 0);
                ?>
                <tr class="rpt-click-row" data-href="<?= htmlspecialchars($glUrl, ENT_QUOTES) ?>" style="cursor:pointer">
                    <td>
                        <strong><?= htmlspecialchars($row['ledger_code'] ?? '', ENT_QUOTES) ?></strong>
                        <?= htmlspecialchars($row['ledger_name'] ?? '', ENT_QUOTES) ?>
                    </td>
                    <td><span class="badge bg-light text-dark border small"><?= htmlspecialchars($row['nature_label'] ?? '', ENT_QUOTES) ?></span></td>
                    <td class="text-end">
                        Tk <?= number_format($amt, 2) ?>
                        <small class="text-muted">(<?= htmlspecialchars($row['balance_side'] ?? '', ENT_QUOTES) ?>)</small>
                    </td>
                    <?php if ($hasCompare): ?>
                    <td class="text-end">Tk <?= number_format((float)($row['compare_amount'] ?? 0), 2) ?></td>
                    <td class="text-end">Tk <?= number_format((float)($row['variance'] ?? 0), 2) ?></td>
                    <?php endif; ?>
                    <td><a href="<?= htmlspecialchars($glUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation()"><i class="fas fa-book-open"></i></a></td>
                </tr>
                <?php endforeach; ?>
                <?php $renderSubtotal(
                    ($section['label'] ?? '') . ' total',
                    (float)($section['total'] ?? 0),
                    $hasCompare ? (float)($section['compare_total'] ?? 0) : null,
                    $hasCompare ? (float)($section['variance'] ?? 0) : null
                ); ?>

                <?php if ($sectionKey === 'cost_of_sales'): ?>
                <?php $renderSubtotal(
                    'Gross profit',
                    (float)($summary['gross_profit'] ?? 0),
                    $hasCompare ? (float)($summary['compare_gross_profit'] ?? 0) : null,
                    $hasCompare ? round((float)($summary['gross_profit'] ?? 0) - (float)($summary['compare_gross_profit'] ?? 0), 2) : null
                ); ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php $renderSubtotal('Net profit (Income − Expense)', $netProfit,
                $hasCompare ? (float)($summary['compare_net_profit'] ?? 0) : null,
                $hasCompare ? (float)($summary['net_profit_variance'] ?? 0) : null
            ); ?>
            </tbody>
        </table>
    </div>
</div>
<script>
document.querySelectorAll('.rpt-click-row[data-href]').forEach(function (row) {
    row.addEventListener('click', function () {
        window.location.href = row.getAttribute('data-href');
    });
});
document.getElementById('plComparePrior')?.addEventListener('change', function () {
    const ro = this.checked;
    document.querySelector('[name=compare_from_date]')?.toggleAttribute('readonly', ro);
    document.querySelector('[name=compare_to_date]')?.toggleAttribute('readonly', ro);
});
</script>
<?php
else:
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <i class="fas fa-chart-pie d-block"></i>
        <h3>Set your period</h3>
        <p>Generate profit &amp; loss grouped by ledger nature — revenue, COGS, operating expense, payroll, and more.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
