<?php
ob_start();
$branch = $branch ?? [];
$usage = $usage ?? [];
$stock = $stock ?? ['total_qty' => 0, 'product_lines' => 0];
$stockByCategory = $stockByCategory ?? [];
$stockByGroup = $stockByGroup ?? [];
$warehouses = $warehouses ?? [];
$employees = $employees ?? [];
$branchId = (int)($branch['id'] ?? 0);
$isActive = !empty($branch['is_active']);
$canManage = Auth::isAdmin();
$title = $title ?? 'Branch hub';
$totalQty = (float)($stock['total_qty'] ?? 0);
$maxCatQty = 0.0;
$maxGrpQty = 0.0;
$maxWhQty = 0.0;
foreach ($stockByCategory as $row) {
    $maxCatQty = max($maxCatQty, (float)($row['total_qty'] ?? 0));
}
foreach ($stockByGroup as $row) {
    $maxGrpQty = max($maxGrpQty, (float)($row['total_qty'] ?? 0));
}
foreach ($warehouses as $wh) {
    $maxWhQty = max($maxWhQty, (float)($wh['stock_qty'] ?? 0));
}
$hubBarPct = static function (float $qty, float $max): float {
    if ($max <= 0) {
        return 0.0;
    }

    return min(100.0, round(($qty / $max) * 100, 1));
};
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }

    return $letters !== '' ? $letters : '?';
};
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">

