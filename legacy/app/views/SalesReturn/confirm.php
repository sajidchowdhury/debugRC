<?php
$return = $return ?? [];
$warehouses = $warehouses ?? [];
$items = $return['items'] ?? [];

$returnCode = $return['return_code'] ?? '';
$customerLabel = trim($return['shop_name'] ?? '') ?: trim($return['customer_name'] ?? '—');
$returnTotal = (float)($return['total_amount'] ?? 0);
$returnDate = !empty($return['return_date']) ? date('d-m-Y', strtotime($return['return_date'])) : '—';
$singleWarehouseId = count($warehouses) === 1 ? (int)$warehouses[0]['id'] : 0;

$formatMoney = static function ($n) {
    return 'Tk ' . number_format((float)$n, 2);
};

$formatQty = static function ($n) {
    $v = (float)$n;
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
};

$branchName = $session_branch_name ?? 'Branch';
$title = 'Confirm return — ' . $returnCode;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-confirm.css">
<meta name="theme-color" content="#d97706">

<div id="sr-confirm-app" class="sr-confirm-app container-fluid py-2">
    <header class="sr-confirm-hero">
        <div>
            <h1><i class="fas fa-warehouse me-2"></i>Step 2 — Warehouse confirm</h1>
            <p>Inspect goods, verify condition, and choose receiving warehouse — stock &amp; credit note post here</p>
            <div class="sr-journey-steps sr-journey-steps--hero" aria-label="Return process">
                <div class="sr-journey-step is-done">
                    <span class="sr-journey-num"><i class="fas fa-check"></i></span>
                    <span class="sr-journey-label">Received from customer</span>
                </div>
                <span class="sr-journey-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                <div class="sr-journey-step is-active">
                    <span class="sr-journey-num">2</span>
                    <span class="sr-journey-label">Warehouse confirm</span>
                </div>
                <span class="sr-journey-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                <div class="sr-journey-step is-muted">
                    <span class="sr-journey-num">3</span>
                    <span class="sr-journey-label">Auto damage (if damaged)</span>
                </div>
            </div>
            <span class="sales-return-branch-tag">
                <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?>
            </span>
            <span class="sales-return-branch-tag ms-1">
                <i class="fas fa-barcode me-1"></i><?= htmlspecialchars($returnCode, ENT_QUOTES) ?>
            </span>
        </div>
        <div class="sr-confirm-hero-actions">
            <a href="<?= BASE_URL ?>SalesReturn/slip/<?= (int)($return['id'] ?? 0) ?>" class="btn btn-light btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-print"></i> Slip
            </a>
            <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-light btn-sm">
                <i class="fas fa-list"></i> List
            </a>
        </div>
    </header>

    <form id="srConfirmForm"
          method="POST"
          action="<?= BASE_URL ?>SalesReturn/confirm_store"
          data-return-code="<?= htmlspecialchars($returnCode, ENT_QUOTES) ?>"
          data-return-total="<?= htmlspecialchars($formatMoney($returnTotal), ENT_QUOTES) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
        <input type="hidden" name="return_id" value="<?= (int)($return['id'] ?? 0) ?>">

        <div class="sr-confirm-layout">
            <section class="sr-confirm-panel">
                <div class="sr-confirm-summary">
                    <div class="sr-confirm-summary-item">
                        <span class="label">Customer</span>
                        <span class="value"><?= htmlspecialchars($customerLabel, ENT_QUOTES) ?></span>
                    </div>
                    <div class="sr-confirm-summary-item">
                        <span class="label">Invoice</span>
                        <span class="value"><?= htmlspecialchars($return['invoice_code'] ?? '—', ENT_QUOTES) ?></span>
                    </div>
                    <div class="sr-confirm-summary-item">
                        <span class="label">Return date</span>
                        <span class="value"><?= htmlspecialchars($returnDate, ENT_QUOTES) ?></span>
                    </div>
                    <div class="sr-confirm-summary-item highlight">
                        <span class="label">Return total</span>
                        <span class="value"><?= $formatMoney($returnTotal) ?></span>
                    </div>
                </div>

                <?php if (!empty($return['reason'])): ?>
                <div class="sr-confirm-reason">
                    <strong>Sales reason:</strong>
                    <?= nl2br(htmlspecialchars($return['reason'], ENT_QUOTES)) ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($return['mobile']) || !empty($return['address'])): ?>
                <p class="small text-muted mb-3">
                    <?php if (!empty($return['mobile'])): ?>
                    <i class="fas fa-phone me-1"></i><?= htmlspecialchars($return['mobile'], ENT_QUOTES) ?>
                    <?php endif; ?>
                    <?php if (!empty($return['address'])): ?>
                    <span class="ms-2"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($return['address'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

                <div class="sr-confirm-progress" aria-hidden="true">
                    <div class="sr-confirm-progress-bar" id="srConfirmProgressBar"></div>
                </div>

                <?php if (count($warehouses) > 0): ?>
                <div class="sr-confirm-bulk-bar">
                    <label for="srBulkWarehouse"><i class="fas fa-layer-group me-1"></i>Apply warehouse to all lines</label>
                    <select id="srBulkWarehouse" class="form-select form-select-sm">
                        <option value="">— Choose warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['warehouse_name'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-warning" id="srApplyBulkWarehouse">
                        Apply to all
                    </button>
                </div>
                <?php else: ?>
                <div class="alert alert-danger mb-3">No warehouse found for your branch. Cannot confirm until a warehouse exists.</div>
                <?php endif; ?>

                <div class="sr-confirm-lines-table-wrap table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0 sr-confirm-lines-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th>Condition</th>
                                <th>Warehouse <span class="text-warning">*</span></th>
                                <th class="text-end">On hand</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $i => $item):
                            $itemId = (int)$item['id'];
                            $cond = trim($item['condition'] ?? 'Good');
                            $isGood = strtolower($cond) !== 'damage';
                            $defaultWh = $singleWarehouseId ?: (int)($item['warehouse_id'] ?? 0);
                            $salesCond = $cond;
                        ?>
                            <tr class="<?= $isGood ? '' : 'is-damage' ?>"
                                data-line-id="<?= $itemId ?>"
                                data-product-id="<?= (int)($item['product_id'] ?? 0) ?>">
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></strong>
                                    <?php if (!empty($item['unit'])): ?>
                                    <span class="text-muted small"> · <?= htmlspecialchars($item['unit'], ENT_QUOTES) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center fw-bold"><?= $formatQty($item['return_qty'] ?? 0) ?></td>
                                <td>
                                    <select class="form-select form-select-sm sr-condition-select">
                                        <option value="Good" <?= $isGood ? 'selected' : '' ?>>Good (restock)</option>
                                        <option value="Damage" <?= !$isGood ? 'selected' : '' ?>>Damage (no stock)</option>
                                    </select>
                                    <input type="hidden" name="items[<?= $itemId ?>][condition]" class="sr-condition-hidden" value="<?= htmlspecialchars($cond, ENT_QUOTES) ?>">
                                    <small class="sr-sales-condition-note text-muted">Sales noted: <?= htmlspecialchars($salesCond, ENT_QUOTES) ?></small>
                                </td>
                                <td>
                                    <input type="hidden"
                                           name="items[<?= $itemId ?>][warehouse_id]"
                                           class="sr-warehouse-hidden"
                                           value="<?= $defaultWh > 0 ? $defaultWh : '' ?>">
                                    <select class="form-select form-select-sm sr-warehouse-select"
                                            <?= $isGood ? 'required' : '' ?>>
                                        <option value="">— Warehouse —</option>
                                        <?php foreach ($warehouses as $w):
                                            $wid = (int)$w['id'];
                                        ?>
                                        <option value="<?= $wid ?>" <?= $defaultWh === $wid ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($w['warehouse_name'], ENT_QUOTES) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="sr-confirm-line-hint <?= $isGood ? '' : 'is-damage-note' ?>">
                                        <?= $isGood ? 'Good — quantity will be added to selected warehouse' : 'Damaged — received then auto write-off (linked damage doc)' ?>
                                    </span>
                                    <input type="hidden" name="items[<?= $itemId ?>][product_id]" value="<?= (int)($item['product_id'] ?? 0) ?>">
                                    <input type="hidden" name="items[<?= $itemId ?>][return_qty]" value="<?= htmlspecialchars((string)($item['return_qty'] ?? 0), ENT_QUOTES) ?>">
                                    <input type="hidden" name="items[<?= $itemId ?>][rate]" value="<?= htmlspecialchars((string)($item['rate'] ?? 0), ENT_QUOTES) ?>">
                                    <input type="hidden" name="items[<?= $itemId ?>][return_item_id]" value="<?= $itemId ?>">
                                </td>
                                <td class="text-end">
                                    <span class="sr-wh-stock-badge" data-role="wh-stock-badge">—</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sr-confirm-form-actions">
                    <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-confirm-submit" <?= empty($warehouses) ? 'disabled' : '' ?>>
                        <i class="fas fa-check-double me-1"></i> Confirm &amp; update stock
                    </button>
                </div>
            </section>

            <aside class="sr-confirm-side">
                <div class="sr-confirm-effects">
                    <h3><i class="fas fa-bolt me-1"></i>On confirm</h3>
                    <ul>
                        <li>Customer ledger <strong>credit note</strong> for <?= $formatMoney($returnTotal) ?></li>
                        <li><strong>Good</strong> lines increase warehouse stock (Dr Inventory / Cr COGS)</li>
                        <li><strong>Damage</strong> lines: received → auto <strong>damage write-off</strong> (Dr Shrinkage / Cr Inventory at cost)</li>
                        <li>Return status becomes <strong>Completed</strong></li>
                    </ul>
                </div>
                <div class="sr-confirm-checklist">
                    <h3>Before you submit</h3>
                    <div class="sr-confirm-check-item" data-check="inspect">
                        <i class="fas fa-circle"></i>
                        <span>Physical goods match return quantities</span>
                    </div>
                    <div class="sr-confirm-check-item" data-check="condition">
                        <i class="fas fa-circle"></i>
                        <span>Condition (Good / Damage) verified per line</span>
                    </div>
                    <div class="sr-confirm-check-item" data-check="warehouse">
                        <i class="fas fa-circle"></i>
                        <span>Every line has a receiving warehouse assigned</span>
                    </div>
                    <p class="sr-confirm-check-note small text-muted mb-0 mt-2">
                        <i class="fas fa-info-circle me-1"></i>
                        Damage lines are received then auto-written off — linked damage document created (no sellable stock).
                    </p>
                </div>
            </aside>
        </div>
    </form>
</div>

<script>
window.SR_CONFIRM_BASE = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/sales-return-confirm.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';