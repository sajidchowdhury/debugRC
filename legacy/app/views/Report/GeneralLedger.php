<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';

ob_start();
$title = 'General Ledger';
$rpt = ReportsCatalog::get('general_ledger');
$rpt_has_run = !empty($general_ledger) && !empty($general_ledger['ledger']);
$gl = $general_ledger ?? [];
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/GeneralLedger?' . http_build_query(array_merge($_GET, ['export' => 1]))
    : '';
?>
<form method="GET" action="<?= BASE_URL ?>Report/GeneralLedger" class="row g-3 align-items-end">
    <input type="hidden" name="search" value="1">
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Ledger account <span class="text-danger">*</span></label>
        <select name="ledger_id" class="form-select form-select-sm" required>
            <option value="">Select ledger…</option>
            <?php foreach ($ledgers ?? [] as $lg): ?>
            <option value="<?= (int)$lg['id'] ?>" <?= (int)($ledger_id ?? 0) === (int)$lg['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(($lg['ledger_code'] ?? '') . ' — ' . ($lg['ledger_name'] ?? ''), ENT_QUOTES) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">From date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date ?? date('Y-m-01'), ENT_QUOTES) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">To date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date ?? date('Y-m-d'), ENT_QUOTES) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Branch</label>
        <select name="branch_id" class="form-select form-select-sm">
            <option value="">All branches</option>
            <?php foreach ($branches ?? [] as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)($branch_id ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-book-open me-1"></i> Run</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $ledger = $gl['ledger'] ?? [];
    $opening = $gl['opening'] ?? [];
    $closing = $gl['closing'] ?? [];
    $lines = $gl['lines'] ?? [];
?>
<div class="rpt-status-banner balanced">
    <div class="fs-2"><i class="fas fa-book-open text-primary"></i></div>
    <div>
        <h4 class="mb-1 fw-bold"><?= htmlspecialchars($ledger['ledger_name'] ?? '', ENT_QUOTES) ?></h4>
        <p class="mb-0 small">
            <?= htmlspecialchars($ledger['ledger_code'] ?? '', ENT_QUOTES) ?>
            · <?= htmlspecialchars($gl['from_date'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($gl['to_date'] ?? '', ENT_QUOTES) ?>
            · Normal <?= htmlspecialchars(ucfirst((string)($ledger['normal_balance'] ?? 'debit')), ENT_QUOTES) ?>
        </p>
    </div>
</div>
<?php
    ob_start();
?>
<div class="rpt-kpi">
    <div class="kpi-label">Opening</div>
    <div class="kpi-value"><?= number_format((float)($opening['balance'] ?? 0), 2) ?> <?= htmlspecialchars($opening['balance_side'] ?? 'Dr', ENT_QUOTES) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Lines</div>
    <div class="kpi-value"><?= count($lines) ?></div>
</div>
<div class="rpt-kpi success">
    <div class="kpi-label">Closing</div>
    <div class="kpi-value"><?= number_format((float)($closing['balance'] ?? 0), 2) ?> <?= htmlspecialchars($closing['balance_side'] ?? 'Dr', ENT_QUOTES) ?></div>
</div>
<?php $rpt_kpis = ob_get_clean(); ?>

<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2><i class="fas fa-list me-1"></i> Account activity</h2>
        <a href="<?= BASE_URL ?>ledger/show/<?= (int)($ledger['id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-eye me-1"></i> Ledger hub
        </a>
    </div>
    <div class="table-responsive">
        <table class="table rpt-data-table mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Entry</th>
                    <th>Reference</th>
                    <th>Narration</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                    <th class="text-end">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="table-light">
                    <td colspan="6" class="fw-semibold">Opening balance</td>
                    <td class="text-end fw-semibold">
                        <?= number_format((float)($opening['balance'] ?? 0), 2) ?>
                        <small class="text-muted">(<?= htmlspecialchars($opening['balance_side'] ?? 'Dr', ENT_QUOTES) ?>)</small>
                    </td>
                </tr>
                <?php if (empty($lines)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No journal lines in this period.</td></tr>
                <?php else: foreach ($lines as $line): ?>
                <tr>
                    <td class="text-nowrap"><?= htmlspecialchars($line['entry_date'] ?? '', ENT_QUOTES) ?></td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($line['entry_no'] ?? '', ENT_QUOTES) ?></div>
                    </td>
                    <td class="small">
                        <?php if (!empty($line['reference_url'])): ?>
                        <a href="<?= htmlspecialchars($line['reference_url'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($line['reference_label'] ?? '', ENT_QUOTES) ?>
                            #<?= (int)($line['reference_id'] ?? 0) ?>
                        </a>
                        <?php else: ?>
                        <?= htmlspecialchars($line['reference_label'] ?? '—', ENT_QUOTES) ?>
                        <?php if (!empty($line['reference_id'])): ?> #<?= (int)$line['reference_id'] ?><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($line['narration'] ?? '', ENT_QUOTES) ?></td>
                    <td class="text-end"><?= (float)($line['debit'] ?? 0) > 0 ? number_format((float)$line['debit'], 2) : '—' ?></td>
                    <td class="text-end"><?= (float)($line['credit'] ?? 0) > 0 ? number_format((float)$line['credit'], 2) : '—' ?></td>
                    <td class="text-end">
                        <?= number_format((float)($line['running_balance'] ?? 0), 2) ?>
                        <small class="text-muted">(<?= htmlspecialchars($line['running_side'] ?? 'Dr', ENT_QUOTES) ?>)</small>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td colspan="6" class="fw-semibold text-end">Closing balance</td>
                    <td class="text-end fw-semibold">
                        <?= number_format((float)($closing['balance'] ?? 0), 2) ?>
                        <small class="text-muted">(<?= htmlspecialchars($closing['balance_side'] ?? 'Dr', ENT_QUOTES) ?>)</small>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php
elseif (!empty($_GET['search']) && empty($ledger_id)):
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="alert alert-warning mb-0">Select a ledger account to run the general ledger report.</div>
</div>
<?php
else:
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <i class="fas fa-book-open d-block"></i>
        <h3>Pick a ledger</h3>
        <p>Choose an account and date range to see chronological journal activity with a running balance.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
