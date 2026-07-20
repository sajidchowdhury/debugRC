<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';

ob_start();
$title = 'Balance Sheet';
$rpt = ReportsCatalog::get('balance_sheet');
$rpt_has_run = !empty($balance_sheet);
$includeZero = !empty($include_zero);
$canOverrideBranch = !empty($can_override_branch);
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/BalanceSheet?' . http_build_query(array_merge($_GET, ['export' => 1]))
    : '';
?>
<form method="GET" action="<?= BASE_URL ?>Report/BalanceSheet" class="row g-3 align-items-end">
    <input type="hidden" name="search" value="1">
    <div class="col-md-2">
        <label class="form-label small fw-semibold">As of date</label>
        <input type="date" name="as_of_date" class="form-control form-control-sm" value="<?= htmlspecialchars($as_of_date ?? date('Y-m-d'), ENT_QUOTES) ?>">
    </div>
    <?php if ($canOverrideBranch): ?>
    <div class="col-md-3">
        <label class="form-label small fw-semibold">Branch</label>
        <select name="branch_id" class="form-select form-select-sm">
            <option value="">All branches</option>
            <?php foreach ($branches ?? [] as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)($branch_id ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-md-3">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="include_zero" value="1" id="bsIncludeZero" <?= $includeZero ? 'checked' : '' ?>>
            <label class="form-check-label small" for="bsIncludeZero">Include zero-balance accounts</label>
        </div>
    </div>
    <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $bs = $balance_sheet;
    $balanced = !empty($bs['is_balanced']);
    $totals = $bs['totals'] ?? [];
    $sections = $bs['sections'] ?? [];
?>
<div class="rpt-status-banner <?= $balanced ? 'balanced' : 'unbalanced' ?>">
    <div class="fs-2">
        <i class="fas <?= $balanced ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-danger' ?>"></i>
    </div>
    <div>
        <h4 class="mb-1 fw-bold"><?= $balanced ? 'Balance sheet equation holds' : 'Out of balance — Assets ≠ Liabilities + Equity' ?></h4>
        <p class="mb-0 small">
            As of <?= htmlspecialchars($bs['as_of_date'] ?? '', ENT_QUOTES) ?>
            · Assets <strong>Tk <?= number_format((float)($totals['assets'] ?? 0), 2) ?></strong>
            = Liabilities + Equity <strong>Tk <?= number_format((float)($totals['liabilities_plus_equity'] ?? 0), 2) ?></strong>
            <?php if (!$balanced): ?>
            · Difference <strong>Tk <?= number_format((float)($bs['difference'] ?? 0), 2) ?></strong>
            <?php endif; ?>
        </p>
        <?php if (!empty($bs['tb_activity_balanced'])): ?>
        <p class="mb-0 small text-muted mt-1"><i class="fas fa-check-circle me-1"></i> Cumulative GL activity through this date is balanced (Dr = Cr).</p>
        <?php elseif ($rpt_has_run): ?>
        <p class="mb-0 small text-warning mt-1"><i class="fas fa-exclamation-circle me-1"></i> GL period activity is out of balance — fix journals before trusting this statement.</p>
        <?php endif; ?>
    </div>
</div>
<?php
    ob_start();
?>
<div class="rpt-kpi <?= $balanced ? 'success' : 'danger' ?>">
    <div class="kpi-label">Total assets</div>
    <div class="kpi-value">Tk <?= number_format((float)($totals['assets'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi <?= $balanced ? 'success' : 'danger' ?>">
    <div class="kpi-label">Liabilities + equity</div>
    <div class="kpi-value">Tk <?= number_format((float)($totals['liabilities_plus_equity'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Difference</div>
    <div class="kpi-value"><?= $balanced ? '—' : 'Tk ' . number_format((float)($bs['difference'] ?? 0), 2) ?></div>
</div>
<?php $rpt_kpis = ob_get_clean();

    $renderSection = static function (array $section, bool $showNetIncome = false) use ($bs): void {
        ?>
        <div class="rpt-result-panel mb-3">
            <div class="rpt-result-panel-head">
                <h2><?= htmlspecialchars($section['label'] ?? '', ENT_QUOTES) ?></h2>
                <span class="badge bg-dark">Tk <?= number_format((float)($section['total'] ?? 0), 2) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table rpt-data-table mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Ledger</th>
                            <th>Nature</th>
                            <th class="text-end">Balance</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($section['rows']) && !$showNetIncome): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No balances in this section.</td></tr>
                    <?php else: ?>
                        <?php foreach ($section['rows'] ?? [] as $row):
                            $glUrl = BASE_URL . 'Report/GeneralLedger?' . http_build_query([
                                'search'    => 1,
                                'ledger_id' => (int)($row['ledger_id'] ?? 0),
                                'from_date' => date('Y-01-01', strtotime($bs['as_of_date'] ?? 'today')),
                                'to_date'   => $bs['as_of_date'] ?? date('Y-m-d'),
                            ]);
                        ?>
                        <tr class="rpt-click-row" data-href="<?= htmlspecialchars($glUrl, ENT_QUOTES) ?>" style="cursor:pointer">
                            <td><strong><?= htmlspecialchars($row['ledger_code'] ?? '', ENT_QUOTES) ?></strong></td>
                            <td>
                                <a href="<?= BASE_URL ?>ledger/show/<?= (int)($row['ledger_id'] ?? 0) ?>" class="text-decoration-none" onclick="event.stopPropagation()">
                                    <?= htmlspecialchars($row['ledger_name'] ?? '', ENT_QUOTES) ?>
                                </a>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['ledger_nature'] ?? '—', ENT_QUOTES) ?></span></td>
                            <td class="text-end">
                                <strong><?= number_format((float)($row['balance'] ?? 0), 2) ?></strong>
                                <small class="text-muted">(<?= htmlspecialchars($row['balance_side'] ?? '', ENT_QUOTES) ?>)</small>
                            </td>
                            <td>
                                <a href="<?= htmlspecialchars($glUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation()"><i class="fas fa-book-open"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($showNetIncome && (float)($section['net_income'] ?? 0) !== 0.0): ?>
                        <tr class="table-light">
                            <td colspan="2"><em><?= htmlspecialchars($section['net_income_label'] ?? 'Net income', ENT_QUOTES) ?></em></td>
                            <td></td>
                            <td class="text-end">
                                <strong><?= number_format(abs((float)$section['net_income']), 2) ?></strong>
                                <small class="text-muted">(<?= (float)$section['net_income'] >= 0 ? 'Cr' : 'Dr' ?>)</small>
                            </td>
                            <td></td>
                        </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($section['rows']) || ($showNetIncome && (float)($section['net_income'] ?? 0) !== 0.0)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-semibold">Section total</td>
                            <td class="text-end fw-bold">Tk <?= number_format((float)($section['total'] ?? 0), 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php
    };
?>

<div class="row">
    <div class="col-lg-6">
        <?php $renderSection($sections['assets'] ?? []); ?>
    </div>
    <div class="col-lg-6">
        <?php $renderSection($sections['liabilities'] ?? []); ?>
        <?php $renderSection($sections['equity'] ?? [], true); ?>
    </div>
</div>

<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2><i class="fas fa-equals me-1"></i> Equation check</h2>
        <?php if ($balanced): ?>
        <span class="badge bg-success">Balanced ✓</span>
        <?php else: ?>
        <span class="badge bg-danger">Diff Tk <?= number_format((float)($bs['difference'] ?? 0), 2) ?></span>
        <?php endif; ?>
    </div>
    <table class="table rpt-data-table mb-0">
        <tbody>
            <tr>
                <td>Total assets</td>
                <td class="text-end">Tk <?= number_format((float)($totals['assets'] ?? 0), 2) ?></td>
            </tr>
            <tr>
                <td>Total liabilities</td>
                <td class="text-end">Tk <?= number_format((float)($totals['liabilities'] ?? 0), 2) ?></td>
            </tr>
            <tr>
                <td>Total equity (incl. unclosed P&amp;L)</td>
                <td class="text-end">Tk <?= number_format((float)($totals['equity'] ?? 0), 2) ?></td>
            </tr>
            <tr class="fw-bold">
                <td>Liabilities + equity</td>
                <td class="text-end">Tk <?= number_format((float)($totals['liabilities_plus_equity'] ?? 0), 2) ?></td>
            </tr>
            <tr class="<?= $balanced ? 'table-success' : 'table-danger' ?>">
                <td>Difference (Assets − Liabilities − Equity)</td>
                <td class="text-end">Tk <?= number_format((float)($bs['difference'] ?? 0), 2) ?></td>
            </tr>
        </tbody>
    </table>
</div>
<script>
document.querySelectorAll('.rpt-click-row[data-href]').forEach(function (row) {
    row.addEventListener('click', function () {
        window.location.href = row.getAttribute('data-href');
    });
});
</script>
<?php
else:
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <i class="fas fa-scale-balanced d-block"></i>
        <h3>Pick an as-of date</h3>
        <p>Generate the balance sheet to verify Assets = Liabilities + Equity using normal-balance presentation.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
