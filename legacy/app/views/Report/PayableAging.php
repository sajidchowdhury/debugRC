<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';

ob_start();
$rpt = ReportsCatalog::get('payable_aging');
$rpt_has_run = !empty($aging_report);
$canOverrideBranch = !empty($can_override_branch);
$rows = $aging_report['rows'] ?? [];
$footnote = $aging_report['footnote'] ?? [];
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/PayableAging?' . http_build_query(array_merge($_GET, ['search' => 1, 'export' => 1]))
    : '';
?>
<form method="GET" action="<?= BASE_URL ?>Report/PayableAging" class="row g-3 align-items-end">
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
    <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $grandTotal = (float)($aging_report['grand_total'] ?? 0);
    ob_start();
?>
<div class="rpt-kpi">
    <div class="kpi-label">Suppliers with balance</div>
    <div class="kpi-value"><?= count($rows) ?></div>
</div>
<div class="rpt-kpi success">
    <div class="kpi-label">Aging total</div>
    <div class="kpi-value">Tk <?= number_format($grandTotal, 2) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Sub-ledger total</div>
    <div class="kpi-value">Tk <?= number_format((float)($footnote['sub_ledger_total'] ?? 0), 2) ?></div>
</div>
<?php $rpt_kpis = ob_get_clean();

    $moduleLabel = 'Supplier payments';
    include __DIR__ . '/partials/aging_footnote.php';
?>
<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2><i class="fas fa-clock me-1"></i> Payable aging detail</h2>
        <span class="badge bg-dark">Tk <?= number_format($grandTotal, 2) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table rpt-data-table mb-0">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Branch</th>
                    <th class="text-end">0–30</th>
                    <th class="text-end">31–60</th>
                    <th class="text-end">61–90</th>
                    <th class="text-end">90+</th>
                    <th class="text-end">Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No outstanding payables.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row):
                    $supplierId = (int)($row['supplier_id'] ?? 0);
                    $moduleUrl = BASE_URL . 'SupplierTransaction/index?supplier_id=' . $supplierId;
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($row['supplier_code'] ?? '', ENT_QUOTES) ?></strong>
                        <?= htmlspecialchars($row['supplier_name'] ?? '', ENT_QUOTES) ?>
                        <?php if (!empty($row['mobile'])): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($row['mobile'], ENT_QUOTES) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['branch_name'] ?? '—', ENT_QUOTES) ?></td>
                    <td class="text-end"><?= number_format((float)($row['bucket_0_30'] ?? 0), 2) ?></td>
                    <td class="text-end"><?= number_format((float)($row['bucket_31_60'] ?? 0), 2) ?></td>
                    <td class="text-end"><?= number_format((float)($row['bucket_61_90'] ?? 0), 2) ?></td>
                    <td class="text-end"><?= number_format((float)($row['bucket_90_plus'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= number_format((float)($row['total_payable'] ?? 0), 2) ?></td>
                    <td>
                        <a href="<?= htmlspecialchars($moduleUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-outline-primary" title="Supplier payments">
                            <i class="fas fa-arrow-up-right-from-square"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr class="fw-bold">
                    <td colspan="6" class="text-end">Grand total</td>
                    <td class="text-end">Tk <?= number_format($grandTotal, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<?php
elseif (isset($_GET['search'])):
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <p>No outstanding payables as of this date.</p>
    </div>
</div>
<?php
else:
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <i class="fas fa-clock d-block"></i>
        <h3>Pick an as-of date</h3>
        <p>Outstanding supplier balances by age bucket with GL control footnote.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
