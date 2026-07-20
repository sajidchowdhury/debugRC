<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';
require_once __DIR__ . '/../../helpers/JournalReportHelper.php';

ob_start();
$title = 'Journal Entries';
$rpt = ReportsCatalog::get('journal_entries');
$jeReport = $journal_entries ?? null;
$rpt_has_run = $jeReport !== null;
$rpt_export_url = $rpt_has_run
    ? BASE_URL . 'Report/JournalEntries?' . http_build_query(array_merge($_GET, ['export' => 1]))
    : '';
$refTypes = JournalReportHelper::referenceTypeOptions();
?>
<form method="GET" action="<?= BASE_URL ?>Report/JournalEntries" class="row g-3 align-items-end">
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
        <label class="form-label small fw-semibold">Reference type</label>
        <select name="reference_type" class="form-select form-select-sm">
            <option value="">All types</option>
            <?php foreach ($refTypes as $val => $label): ?>
            <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= ($reference_type ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
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
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Reversed</label>
        <select name="reversed" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="no" <?= ($reversed ?? '') === 'no' ? 'selected' : '' ?>>Active only</option>
            <option value="yes" <?= ($reversed ?? '') === 'yes' ? 'selected' : '' ?>>Reversed only</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Created by</label>
        <select name="created_by" class="form-select form-select-sm">
            <option value="">Anyone</option>
            <?php foreach ($journal_creators ?? [] as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($created_by ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['username'] ?? '', ENT_QUOTES) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Entry no, description, reference id…" value="<?= htmlspecialchars($search_q ?? '', ENT_QUOTES) ?>">
    </div>
    <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i> Search</button>
    </div>
</form>
<?php $rpt_filters = ob_get_clean();

ob_start();
if ($rpt_has_run):
    $entries = $jeReport['entries'] ?? [];
?>
<?php ob_start(); ?>
<div class="rpt-kpi">
    <div class="kpi-label">Entries</div>
    <div class="kpi-value"><?= (int)($jeReport['total'] ?? 0) ?></div>
</div>
<div class="rpt-kpi">
    <div class="kpi-label">Period</div>
    <div class="kpi-value" style="font-size:1rem"><?= htmlspecialchars($jeReport['from_date'] ?? '', ENT_QUOTES) ?> → <?= htmlspecialchars($jeReport['to_date'] ?? '', ENT_QUOTES) ?></div>
</div>
<?php $rpt_kpis = ob_get_clean(); ?>

<div class="rpt-result-panel">
    <div class="rpt-result-panel-head">
        <h2><i class="fas fa-list me-1"></i> Journal entries</h2>
    </div>
    <div class="table-responsive">
        <table class="table rpt-data-table mb-0">
            <thead>
                <tr>
                    <th style="width:2rem"></th>
                    <th>Date</th>
                    <th>Entry</th>
                    <th>Reference</th>
                    <th>Branch</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                    <th>Status</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($entries)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No journal entries match these filters.</td></tr>
            <?php else: foreach ($entries as $i => $entry):
                $collapseId = 'je-lines-' . (int)$entry['id'];
            ?>
                <tr>
                    <td>
                        <?php if (!empty($entry['lines'])): ?>
                        <button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="false">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap"><?= htmlspecialchars($entry['entry_date'] ?? '', ENT_QUOTES) ?></td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($entry['entry_no'] ?? '', ENT_QUOTES) ?></div>
                        <?php if (!empty($entry['description'])): ?>
                        <div class="small text-muted"><?= htmlspecialchars($entry['description'], ENT_QUOTES) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($entry['reference_url'])): ?>
                        <a href="<?= htmlspecialchars($entry['reference_url'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($entry['reference_label'] ?? '', ENT_QUOTES) ?>
                            #<?= (int)($entry['reference_id'] ?? 0) ?>
                        </a>
                        <?php else: ?>
                        <?= htmlspecialchars($entry['reference_label'] ?? '—', ENT_QUOTES) ?>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($entry['branch_name'] ?? '—', ENT_QUOTES) ?></td>
                    <td class="text-end"><?= number_format((float)($entry['total_debit'] ?? 0), 2) ?></td>
                    <td class="text-end"><?= number_format((float)($entry['total_credit'] ?? 0), 2) ?></td>
                    <td>
                        <?php if (!empty($entry['is_reversed'])): ?>
                        <span class="badge bg-danger">Reversed</span>
                        <?php else: ?>
                        <span class="badge bg-success">Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($entry['created_by_name'] ?? '—', ENT_QUOTES) ?></td>
                </tr>
                <?php if (!empty($entry['lines'])): ?>
                <tr>
                    <td colspan="9" class="p-0 border-0">
                        <div class="collapse" id="<?= $collapseId ?>">
                            <div class="p-3 bg-light">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr><th>Ledger</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($entry['lines'] as $line): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= BASE_URL ?>Report/GeneralLedger?search=1&amp;ledger_id=<?= (int)($line['ledger_id'] ?? 0) ?>&amp;from_date=<?= urlencode((string)($entry['entry_date'] ?? date('Y-m-01'))) ?>&amp;to_date=<?= urlencode((string)($entry['entry_date'] ?? date('Y-m-d'))) ?>">
                                                    <?= htmlspecialchars(($line['ledger_code'] ?? '') . ' ' . ($line['ledger_name'] ?? ''), ENT_QUOTES) ?>
                                                </a>
                                            </td>
                                            <td class="text-end"><?= (float)($line['debit'] ?? 0) > 0 ? number_format((float)$line['debit'], 2) : '—' ?></td>
                                            <td class="text-end"><?= (float)($line['credit'] ?? 0) > 0 ? number_format((float)$line['credit'], 2) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
else:
    $rpt_kpis = '';
?>
<div class="rpt-result-panel">
    <div class="rpt-empty-state">
        <i class="fas fa-file-invoice d-block"></i>
        <h3>Search journal entries</h3>
        <p>Filter by date, reference type, branch, reversal status, or user — expand any row for line detail.</p>
    </div>
</div>
<?php endif;
$rpt_body = ob_get_clean();

ob_start();
include __DIR__ . '/partials/frame.php';
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
