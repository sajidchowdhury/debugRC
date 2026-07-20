<?php
$session = $session ?? [];
$warehouse = $warehouse ?? [];
$products = $products ?? [];
$savedCounts = $savedCounts ?? [];
$categories = $categories ?? [];
$productTotal = (int)($product_total ?? count($products));
$whStatus = $wh_status ?? 'pending';
$whSavedLines = (int)($wh_saved_lines ?? 0);
$whInProgress = $whStatus === 'pending' && $whSavedLines > 0;

$title = 'Count — ' . ($warehouse['warehouse_name'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take-count.css">

<div class="purch-index-app st-take-app container-fluid py-2" id="stCountApp"
     data-session-id="<?= (int)($session['id'] ?? 0) ?>"
     data-warehouse-id="<?= (int)($warehouse['id'] ?? 0) ?>">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-table me-2"></i><?= htmlspecialchars($warehouse['warehouse_name'] ?? '', ENT_QUOTES) ?></h1>
            <p>Session <?= htmlspecialchars($session['session_code'] ?? '', ENT_QUOTES) ?> — count only the items you need (partial count OK)
                <?php if ($whStatus === 'counted'): ?>
                <span class="badge bg-primary ms-1">Warehouse complete</span>
                <?php elseif ($whInProgress): ?>
                <span class="badge bg-warning text-dark ms-1"><?= $whSavedLines ?> line(s) saved — still in progress</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>StockTake/details/<?= (int)($session['id'] ?? 0) ?>" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Session hub
            </a>
        </div>
    </header>

    <p class="st-count-hint-inline mb-2">
        <i class="fas fa-info-circle text-primary me-1"></i>
        Leave physical qty <strong>blank</strong> to skip a product. Only filled rows are saved on each click.
        Stock changes only when you <strong>post the session</strong> from the session hub (after every warehouse is marked complete).
    </p>

    <form id="countForm" class="st-count-workspace">
        <input type="hidden" name="session_id" value="<?= (int)($session['id'] ?? 0) ?>">
        <input type="hidden" name="warehouse_id" value="<?= (int)($warehouse['id'] ?? 0) ?>">

        <div class="st-count-toolbar">
            <div class="st-count-search-wrap" style="min-width:220px">
                <label class="form-label">Search</label>
                <i class="fas fa-search"></i>
                <input type="search" id="stCountSearch" class="form-control form-control-sm"
                       placeholder="Code or name…" autocomplete="off">
            </div>
            <div>
                <label class="form-label">Category</label>
                <select id="stCountCategory" class="form-select form-select-sm">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)($cat['id'] ?? 0) ?>">
                        <?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-0 pt-4">
                <input class="form-check-input" type="checkbox" id="stFilterStock">
                <label class="form-check-label small" for="stFilterStock">In stock only</label>
            </div>
            <div class="form-check mb-0 pt-4">
                <input class="form-check-input" type="checkbox" id="stFilterFilled">
                <label class="form-check-label small" for="stFilterFilled">Filled rows only</label>
            </div>
        </div>

        <div class="st-count-summary-bar" id="stCountSummary">
            <span><strong id="stVisibleCount"><?= $productTotal ?></strong> / <?= $productTotal ?> shown</span>
            <span><strong id="stFilledCount">0</strong> lines to save</span>
            <span>Gain est.: <strong id="stGainTotal" class="st-diff-pos">0.00</strong></span>
            <span>Loss est.: <strong id="stLossTotal" class="st-diff-neg">0.00</strong></span>
            <span>Net (uses avg cost, else receipt): <strong id="stNetImpact">0.00</strong></span>
        </div>

        <div class="st-count-grid-wrap">
            <table class="table table-sm st-count-grid mb-0" id="countTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-end">System</th>
                        <th class="text-end">Physical</th>
                        <th class="text-end">Diff</th>
                        <th class="text-end" title="Latest sales/receipt rate from price list">Receipt rate</th>
                        <th class="text-end">Avg cost</th>
                        <th class="text-end">Impact</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p):
                    $saved = $savedCounts[$p['id']] ?? [];
                    $sys = (float)($p['system_qty'] ?? 0);
                    $avg = (float)($p['avg_cost'] ?? 0);
                    $receipt = (float)($p['receipt_price'] ?? 0);
                    $hasSaved = isset($saved['physical_qty']);
                    $physical = $hasSaved ? (float)$saved['physical_qty'] : '';
                    $diff = $hasSaved ? ((float)$saved['physical_qty'] - $sys) : 0;
                    $impact = $diff * ($avg > 0 ? $avg : $receipt);
                    $catId = (int)($p['category_id'] ?? 0);
                    $catName = $p['category_name'] ?? '—';
                    ?>
                    <tr class="st-count-row <?= $hasSaved ? 'is-counted' : '' ?>"
                        data-product-id="<?= (int)$p['id'] ?>"
                        data-category-id="<?= $catId ?>"
                        data-search="<?= htmlspecialchars(strtolower(($p['product_code'] ?? '') . ' ' . ($p['product_name'] ?? '')), ENT_QUOTES) ?>"
                        data-system="<?= $sys ?>"
                        data-avg="<?= $avg ?>"
                        data-receipt="<?= $receipt ?>">
                        <td class="text-nowrap"><?= htmlspecialchars($p['product_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($catName, ENT_QUOTES) ?></td>
                        <td class="text-end system-qty"><?= number_format($sys, 2) ?></td>
                        <td class="text-end p-1">
                            <input type="number" step="0.01"
                                   class="form-control form-control-sm text-end physical-qty"
                                   data-product-id="<?= (int)$p['id'] ?>"
                                   value="<?= $physical !== '' ? htmlspecialchars((string)$physical, ENT_QUOTES) : '' ?>"
                                   placeholder="—">
                        </td>
                        <td class="text-end difference <?= $diff >= 0 ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= $hasSaved ? number_format($diff, 2) : '—' ?></td>
                        <td class="text-end receipt-price"><?= number_format($receipt, 2) ?></td>
                        <td class="text-end avg-cost"><?= number_format($avg, 2) ?></td>
                        <td class="text-end col-impact <?= $impact >= 0 ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= $hasSaved ? number_format($impact, 2) : '—' ?></td>
                        <td class="p-1">
                            <input type="text" class="form-control form-control-sm reason-input"
                                   data-product-id="<?= (int)$p['id'] ?>"
                                   placeholder="If variance"
                                   value="<?= htmlspecialchars($saved['reason'] ?? '', ENT_QUOTES) ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="st-count-foot">
            <div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="mark_complete" id="markComplete" value="1">
                    <label class="form-check-label small" for="markComplete">
                        Mark this warehouse <strong>complete</strong> when you are done (required before posting the session; partial product list is OK)
                    </label>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>StockTake/details/<?= (int)($session['id'] ?? 0) ?>" class="btn btn-outline-secondary btn-sm">Back to session</a>
                <button type="submit" class="btn btn-success btn-sm" id="btnSaveCount">
                    <i class="fas fa-save me-1"></i> Save count lines
                </button>
            </div>
        </div>
    </form>
</div>

<script>
window.ST_BOOT = {
    baseUrl: <?= json_encode(BASE_URL) ?>,
    sessionId: <?= (int)($session['id'] ?? 0) ?>
};
</script>
<script src="<?= BASE_URL ?>assets/js/StockTake.js"></script>
<script src="<?= BASE_URL ?>assets/js/stock-take-count.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';