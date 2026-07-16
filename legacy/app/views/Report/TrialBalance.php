<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';

ob_start();
$title = 'Trial Balance';
$rpt = ReportsCatalog::get('trial_balance');
$rpt_has_run = !empty($trial_balance);
$includeZero = !empty($include_zero);
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/TrialBalance?' . http_build_query(array_merge($_GET, ['export' => 1]))
    : '';
?>
<form method="GET" action="<?= BASE_URL ?>Report/TrialBalance" class="row g-3 align-items-end">
    <input type="hidden" name="search" value="1">
    <div class="col-md-2">
        <label class="form-label small fw-semibold">From date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date ?? date('Y-m-01'), ENT_QUOTES) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">To date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date ?? date('Y-m-d'), ENT_QUOTES) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Account type</label>
        <select name="account_type" class="form-select form-select-sm">
            <option value="">All types</option>
            <?php foreach (['Asset', 'Liability', 'Equity', 'Income', 'Expense'] as $t): ?>
            <option value="<?= $t ?>" <?= ($account_type ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="include_zero" value="1" id="tbIncludeZero" <?= $includeZero ? 'checked' : '' ?>>
            <label class="form-check-label small" for="tbIncludeZero">Include zero-balance accounts</label>
        </div>
    </div>
    <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $tb = $trial_balance;
    $balanced = !empty($tb['is_balanced']);
?>
<div class="rpt-status-banner <?= $balanced ? 'balanced' : 'unbalanced' ?>">
    <div class="fs-2">
        <i class="fas <?= $balanced ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-danger' ?>"></i>
    </div>
    <div>
        <h4 class="mb-1 fw-bold"><?= $balanced ? 'Period activity is balanced' : 'Period out of balance — investigate journals' ?></h4>
        <p class="mb-0 small">
            <?= htmlspecialchars($tb['from_date'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($tb['to_date'] ?? '', ENT_QUOTES) ?>
            <?php if (!$balanced): ?>
            · Period difference <strong>Tk <?= number_format((float)($tb['difference'] ?? 0), 2) ?></strong>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php
    ob_start();
?>
<div class="rpt-kpi <?= $balanced ? 'success' : 'danger' ?>">
    <div class="kpi-label">Period debits</div>
    <div class="kpi-value">Tk <?= number_format((float)($tb['grand_debit'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi <?= $balanced ? 'success' : 'danger' ?>">
    <div class="kpi-label">Period credits</div>
    <div class="kpi-value">Tk <?= number_format((float)($tb['grand_credit'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Accounts shown</div>
    <div class="kpi-value"><?= count($tb['data'] ?? []) ?></div>
</div>
<?php $rpt_kpis = ob_get_clean(); ?>

<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2><i class="fas fa-list me-1"></i> Trial balance detail</h2>
        <?php if ($balanced): ?>
        <span class="badge bg-success">Period balanced ✓</span>
        <?php else: ?>
        <span class="badge bg-danger">Diff <?= number_format((float)($tb['difference'] ?? 0), 2) ?></span>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table rpt-data-table mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Ledger</th>
                    <th>Type</th>
                    <th class="text-end">Opening</th>
                    <th class="text-end">Period Dr</th>
                    <th class="text-end">Period Cr</th>
                    <th class="text-end">Closing</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($tb['data'])): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No accounts match these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($tb['data'] as $row):
                    $glUrl = BASE_URL . 'Report/GeneralLedger?' . http_build_query([
                        'search'     => 1,
                        'ledger_id'  => (int)($row['ledger_id'] ?? 0),
                        'from_date'  => $tb['from_date'] ?? $from_date,
                        'to_date'    => $tb['to_date'] ?? $to_date,
                    ]);
                ?>
                <tr class="rpt-click-row" data-href="<?= htmlspecialchars($glUrl, ENT_QUOTES) ?>" style="cursor:pointer">
                    <td><strong><?= htmlspecialchars($row['ledger_code'] ?? '', ENT_QUOTES) ?></strong></td>
                    <td>
                        <a href="<?= BASE_URL ?>ledger/show/<?= (int)($row['ledger_id'] ?? 0) ?>" class="text-decoration-none" onclick="event.stopPropagation()">
                            <?= htmlspecialchars($row['ledger_name'] ?? '', ENT_QUOTES) ?>
                        </a>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($row['account_type'] ?? '', ENT_QUOTES) ?></span></td>
                    <td class="text-end">
                        <?php if ((float)($row['opening_balance'] ?? 0) > 0): ?>
                        <?= number_format((float)$row['opening_balance'], 2) ?>
                        <small class="text-muted">(<?= htmlspecialchars($row['opening_side'] ?? '', ENT_QUOTES) ?>)</small>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end"><?= (float)($row['debit'] ?? 0) > 0 ? number_format((float)$row['debit'], 2) : '—' ?></td>
                    <td class="text-end"><?= (float)($row['credit'] ?? 0) > 0 ? number_format((float)$row['credit'], 2) : '—' ?></td>
                    <td class="text-end">
                        <strong><?= number_format((float)($row['closing_balance'] ?? 0), 2) ?></strong>
                        <small class="text-muted">(<?= htmlspecialchars($row['closing_side'] ?? '', ENT_QUOTES) ?>)</small>
                    </td>
                    <td class="text-nowrap">
                        <a href="<?= htmlspecialchars($glUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-outline-primary" title="General ledger" onclick="event.stopPropagation()">
                            <i class="fas fa-book-open"></i>
                        </a>
                        <a href="<?= BASE_URL ?>ledger/show/<?= (int)($row['ledger_id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary" title="Ledger hub" onclick="event.stopPropagation()">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($tb['data'])): ?>
            <tfoot>
                <tr>
                    <td colspan="4" class="text-end">Period totals</td>
                    <td class="text-end"><?= number_format((float)($tb['grand_debit'] ?? 0), 2) ?></td>
                    <td class="text-end"><?= number_format((float)($tb['grand_credit'] ?? 0), 2) ?></td>
                    <td colspan="2" class="text-center"><?= $balanced ? 'BALANCED' : 'OUT OF BALANCE' ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
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
        <h3>Set your period</h3>
        <p>Generate the trial balance with opening, period, and closing columns to verify GL integrity.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