<div class="branch-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-sitemap me-2"></i>
                <?= htmlspecialchars($branch['branch_name'] ?? 'Branch', ENT_QUOTES) ?>
            </h1>
            <p>Branch hub — stock footprint, warehouse sites, and team at a glance.</p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($branch['branch_code'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($canManage): ?>
            <a href="<?= BASE_URL ?>branch/edit/<?= $branchId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="<?= BASE_URL ?>warehouse/create?branch=<?= $branchId ?>" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> Add warehouse
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>branch" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All branches
            </a>
        </div>
    </header>

    <?php if ((int)($usage['pending_demands'] ?? 0) > 0 || (int)($usage['open_invoices'] ?? 0) > 0): ?>
    <div class="hub-alert-strip">
        <?php if ((int)($usage['pending_demands'] ?? 0) > 0): ?>
        <a href="<?= BASE_URL ?>BranchDemand" class="hub-alert-chip warn">
            <i class="fas fa-truck-ramp-box"></i>
            <?= (int)$usage['pending_demands'] ?> pending branch demand(s)
        </a>
        <?php endif; ?>
        <?php if ((int)($usage['open_invoices'] ?? 0) > 0): ?>
        <span class="hub-alert-chip info">
            <i class="fas fa-receipt"></i>
            <?= (int)$usage['open_invoices'] ?> open invoice(s)
        </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-warehouse"></i></div>
            <div>
                <div class="stat-value"><?= (int)($usage['warehouses'] ?? 0) ?></div>
                <div class="stat-label">Warehouses</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value"><?= (int)($usage['employees'] ?? 0) ?></div>
                <div class="stat-label">Team members</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-cubes"></i></div>
            <div>
                <div class="stat-value"><?= number_format($totalQty, 0) ?></div>
                <div class="stat-label">Units on hand</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-barcode"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stock['product_lines'] ?? 0) ?></div>
                <div class="stat-label">Product lines</div>
            </div>
        </div>
    </div>

    <div class="branch-hub-panel mb-3">
        <div class="hub-panel-body">
            <div class="branch-form-section-head mb-3">
                <span class="icon-wrap teal"><i class="fas fa-chart-pie"></i></span>
                Stock composition (all sites)
            </div>

            <?php if ($totalQty <= 0.0001): ?>
            <div class="hub-empty-state py-3">
                <i class="fas fa-box-open d-block"></i>
                <p class="mb-0">No stock across branch warehouses yet.</p>
            </div>
            <?php else: ?>
            <div class="hub-breakdown-grid">
                <div class="hub-breakdown-card">
                    <div class="hub-breakdown-card-head">
                        <h3><i class="fas fa-layer-group me-1"></i> By category</h3>
                        <span class="hub-breakdown-count"><?= count($stockByCategory) ?></span>
                    </div>
                    <div class="hub-breakdown-list">
                        <?php foreach ($stockByCategory as $row): ?>
                        <?php $qty = (float)($row['total_qty'] ?? 0); ?>
                        <div class="hub-breakdown-row">
                            <div class="hub-breakdown-label"><?= htmlspecialchars($row['label'] ?? '', ENT_QUOTES) ?></div>
                            <div class="hub-breakdown-qty"><?= number_format($qty, 0) ?></div>
                            <div class="hub-breakdown-meta">
                                <div class="hub-breakdown-bar"><span style="width: <?= $hubBarPct($qty, $maxCatQty) ?>%"></span></div>
                                <span class="hub-breakdown-skus"><?= (int)($row['product_lines'] ?? 0) ?> SKU</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="hub-breakdown-card">
                    <div class="hub-breakdown-card-head">
                        <h3><i class="fas fa-object-group me-1"></i> By product group</h3>
                        <span class="hub-breakdown-count"><?= count($stockByGroup) ?></span>
                    </div>
                    <div class="hub-breakdown-list">
                        <?php foreach ($stockByGroup as $row): ?>
                        <?php $qty = (float)($row['total_qty'] ?? 0); ?>
                        <div class="hub-breakdown-row">
                            <div class="hub-breakdown-label"><?= htmlspecialchars($row['label'] ?? '', ENT_QUOTES) ?></div>
                            <div class="hub-breakdown-qty"><?= number_format($qty, 0) ?></div>
                            <div class="hub-breakdown-meta">
                                <div class="hub-breakdown-bar group"><span style="width: <?= $hubBarPct($qty, $maxGrpQty) ?>%"></span></div>
                                <span class="hub-breakdown-skus"><?= (int)($row['product_lines'] ?? 0) ?> SKU</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="branch-hub-panel mb-3">
        <div class="hub-panel-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="branch-form-section-head mb-0">
                    <span class="icon-wrap amber"><i class="fas fa-warehouse"></i></span>
                    Warehouse sites (<?= count($warehouses) ?>)
                </div>
                <a href="<?= BASE_URL ?>warehouse?branch=<?= $branchId ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-list me-1"></i> Manage list
                </a>
            </div>

            <?php if (empty($warehouses)): ?>
            <div class="hub-empty-state py-3">
                <i class="fas fa-warehouse d-block"></i>
                <p class="mb-2">No active warehouses on this branch yet.</p>
                <?php if ($canManage): ?>
                <a href="<?= BASE_URL ?>warehouse/create?branch=<?= $branchId ?>" class="btn btn-sm btn-outline-primary">Add warehouse</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="hub-warehouse-grid">
                <?php foreach ($warehouses as $wh): ?>
                <?php
                $whQty = (float)($wh['stock_qty'] ?? 0);
                $whShare = $totalQty > 0 ? round(($whQty / $totalQty) * 100, 1) : 0;
                ?>
                <a href="<?= BASE_URL ?>warehouse/show/<?= (int)$wh['id'] ?>" class="hub-warehouse-card">
                    <div class="hub-warehouse-card-top">
                        <div>
                            <h4><?= htmlspecialchars($wh['warehouse_name'] ?? '', ENT_QUOTES) ?></h4>
                            <span class="branch-code-pill"><?= htmlspecialchars($wh['warehouse_code'] ?? '', ENT_QUOTES) ?></span>
                        </div>
                        <div class="hub-warehouse-card-icon"><i class="fas fa-warehouse"></i></div>
                    </div>
                    <div class="hub-breakdown-meta">
                        <div class="hub-breakdown-bar">
                            <span style="width: <?= $hubBarPct($whQty, $maxWhQty) ?>%"></span>
                        </div>
                        <span class="hub-breakdown-skus"><?= $whShare ?>% of branch</span>
                    </div>
                    <div class="hub-warehouse-card-stats">
                        <span>
                            <?php if ($whQty > 0.0001): ?>
                            <strong><?= number_format($whQty, 0) ?></strong> units
                            <?php else: ?>
                            <strong>Empty</strong>
                            <?php endif; ?>
                        </span>
                        <span><?= (int)($wh['product_lines'] ?? 0) ?> SKU</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="branch-hub-panel h-100 hub-contact-card">
                <div class="hub-panel-body">
                    <div class="branch-form-section-head mb-3">
                        <span class="icon-wrap indigo"><i class="fas fa-address-book"></i></span>
                        Contact &amp; details
                    </div>
                    <?php if (!empty($branch['phone'])): ?>
                    <div class="branch-contact"><i class="fas fa-phone"></i> <?= htmlspecialchars($branch['phone'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['email'])): ?>
                    <div class="branch-contact"><i class="fas fa-envelope"></i> <?= htmlspecialchars($branch['email'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['address'])): ?>
                    <div class="branch-contact"><i class="fas fa-location-dot"></i> <?= nl2br(htmlspecialchars($branch['address'], ENT_QUOTES)) ?></div>
                    <?php else: ?>
                    <p class="text-muted small mb-0">No address on file.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="branch-hub-panel h-100">
                <div class="hub-panel-body">
                    <div class="branch-form-section-head mb-3">
                        <span class="icon-wrap teal"><i class="fas fa-users"></i></span>
                        Team (<?= (int)($usage['employees'] ?? 0) ?> active)
                    </div>
                    <?php if (empty($employees)): ?>
                    <p class="text-muted small mb-0">No active employees assigned to this branch.</p>
                    <?php else: ?>
                    <div class="hub-team-grid">
                        <?php foreach ($employees as $emp): ?>
                        <div class="hub-team-card">
                            <div class="hub-team-avatar"><?= htmlspecialchars($initials($emp['name'] ?? ''), ENT_QUOTES) ?></div>
                            <div class="hub-team-info">
                                <strong><?= htmlspecialchars($emp['name'] ?? '', ENT_QUOTES) ?></strong>
                                <span><?= htmlspecialchars($emp['designation'] ?? $emp['role'] ?? '', ENT_QUOTES) ?></span>
                                <?php if (!empty($emp['employee_code'])): ?>
                                <span class="d-block"><?= htmlspecialchars($emp['employee_code'], ENT_QUOTES) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ((int)($usage['employees'] ?? 0) > count($employees)): ?>
                    <p class="small text-muted mt-3 mb-0">Showing first <?= count($employees) ?> of <?= (int)$usage['employees'] ?> team members.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
