<?php
$session = $session ?? [];
$warehouses = $warehouses ?? [];
$progress = $progress ?? [];
$variances = $variances ?? [];
$movements = $movements ?? [];
$canPost = !empty($can_post);
$journal_blocks = $journal_blocks ?? [];
$sessionAudit = $session_audit ?? [];
$auditItems = $sessionAudit['items'] ?? [];
$auditSummary = $sessionAudit['summary'] ?? [];
$auditReady = !empty($sessionAudit['ready_to_post']);

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$isReversed = !empty($session['is_reversed']);
$status = $isReversed ? 'reversed' : ($session['status'] ?? 'draft');
$code = $session['session_code'] ?? '';
$totalWh = (int)($progress['total_wh'] ?? 0);
$countedWh = (int)($progress['counted_wh'] ?? 0);
$varLines = (int)($progress['variance_lines'] ?? 0);
$varValue = (float)($progress['variance_value'] ?? 0);
$gainValue = (float)($progress['gain_value'] ?? 0);
$lossValue = (float)($progress['loss_value'] ?? 0);
$netValue = $gainValue - $lossValue;
$countPct = $totalWh > 0 ? min(100, (int)round(($countedWh / $totalWh) * 100)) : 0;
$pendingWh = max(0, $totalWh - $countedWh);
$step1Done = true;
$step2Done = $countedWh >= $totalWh && $totalWh > 0;
$step3Done = $status === 'adjusted';

