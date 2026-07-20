<?php
$title = $title ?? 'Damage / Write-offs';
$damages = $damages ?? [];
$filters = $filters ?? [];
$warehouses = $warehouses ?? [];
$branchName = $branch_name ?? 'Branch';

$periodTotal = 0.0;
foreach ($damages as $d) {
    if (empty($d['is_reversed'])) {
        $periodTotal += (float)($d['total_value'] ?? 0);
    }
}

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/damage.css">

<div class="purch-index-app st-take-app dmg-app container-fluid py-2">
    <header class="purch-index-hero dmg-hero">
        <div>
            <h1><i class="fas fa-heart-crack me-2"></i>Damage &amp; write-offs</h1>
            <p>Record damaged stock from your branch warehouses — amount at avg cost, GL Dr shrinkage / Cr inventory</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>Damage/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> Record damage
            </a>
            <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-outline-light btn-sm" title="Damaged returns auto-create linked write-offs on confirm">
                <i class="fas fa-undo-alt me-1"></i> Sales returns
            </a>
            <button type="button" class="btn btn-outline-light btn-sm collapsed" data-bs-toggle="collapse" data-bs-target="#dmgFiltersCollapse">
                <i class="fas fa-filter me-1"></i> Filters
            </button>
        </div>
    </header>

    <div class="st-count-summary-bar mb-2">
        <span>Records: <strong><?= count($damages) ?></strong></span>
        <span>Active damage value: <strong><?= number_format($periodTotal, 2) ?></strong></span>
    </div>

    <div class="purch-index-filters-shell">
        <div class="collapse show" id="dmgFiltersCollapse">
            <div class="purch-index-smart-panel">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_from'] ?? date('Y-m-d'), ENT_QUOTES) ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_to'] ?? date('Y-m-d'), ENT_QUOTES) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Warehouse</label>
                        <select name="warehouse_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($warehouses as $w): ?>
                            <option value="<?= (int)$w['id'] ?>" <?= (int)($filters['warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all">All</option>
                            <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="reversed" <?= ($filters['status'] ?? '') === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Source</label>
                        <select name="source" class="form-select form-select-sm">
                            <option value="all">All</option>
                            <option value="return" <?= ($filters['source'] ?? '') === 'return' ? 'selected' : '' ?>>From sales return</option>
                            <option value="manual" <?= ($filters['source'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual only</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="<?= BASE_URL ?>Damage" class="btn btn-outline-secondary btn-sm">Reset</a>
                        <a href="<?= BASE_URL ?>Damage/export?<?= htmlspecialchars(http_build_query($_GET ?? []), ENT_QUOTES) ?>" class="btn btn-success btn-sm"><i class="fas fa-file-csv"></i></a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="st-section-card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 st-index-table">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Code</th>
                        <th>Warehouse</th>
                        <th>Source</th>
                        <th class="text-end">Damage amount</th>
                        <th>GL</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($damages)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No damage records in this period</td></tr>
                <?php else: ?>
                    <?php foreach ($damages as $d):
                        $rev = !empty($d['is_reversed']);
                        ?>
                    <tr class="<?= $rev ? 'is-reversed' : '' ?>">
                        <td><?= !empty($d['damage_date']) ? date('d M Y', strtotime($d['damage_date'])) : '' ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>Damage/details/<?= (int)$d['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($d['damage_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                        </td>
                        <td class="small"><?= htmlspecialchars($d['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="small">
                            <?php if (!empty($d['sales_return_id'])): ?>
                            <a href="<?= BASE_URL ?>SalesReturn/slip/<?= (int)$d['sales_return_id'] ?>" class="text-decoration-none" target="_blank" rel="noopener" title="Sales return slip">
                                <i class="fas fa-undo-alt me-1"></i><?= htmlspecialchars($d['sales_return_code'] ?? 'Return', ENT_QUOTES) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold text-danger"><?= number_format((float)($d['total_value'] ?? 0), 2) ?></td>
                        <td><?= !empty($d['journal_entry_id']) ? '<span class="badge bg-success">Yes</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge-status <?= $rev ? 'reversed' : 'damaged' ?>"><?= $rev ? 'reversed' : 'active' ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= BASE_URL ?>Damage/details/<?= (int)$d['id'] ?>" class="btn btn-outline-primary btn-sm py-0">View</a>
                            <?php if (!empty($d['can_reverse'])): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 js-dmg-reverse"
                                    data-damage-id="<?= (int)$d['id'] ?>"
                                    data-damage-code="<?= htmlspecialchars($d['damage_code'] ?? '', ENT_QUOTES) ?>">
                                <i class="fas fa-undo"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>window.DMG_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/Damage.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';