<?php
ob_start();
$warehouse = $warehouse ?? [];
$usage = $usage ?? [];
$stockByCategory = $stockByCategory ?? [];
$stockByGroup = $stockByGroup ?? [];
$transfers = $transfers ?? [];
$adjustments = $adjustments ?? [];
$warehouseId = (int)($warehouse['id'] ?? 0);
$branchId = (int)($warehouse['branch_id'] ?? 0);
$isActive = !empty($warehouse['is_active']);
$canManage = Auth::isAdmin();
$title = $title ?? 'Warehouse hub';
$totalQty = (float)($usage['total_qty'] ?? 0);
$maxCatQty = 0.0;
$maxGrpQty = 0.0;
foreach ($stockByCategory as $row) {
    $maxCatQty = max($maxCatQty, (float)($row['total_qty'] ?? 0));
}
foreach ($stockByGroup as $row) {
    $maxGrpQty = max($maxGrpQty, (float)($row['total_qty'] ?? 0));
}
$hubBarPct = static function (float $qty, float $max): float {
    if ($max <= 0) {
        return 0.0;
    }

    return min(100.0, round(($qty / $max) * 100, 1));
};
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/warehouse-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">

<div class="branch-hub warehouse-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-warehouse me-2"></i>
                <?= htmlspecialchars($warehouse['warehouse_name'] ?? 'Warehouse', ENT_QUOTES) ?>
            </h1>
            <p>
                Stock SSOT hub
                <?php if (!empty($warehouse['branch_name'])): ?>
                    · Branch:
                    <a href="<?= BASE_URL ?>branch/show/<?= $branchId ?>" class="text-white fw-semibold">
                        <?= htmlspecialchars($warehouse['branch_name'], ENT_QUOTES) ?>
                    </a>
                <?php endif; ?>
            </p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($warehouse['warehouse_code'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($canManage): ?>
            <a href="<?= BASE_URL ?>warehouse/edit/<?= $warehouseId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>warehouse?branch=<?= $branchId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-list me-1"></i> Branch warehouses
            </a>
            <a href="<?= BASE_URL ?>warehouse" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All sites
            </a>
        </div>
    </header>

    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-cubes"></i></div>
            <div>
                <div class="stat-value"><?= number_format($totalQty, 0) ?></div>
                <div class="stat-label">Units on hand</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-barcode"></i></div>
            <div>
                <div class="stat-value"><?= (int)($usage['product_lines'] ?? 0) ?></div>
                <div class="stat-label">Product lines</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-truck"></i></div>
            <div>
                <div class="stat-value"><?= (int)($usage['pending_dispatches'] ?? 0) ?></div>
                <div class="stat-label">Pending dispatches</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-clipboard-check"></i></div>
            <div>
                <div class="stat-value"><?= (int)($usage['active_stock_takes'] ?? 0) ?></div>
                <div class="stat-label">Active stock takes</div>
            </div>
        </div>
    </div>

    <div class="hub-quick-actions">
        <a href="<?= BASE_URL ?>StockAdjustment/create" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-sliders me-1"></i> Stock adjustment
        </a>
        <a href="<?= BASE_URL ?>WarehouseTransfer/create" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-right-left me-1"></i> Transfer stock
        </a>
        <?php if ($branchId > 0): ?>
        <a href="<?= BASE_URL ?>branch/show/<?= $branchId ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-sitemap me-1"></i> Branch hub
        </a>
        <?php endif; ?>
    </div>

    <nav class="hub-tabs" role="tablist" aria-label="Warehouse hub sections">
        <button type="button" class="hub-tab-btn active" data-hub-tab="composition" role="tab" aria-selected="true">
            <i class="fas fa-chart-pie me-1"></i> Stock composition
        </button>
        <button type="button" class="hub-tab-btn" data-hub-tab="activity" role="tab" aria-selected="false">
            <i class="fas fa-clock-rotate-left me-1"></i> Recent activity
        </button>
    </nav>

    <div class="hub-tab-pane active" data-hub-pane="composition">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if ($totalQty <= 0.0001): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-box-open d-block"></i>
                    <p class="mb-0">No stock in this warehouse yet.</p>
                </div>
                <?php else: ?>
                <div class="hub-breakdown-grid mb-0">
                    <div class="hub-breakdown-card">
                        <div class="hub-breakdown-card-head">
                            <h3><i class="fas fa-layer-group me-1"></i> By category</h3>
                            <span class="hub-breakdown-count"><?= count($stockByCategory) ?> groups</span>
                        </div>
                        <?php if (empty($stockByCategory)): ?>
                        <p class="text-muted small mb-0">No categorized stock.</p>
                        <?php else: ?>
                        <div class="hub-breakdown-list">
                            <?php foreach ($stockByCategory as $row): ?>
                            <?php $qty = (float)($row['total_qty'] ?? 0); ?>
                            <div class="hub-breakdown-row">
                                <div class="hub-breakdown-label" title="<?= htmlspecialchars($row['label'] ?? '', ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($row['label'] ?? '', ENT_QUOTES) ?>
                                </div>
                                <div class="hub-breakdown-qty"><?= number_format($qty, 0) ?></div>
                                <div class="hub-breakdown-meta">
                                    <div class="hub-breakdown-bar">
                                        <span style="width: <?= $hubBarPct($qty, $maxCatQty) ?>%"></span>
                                    </div>
                                    <span class="hub-breakdown-skus"><?= (int)($row['product_lines'] ?? 0) ?> SKU</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="hub-breakdown-card">
                        <div class="hub-breakdown-card-head">
                            <h3><i class="fas fa-object-group me-1"></i> By product group</h3>
                            <span class="hub-breakdown-count"><?= count($stockByGroup) ?> groups</span>
                        </div>
                        <?php if (empty($stockByGroup)): ?>
                        <p class="text-muted small mb-0">No grouped stock.</p>
                        <?php else: ?>
                        <div class="hub-breakdown-list">
                            <?php foreach ($stockByGroup as $row): ?>
                            <?php $qty = (float)($row['total_qty'] ?? 0); ?>
                            <div class="hub-breakdown-row">
                                <div class="hub-breakdown-label" title="<?= htmlspecialchars($row['label'] ?? '', ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($row['label'] ?? '', ENT_QUOTES) ?>
                                </div>
                                <div class="hub-breakdown-qty"><?= number_format($qty, 0) ?></div>
                                <div class="hub-breakdown-meta">
                                    <div class="hub-breakdown-bar group">
                                        <span style="width: <?= $hubBarPct($qty, $maxGrpQty) ?>%"></span>
                                    </div>
                                    <span class="hub-breakdown-skus"><?= (int)($row['product_lines'] ?? 0) ?> SKU</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hub-search-panel" id="warehouseHubStockSearch" data-search-url="<?= BASE_URL ?>warehouse/stock_search/<?= $warehouseId ?>">
                    <div class="hub-search-panel-head">
                        <div>
                            <h3><i class="fas fa-magnifying-glass me-1 text-muted"></i> Product lookup</h3>
                            <p>Search individual SKUs — loaded on demand, paginated (20 per page).</p>
                        </div>
                        <div class="hub-search-wrap">
                            <i class="fas fa-search"></i>
                            <input type="search" id="warehouseHubSearchInput" class="hub-search-input" placeholder="Name, code, category, or group…" autocomplete="off" spellcheck="false">
                        </div>
                    </div>
                    <p class="hub-search-hint" id="warehouseHubSearchHint"></p>
                    <div class="hub-search-results" id="warehouseHubSearchResults"></div>
                    <div id="warehouseHubPagination"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="hub-tab-pane" data-hub-pane="activity">
        <div class="hub-activity-grid">
            <div class="branch-hub-panel">
                <div class="hub-panel-body">
                    <div class="branch-form-section-head mb-3">
                        <span class="icon-wrap amber"><i class="fas fa-right-left"></i></span>
                        Recent transfers
                    </div>
                    <?php if (empty($transfers)): ?>
                    <p class="text-muted small mb-0">No transfer history for this site yet.</p>
                    <?php else: ?>
                    <ul class="hub-activity-list">
                        <?php foreach ($transfers as $t): ?>
                        <li class="hub-activity-item">
                            <a href="<?= BASE_URL ?>WarehouseTransfer/details/<?= (int)$t['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($t['transfer_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                            <div class="small text-muted mt-1">
                                <?= htmlspecialchars($t['transfer_date'] ?? '', ENT_QUOTES) ?>
                                · <?= ($t['direction'] ?? '') === 'out' ? 'Out to' : 'In from' ?>
                                <?= htmlspecialchars(($t['direction'] ?? '') === 'out' ? ($t['to_warehouse'] ?? '') : ($t['from_warehouse'] ?? ''), ENT_QUOTES) ?>
                            </div>
                            <div class="small mt-1">
                                <span class="branch-code-pill"><?= htmlspecialchars($t['status'] ?? '', ENT_QUOTES) ?></span>
                                <?php if (!empty($t['is_reversed'])): ?>
                                <span class="text-danger ms-1">Reversed</span>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="branch-hub-panel">
                <div class="hub-panel-body">
                    <div class="branch-form-section-head mb-3">
                        <span class="icon-wrap indigo"><i class="fas fa-sliders"></i></span>
                        Recent adjustments
                    </div>
                    <?php if (empty($adjustments)): ?>
                    <p class="text-muted small mb-0">No adjustments logged for this warehouse yet.</p>
                    <?php else: ?>
                    <ul class="hub-activity-list">
                        <?php foreach ($adjustments as $a): ?>
                        <li class="hub-activity-item">
                            <a href="<?= BASE_URL ?>StockAdjustment/details/<?= (int)$a['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($a['adjustment_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                            <div class="small text-muted mt-1">
                                <?= htmlspecialchars($a['adjustment_date'] ?? '', ENT_QUOTES) ?>
                                · <?= htmlspecialchars($a['adjustment_type'] ?? '', ENT_QUOTES) ?>
                                · Tk <?= number_format((float)($a['total_amount'] ?? 0), 2) ?>
                            </div>
                            <?php if (!empty($a['is_reversed'])): ?>
                            <div class="small text-danger mt-1">Reversed</div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($warehouse['address'])): ?>
    <div class="branch-hub-panel mt-3">
        <div class="hub-panel-body">
            <div class="branch-contact"><i class="fas fa-location-dot"></i> <?= nl2br(htmlspecialchars($warehouse['address'], ENT_QUOTES)) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="<?= BASE_URL ?>assets/js/warehouse-hub.js"></script>
<script>
(function () {
    document.querySelectorAll('[data-hub-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = btn.getAttribute('data-hub-tab');
            document.querySelectorAll('[data-hub-tab]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            document.querySelectorAll('[data-hub-pane]').forEach(function (pane) {
                pane.classList.toggle('active', pane.getAttribute('data-hub-pane') === tab);
            });
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
