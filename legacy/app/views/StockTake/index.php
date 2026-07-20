<?php
$title = $title ?? 'Stock Take';
$sessions = $sessions ?? [];
$filters = $filters ?? [];
$branchName = $branch_name ?? 'Branch';
$isAdmin = !empty($is_admin);
$branches = $branches ?? [];

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">

<div class="purch-index-app st-take-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-clipboard-check me-2"></i>Physical stock take</h1>
            <p>Count warehouses first, then post adjustments in one step (workflow B)</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
            <span class="purch-index-tag is-alt"><i class="fas fa-cubes me-1"></i>Inventory control</span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>StockTake/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New session
            </a>
            <a href="<?= BASE_URL ?>StockTake/checklist" class="btn btn-outline-light btn-sm" title="Audit checklist">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <a href="<?= BASE_URL ?>StockTake/weekly" class="btn btn-outline-light btn-sm" title="Weekly control">
                <i class="fas fa-chart-line"></i>
            </a>
            <a href="<?= BASE_URL ?>StockTake/variance" class="btn btn-outline-light btn-sm" title="Variance detail report">
                <i class="fas fa-table"></i>
            </a>
            <button type="button" class="btn btn-outline-light btn-sm collapsed" data-bs-toggle="collapse"
                    data-bs-target="#stFiltersCollapse">
                <i class="fas fa-filter me-1"></i> Filters
            </button>
        </div>
    </header>

    <div class="purch-index-filters-shell">
        <div class="collapse show" id="stFiltersCollapse">
            <div class="purch-index-smart-panel">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_from'] ?? '', ENT_QUOTES) ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_to'] ?? '', ENT_QUOTES) ?>">
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="col-12 col-md-2">
                        <label class="form-label small mb-1">Branch</label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="0">My branch</option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"
                                <?= (int)($filters['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['branch_name'], ENT_QUOTES) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-12 col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?= ($filters['status'] ?? 'all') === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="draft" <?= ($filters['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="counting" <?= ($filters['status'] ?? '') === 'counting' ? 'selected' : '' ?>>Counting</option>
                            <option value="adjusted" <?= ($filters['status'] ?? '') === 'adjusted' ? 'selected' : '' ?>>Posted</option>
                            <option value="reversed" <?= ($filters['status'] ?? '') === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Search</label>
                        <input type="search" name="search" class="form-control form-control-sm"
                               placeholder="Code, branch, warehouse…"
                               value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="fas fa-search me-1"></i> Run
                        </button>
                        <a href="<?= BASE_URL ?>StockTake" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
                <p class="small text-muted mb-0 mt-2">Default range: last 30 days. Posting applies stock at warehouse avg cost.</p>
            </div>
        </div>
    </div>

    <div class="purch-index-results-card">
        <div class="purch-index-results-head d-flex justify-content-between flex-wrap gap-2">
            <span class="fw-semibold"><i class="fas fa-list me-1"></i> Sessions</span>
            <div class="d-flex gap-2 align-items-center">
                <span class="text-muted small"><?= count($sessions) ?> record(s)</span>
                <a href="<?= BASE_URL ?>StockTake/export?<?= htmlspecialchars(http_build_query($_GET ?? []), ENT_QUOTES) ?>"
                   class="btn btn-success btn-sm"><i class="fas fa-file-csv me-1"></i> Export</a>
            </div>
        </div>
        <div class="table-responsive p-2">
            <table class="table table-hover align-middle mb-0 st-index-table">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Session</th>
                        <th>Branch</th>
                        <th>WH progress</th>
                        <th class="text-end">Variance</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sessions)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="st-empty-state">
                                <i class="fas fa-inbox d-block mb-2"></i>No sessions for these filters.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sessions as $s):
                        $isRev = !empty($s['is_reversed']);
                        $status = $isRev ? 'reversed' : ($s['status'] ?? 'draft');
                        $whTotal = (int)($s['warehouse_count'] ?? 0);
                        $whDone = (int)($s['warehouses_counted'] ?? 0);
                        ?>
                    <tr class="<?= $isRev ? 'is-reversed' : '' ?>">
                        <td><?= !empty($s['take_date']) ? date('d M Y', strtotime($s['take_date'])) : '—' ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>StockTake/details/<?= (int)$s['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($s['session_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($s['branch_name'] ?? '', ENT_QUOTES) ?></td>
                        <td>
                            <?= $whDone ?>/<?= $whTotal ?>
                            <?php if ($status === 'counting' && $whDone < $whTotal): ?>
                            <span class="text-warning small"> counting</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ((int)($s['variance_lines'] ?? 0) > 0): ?>
                            <span class="d-block"><?= (int)$s['variance_lines'] ?> line(s)</span>
                            <span class="small text-muted">Tk <?= number_format((float)($s['variance_value'] ?? 0), 2) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge-status <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES) ?></span></td>
                        <td class="text-center text-nowrap">
                            <a href="<?= BASE_URL ?>StockTake/details/<?= (int)$s['id'] ?>" class="btn btn-outline-primary btn-sm" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (!$isRev && $status === 'draft'): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteDraftSession(<?= (int)$s['id'] ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php elseif (!$isRev && $status === 'adjusted'): ?>
                            <button type="button" class="btn btn-outline-warning btn-sm js-st-reverse"
                                    data-session-id="<?= (int)$s['id'] ?>"
                                    data-session-code="<?= htmlspecialchars($s['session_code'] ?? '', ENT_QUOTES) ?>">
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

<script>window.ST_BOOT = { baseUrl: <?= json_encode(BASE_URL) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/StockTake.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';