$title = 'Stock Take — ' . $code;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-audit-checklist.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="purch-index-app st-take-app container-fluid py-2" id="stockTakeDetails"
     data-session-id="<?= (int)($session['id'] ?? 0) ?>" data-can-post="<?= $canPost ? '1' : '0' ?>">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-clipboard-check me-2"></i><?= htmlspecialchars($code, ENT_QUOTES) ?></h1>
            <p>Save counts per warehouse, then post all adjustments together</p>
            <span class="st-status-pill <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars(strtoupper($status), ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <?php if ($canPost): ?>
            <button type="button" class="btn btn-success btn-sm" id="btnPostSession">
                <i class="fas fa-check-double me-1"></i> Post adjustments
            </button>
            <?php endif; ?>
            <?php if (!$isReversed && $status === 'adjusted'): ?>
            <button type="button" class="btn btn-warning btn-sm js-st-reverse"
                    data-session-id="<?= (int)$session['id'] ?>"
                    data-session-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>StockTake/variance?session_id=<?= (int)($session['id'] ?? 0) ?>" class="btn btn-outline-light btn-sm" title="Variance lines for this session">
                <i class="fas fa-table me-1"></i> Variance
            </a>
            <a href="<?= BASE_URL ?>StockTake/checklist" class="btn btn-outline-light btn-sm" title="Full audit checklist">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <a href="<?= BASE_URL ?>StockTake" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="alert alert-danger">
        <strong>Reversed.</strong> <?= htmlspecialchars($session['reverse_reason'] ?? '', ENT_QUOTES) ?>
        <?php if (!empty($session['reversed_at'])): ?>
        <span class="small"> — <?= date('d M Y H:i', strtotime($session['reversed_at'])) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="st-detail-stats">
        <div class="st-detail-stat">
            <span class="label">Branch</span>
            <span class="value"><?= htmlspecialchars($session['branch_name'] ?? '', ENT_QUOTES) ?></span>
            <span class="sub"><?= !empty($session['take_date']) ? date('d M Y', strtotime($session['take_date'])) : '' ?></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Warehouses</span>
            <span class="value"><?= $countedWh ?> / <?= $totalWh ?></span>
            <span class="sub">Counted / total</span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Variance lines</span>
            <span class="value"><?= $varLines ?></span>
            <span class="sub">
                Gain <?= number_format($gainValue, 2) ?> · Loss <?= number_format($lossValue, 2) ?>
                · Net <span class="<?= $netValue >= 0 ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= number_format($netValue, 2) ?></span>
            </span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Created by</span>
            <span class="value" style="font-size:0.9rem"><?= htmlspecialchars($session['created_by_name'] ?? '—', ENT_QUOTES) ?></span>
            <?php if (!empty($session['posted_at'])): ?>
            <span class="sub">Posted <?= date('d M Y H:i', strtotime($session['posted_at'])) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($totalWh > 0 && $status !== 'adjusted' && !$isReversed): ?>
    <div class="mb-3">
        <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Count progress</span>
            <span class="fw-semibold"><?= $countPct ?>%</span>
        </div>
        <div class="progress" style="height:8px">
            <div class="progress-bar bg-info" style="width:<?= $countPct ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($auditItems)): ?>
    <section class="st-section-card mb-3 st-session-audit">
        <div class="st-section-head d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="fas fa-shield-alt me-1"></i> Session audit</span>
            <span class="small fw-normal text-muted">
                <?php if ($auditReady): ?>
                <span class="badge bg-success">Ready to post</span>
                <?php endif; ?>
                <?= (int)($auditSummary['pass'] ?? 0) ?> pass ·
                <?= (int)($auditSummary['warn'] ?? 0) ?> warn ·
                <?= (int)($auditSummary['fail'] ?? 0) ?> fail
            </span>
        </div>
        <div class="p-2 px-3">
            <?php foreach ($auditItems as $item):
                $st = (string)($item['status'] ?? 'info');
                ?>
            <article class="purch-audit-item status-<?= htmlspecialchars($st, ENT_QUOTES) ?> py-2">
                <div class="status-icon"><i class="fas <?= $statusIcon($st) ?>"></i></div>
                <div class="flex-grow-1">
                    <h3 class="mb-0" style="font-size:0.9rem"><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES) ?></h3>
                    <?php if (!empty($item['detail'])): ?>
                    <p class="detail mb-0"><?= htmlspecialchars($item['detail'], ENT_QUOTES) ?></p>
                    <?php endif; ?>
                </div>
                <span class="purch-audit-badge <?= htmlspecialchars($st, ENT_QUOTES) ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="st-steps">
        <div class="st-step <?= $step1Done ? 'is-done' : '' ?>">
            <span class="num">1</span> Session created
        </div>
        <div class="st-step <?= $step2Done ? 'is-done' : ($status === 'counting' ? 'is-active' : '') ?>">
            <span class="num">2</span> Count each warehouse (partial lines OK)
        </div>
        <div class="st-step <?= $step3Done ? 'is-done' : ($canPost ? 'is-active' : '') ?>">
            <span class="num">3</span> Finalize here — post adjustments
        </div>
    </div>

    <?php if ($canPost): ?>
    <div class="st-finalize-banner">
        <div>
            <h2><i class="fas fa-flag-checkered me-1"></i> Ready to finalize</h2>
            <p>All <?= $totalWh ?> warehouse(s) are marked complete. Click below to apply stock changes and post GL (shrinkage / surplus at avg cost).</p>
        </div>
        <button type="button" class="btn btn-light btn-lg" id="btnPostSessionBanner">
            <i class="fas fa-check-double me-1"></i> Post session now
        </button>
    </div>
    <?php elseif (in_array($status, ['draft', 'counting'], true) && !$isReversed && $totalWh > 0): ?>
    <div class="alert alert-info mb-3">
        <strong>Session hub</strong> — Count each warehouse below (you do not need every product).
        <?php if ($pendingWh > 0): ?>
        <span class="d-block mt-1"><?= $pendingWh ?> warehouse(s) still need counting. After all are done, the green <strong>Finalize</strong> bar appears on this page.</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <section class="st-section-card">
        <div class="st-section-head">
            <span><i class="fas fa-warehouse me-1"></i> Warehouses in this session</span>
            <span class="text-muted small fw-normal">Open each → count → return here to finalize</span>
        </div>
        <div class="p-3">
            <div class="row g-3">
                <?php foreach ($warehouses as $w):
                    $whStatus = $w['status'] ?? 'pending';
                    $savedLines = (int)($w['saved_lines'] ?? 0);
                    $whVariances = (int)($w['variance_lines'] ?? 0);
                    $whNet = (float)($w['net_impact'] ?? 0);
                    $inProgress = $whStatus === 'pending' && $savedLines > 0;
                    $displayStatus = $whStatus === 'counted' ? 'Complete' : ($inProgress ? 'In progress' : 'Not started');
                    $cardClass = $whStatus === 'posted' ? 'is-posted' : ($whStatus === 'counted' ? 'is-counted' : ($inProgress ? 'is-progress' : ''));
                    $canCount = !$isReversed && $status !== 'adjusted' && $whStatus !== 'posted';
                    $badgeClass = $whStatus === 'posted' ? 'success' : ($whStatus === 'counted' ? 'primary' : ($inProgress ? 'warning text-dark' : 'secondary'));
                    ?>
                <div class="col-md-4">
                    <div class="st-wh-card <?= $cardClass ?>">
                        <h6 class="mb-1"><?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?></h6>
                        <span class="badge bg-<?= $badgeClass ?> mb-1">
                            <?= htmlspecialchars($displayStatus, ENT_QUOTES) ?>
                        </span>
                        <?php if ($savedLines > 0): ?>
                        <p class="small text-muted mb-2 mb-md-1">
                            <?= $savedLines ?> line(s) saved
                            <?php if ($whVariances > 0): ?>
                            · <?= $whVariances ?> variance · Net
                            <span class="<?= $whNet >= 0 ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= number_format($whNet, 2) ?></span>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                        <?php if ($canCount): ?>
                        <a href="<?= BASE_URL ?>StockTake/count/<?= (int)$session['id'] ?>/<?= (int)$w['warehouse_id'] ?>"
                           class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-edit me-1"></i> <?= $whStatus === 'counted' ? 'Edit count' : 'Count items' ?>
                        </a>
                        <?php elseif ($whStatus === 'posted'): ?>
                        <p class="small text-muted mb-0">Posted to stock</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>

    <?php if (!empty($variances)): ?>
    <section class="st-section-card">
        <div class="st-section-head">Variance preview (before / after post)</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Warehouse</th>
                        <th>Product</th>
                        <th class="text-end">System</th>
                        <th class="text-end">Physical</th>
                        <th class="text-end">Diff</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Impact</th>
                        <th><?= $status === 'adjusted' ? 'Applied' : 'Pending' ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($variances as $v):
                    $diff = (float)$v['physical_qty'] - (float)$v['system_qty'];
                    $impact = $diff * (float)($v['rate'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($v['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars(($v['product_code'] ?? '') . ' ' . ($v['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)$v['system_qty'], 2) ?></td>
                        <td class="text-end"><?= number_format((float)$v['physical_qty'], 2) ?></td>
                        <td class="text-end <?= $diff >= 0 ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= number_format($diff, 2) ?></td>
                        <td class="text-end"><?= number_format((float)($v['rate'] ?? 0), 2) ?></td>
                        <td class="text-end <?= $impact >= 0 ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= number_format($impact, 2) ?></td>
                        <td><?= !empty($v['is_applied']) ? '<span class="text-success">Yes</span>' : '<span class="text-warning">No</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($movements)): ?>
    <section class="st-section-card">
        <div class="st-section-head">Stock movements</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr class="<?= !empty($m['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td><?= htmlspecialchars($m['transaction_date'] ?? '', ENT_QUOTES) ?></td>
                        <td><code class="small"><?= htmlspecialchars($m['reference_type'] ?? '', ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars(($m['product_code'] ?? '') . ' ' . ($m['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($m['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($m['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($m['rate'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>window.ST_BOOT = { baseUrl: <?= json_encode(BASE_URL) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/StockTake.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';