<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';

ob_start();
$title = 'Cash Flow Statement';
$rpt = ReportsCatalog::get('cash_flow');
$rpt_has_run = !empty($cash_flow);
$canOverrideBranch = !empty($can_override_branch);
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/CashFlow?' . http_build_query(array_merge($_GET, ['search' => 1, 'export' => 1]))
    : '';
?>
<form method="GET" action="<?= BASE_URL ?>Report/CashFlow" class="row g-3 align-items-end">
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
    <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $cf = $cash_flow;
    $summary = $cf['summary'] ?? [];
    $rec = $cf['reconciliation'] ?? [];
    $moveOk = !empty($rec['movement_within_tolerance']);
    $bankOk = $rec['banks_within_tolerance'] ?? null;
    $netChange = (float)($summary['net_change_in_cash'] ?? 0);
?>
<div class="rpt-status-banner <?= $moveOk ? 'balanced' : 'unbalanced' ?>">
    <div class="fs-2">
        <i class="fas <?= $moveOk ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-warning' ?>"></i>
    </div>
    <div>
        <h4 class="mb-1 fw-bold">Net change in cash: Tk <?= number_format($netChange, 2) ?></h4>
        <p class="mb-0 small">
            <?= htmlspecialchars($cf['from_date'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($cf['to_date'] ?? '', ENT_QUOTES) ?>
            · GL cash_bank movement <strong>Tk <?= number_format((float)($rec['gl_period_movement'] ?? 0), 2) ?></strong>
            <?php if (!$moveOk): ?>
            · Statement vs GL diff <strong>Tk <?= number_format((float)($rec['movement_difference'] ?? 0), 2) ?></strong>
            <?php endif; ?>
        </p>
        <?php if ($bankOk !== null): ?>
        <p class="mb-0 small mt-1">
            Bank register Tk <?= number_format((float)($rec['banks_register_total'] ?? 0), 2) ?>
            vs GL closing Tk <?= number_format((float)($rec['closing_cash_gl'] ?? 0), 2) ?>
            · <?= $bankOk ? 'Within tolerance ✓' : 'Diff Tk ' . number_format((float)($rec['banks_vs_gl_closing_diff'] ?? 0), 2) ?>
        </p>
        <?php elseif (!empty($rec['branch_scoped_note'])): ?>
        <p class="mb-0 small text-muted mt-1"><?= htmlspecialchars($rec['branch_scoped_note'], ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php
    ob_start();
?>
<div class="rpt-kpi">
    <div class="kpi-label">Operating</div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['net_cash_operating'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Investing</div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['net_cash_investing'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi <?= $moveOk ? 'success' : 'danger' ?>">
    <div class="kpi-label">Net change</div>
    <div class="kpi-value">Tk <?= number_format($netChange, 2) ?></div>
</div>
<?php $rpt_kpis = ob_get_clean(); ?>

<?php foreach ($cf['sections'] ?? [] as $section): ?>
<div class="rpt-result-panel mb-3">
    <div class="rpt-result-panel-head">
        <h2><?= htmlspecialchars($section['label'] ?? '', ENT_QUOTES) ?></h2>
    </div>
    <div class="table-responsive">
        <table class="table rpt-data-table mb-0">
            <tbody>
            <?php foreach ($section['lines'] ?? [] as $line):
                $kind = (string)($line['kind'] ?? '');
                $isSubtotal = $kind === 'subtotal';
                $amt = (float)($line['amount'] ?? 0);
            ?>
            <tr class="<?= $isSubtotal ? 'table-secondary fw-semibold' : '' ?>">
                <td><?= htmlspecialchars($line['label'] ?? '', ENT_QUOTES) ?></td>
                <td class="text-end" style="width:12rem">
                    <?= $amt >= 0 ? '' : '(' ?><?= number_format(abs($amt), 2) ?><?= $amt >= 0 ? '' : ')' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2><i class="fas fa-building-columns me-1"></i> Cash reconciliation</h2>
        <?php if ($moveOk): ?>
        <span class="badge bg-success">GL movement tied ✓</span>
        <?php else: ?>
        <span class="badge bg-warning text-dark">Review diff</span>
        <?php endif; ?>
    </div>
    <table class="table rpt-data-table mb-0">
        <tbody>
            <tr><td>Opening cash (GL cash_bank)</td><td class="text-end">Tk <?= number_format((float)($rec['opening_cash_gl'] ?? 0), 2) ?></td></tr>
            <tr><td>Net change (indirect statement)</td><td class="text-end">Tk <?= number_format((float)($rec['statement_net_change'] ?? 0), 2) ?></td></tr>
            <tr><td>Closing cash (GL cash_bank)</td><td class="text-end">Tk <?= number_format((float)($rec['closing_cash_gl'] ?? 0), 2) ?></td></tr>
            <tr><td>GL period movement (closing − opening)</td><td class="text-end">Tk <?= number_format((float)($rec['gl_period_movement'] ?? 0), 2) ?></td></tr>
            <tr class="<?= $moveOk ? 'table-success' : 'table-warning' ?>">
                <td>Statement vs GL movement</td>
                <td class="text-end">Tk <?= number_format((float)($rec['movement_difference'] ?? 0), 2) ?></td>
            </tr>
            <?php if ($bankOk !== null): ?>
            <tr><td>Bank module register total</td><td class="text-end">Tk <?= number_format((float)($rec['banks_register_total'] ?? 0), 2) ?></td></tr>
            <tr class="<?= $bankOk ? 'table-success' : 'table-warning' ?>">
                <td>Bank register vs GL closing</td>
                <td class="text-end">Tk <?= number_format((float)($rec['banks_vs_gl_closing_diff'] ?? 0), 2) ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <p class="small text-muted px-3 pb-3 mb-0">Tolerance Tk <?= number_format((float)($rec['tolerance'] ?? 0.02), 2) ?> · See also <a href="<?= BASE_URL ?>Reconciliation/index">Reconciliation hub</a>.</p>
</div>
<?php
else:
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <i class="fas fa-water d-block"></i>
        <h3>Set your period</h3>
        <p>Indirect cash flow from net profit, working-capital changes, and GL cash_bank reconciliation.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
