<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';

ob_start();
$title = 'Gross Margin';
$rpt = ReportsCatalog::get('gross_margin');
$rpt_has_run = !empty($report);
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/grossMargin?' . http_build_query(array_merge($_GET, ['export' => 1]))
    : '';
$summary = $report['summary'] ?? [];
$filters = $report['filters'] ?? [];
?>
<form method="GET" action="<?= BASE_URL ?>Report/grossMargin" class="row g-3 align-items-end">
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
        <label class="form-label small fw-semibold">Date basis</label>
        <select name="date_basis" class="form-select form-select-sm">
            <option value="delivery" <?= ($date_basis ?? 'delivery') === 'delivery' ? 'selected' : '' ?>>Delivery (challan date)</option>
            <option value="invoice" <?= ($date_basis ?? '') === 'invoice' ? 'selected' : '' ?>>Invoice date</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Group by</label>
        <select name="group_by" class="form-select form-select-sm">
            <option value="invoice" <?= ($group_by ?? 'invoice') === 'invoice' ? 'selected' : '' ?>>Invoice</option>
            <option value="product" <?= ($group_by ?? '') === 'product' ? 'selected' : '' ?>>Product</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Branch</label>
        <select name="branch_id" class="form-select form-select-sm">
            <?php foreach ($branches ?? [] as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)($branch_id ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Salesman</label>
        <select name="salesman_id" class="form-select form-select-sm">
            <option value="0">All</option>
            <?php foreach ($salesmen ?? [] as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= (int)($salesman_id ?? 0) === (int)$e['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $basisLabel = ($filters['date_basis'] ?? 'delivery') === 'delivery'
        ? 'Delivery basis — revenue &amp; COGS both exist after challan'
        : 'Invoice basis — shows timing gap where COGS not yet posted';
?>
<div class="alert alert-info border-0 mb-3 rpt-no-print">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-circle-info"></i></div>
        <div>
            <strong>W6 — Revenue vs COGS timing</strong>
            <p class="mb-1 small">
                Revenue posts to GL when the invoice is finalized (<code>invoice_date</code>).
                COGS posts when the challan completes (<code>challan_date</code>).
                Use <strong>Delivery basis</strong> for true gross margin; <strong>Invoice basis</strong> highlights invoices still in pipeline with zero COGS.
            </p>
            <span class="badge bg-secondary"><?= $basisLabel ?></span>
            <?php if (($summary['returns_count'] ?? 0) > 0): ?>
            <span class="badge bg-warning text-dark ms-1">
                Returns in period: Tk <?= number_format((float)($summary['returns_amount'] ?? 0), 2) ?>
                (<?= (int)($summary['returns_count'] ?? 0) ?> — excluded from margin totals)
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
    ob_start();
?>
<div class="rpt-kpi">
    <div class="kpi-label"><?= ($filters['date_basis'] ?? '') === 'invoice' ? 'Period revenue' : 'Delivered revenue' ?></div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['delivered_revenue'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">COGS</div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['cogs'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi success">
    <div class="kpi-label">Gross profit</div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['gross_profit'] ?? 0), 2) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Margin %</div>
    <div class="kpi-value"><?= $summary['margin_pct'] !== null ? number_format((float)$summary['margin_pct'], 2) . '%' : '—' ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Pipeline revenue</div>
    <div class="kpi-value">Tk <?= number_format((float)($summary['pipeline_revenue'] ?? 0), 2) ?></div>
    <div class="small text-muted"><?= (int)($summary['pipeline_count'] ?? 0) ?> open invoice(s)</div>
</div>
<?php if (($filters['date_basis'] ?? '') === 'invoice' && ($summary['timing_gap_count'] ?? 0) > 0): ?>
<div class="rpt-kpi">
    <div class="kpi-label">Timing gap rows</div>
    <div class="kpi-value"><?= (int)$summary['timing_gap_count'] ?></div>
</div>
<?php endif;
    $rpt_kpis = ob_get_clean();
?>

<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2>
            <i class="fas fa-<?= ($group_by ?? 'invoice') === 'product' ? 'boxes-stacked' : 'file-invoice' ?> me-1"></i>
            <?= ($group_by ?? 'invoice') === 'product' ? 'Product margin' : 'Invoice margin detail' ?>
        </h2>
        <span class="badge bg-secondary"><?= count($report['invoices'] ?? []) ?> invoice(s)</span>
    </div>
    <div class="table-responsive">
        <?php if (($group_by ?? 'invoice') === 'product'): ?>
        <table class="table rpt-data-table mb-0">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">COGS</th>
                    <th class="text-end">Gross profit</th>
                    <th class="text-end">Margin %</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($report['products'])): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No delivered lines in this period.</td></tr>
            <?php else: ?>
                <?php foreach ($report['products'] as $row): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($row['product_code'] ?? '', ENT_QUOTES) ?></strong>
                        <div class="small text-muted"><?= htmlspecialchars($row['product_name'] ?? '', ENT_QUOTES) ?></div>
                    </td>
                    <td class="text-end"><?= number_format((float)($row['qty'] ?? 0), 3) ?></td>
                    <td class="text-end"><?= number_format((float)($row['revenue'] ?? 0), 2) ?></td>
                    <td class="text-end"><?= number_format((float)($row['cogs'] ?? 0), 2) ?></td>
                    <td class="text-end"><strong><?= number_format((float)($row['gross_profit'] ?? 0), 2) ?></strong></td>
                    <td class="text-end"><?= $row['margin_pct'] !== null ? number_format((float)$row['margin_pct'], 2) . '%' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($report['products'])): ?>
            <tfoot>
                <tr>
                    <td colspan="2" class="text-end fw-bold">Totals</td>
                    <td class="text-end fw-bold"><?= number_format((float)($summary['delivered_revenue'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= number_format((float)($summary['cogs'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= number_format((float)($summary['gross_profit'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= $summary['margin_pct'] !== null ? number_format((float)$summary['margin_pct'], 2) . '%' : '—' ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <?php else: ?>
        <table class="table rpt-data-table mb-0">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Invoice date</th>
                    <th>Challan date</th>
                    <th>Status</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">COGS</th>
                    <th class="text-end">Gross profit</th>
                    <th class="text-end">Margin %</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($report['invoices'])): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No rows for this period and basis.</td></tr>
            <?php else: ?>
                <?php foreach ($report['invoices'] as $row): ?>
                <tr class="<?= !empty($row['timing_gap']) ? 'table-warning' : '' ?>">
                    <td>
                        <strong><?= htmlspecialchars($row['invoice_code'] ?? '', ENT_QUOTES) ?></strong>
                        <?php if (!empty($row['timing_gap'])): ?>
                        <span class="badge bg-warning text-dark ms-1" title="Revenue without COGS — still in pipeline">Gap</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['invoice_date'] ?? '', ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($row['challan_date'] ?? '—', ENT_QUOTES) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars(str_replace('_', ' ', $row['status'] ?? ''), ENT_QUOTES) ?></span></td>
                    <td class="text-end"><?= number_format((float)($row['revenue'] ?? 0), 2) ?></td>
                    <td class="text-end"><?= number_format((float)($row['cogs'] ?? 0), 2) ?></td>
                    <td class="text-end"><strong><?= number_format((float)($row['gross_profit'] ?? 0), 2) ?></strong></td>
                    <td class="text-end"><?= $row['margin_pct'] !== null ? number_format((float)$row['margin_pct'], 2) . '%' : '—' ?></td>
                    <td class="text-nowrap">
                        <a href="<?= BASE_URL ?>sales/invoice_copy/<?= (int)($row['id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" title="Invoice copy">
                            <i class="fas fa-file-invoice"></i>
                        </a>
                        <?php if (!empty($row['challan_id'])): ?>
                        <a href="<?= BASE_URL ?>challan/challan_copy/<?= (int)($row['id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" title="Challan copy">
                            <i class="fas fa-truck"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($report['invoices'])): ?>
            <tfoot>
                <tr>
                    <td colspan="4" class="text-end fw-bold">Totals</td>
                    <td class="text-end fw-bold"><?= number_format((float)($summary['delivered_revenue'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= number_format((float)($summary['cogs'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= number_format((float)($summary['gross_profit'] ?? 0), 2) ?></td>
                    <td class="text-end fw-bold"><?= $summary['margin_pct'] !== null ? number_format((float)$summary['margin_pct'], 2) . '%' : '—' ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php
else:
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <i class="fas fa-percent d-block"></i>
        <h3>Gross margin report</h3>
        <p>Choose a period and date basis. Delivery basis aligns revenue with COGS; invoice basis shows the pipeline timing gap.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
