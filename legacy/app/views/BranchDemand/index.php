<?php
$title = $title ?? 'Branch Demands';
$demands = $demands ?? [];
$filters = $filters ?? [];
$branchName = $branch_name ?? ($_SESSION['branch_name'] ?? 'Branch');
$baseUrl = BASE_URL;

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-demand.css">

<div class="purch-index-app bd-demand-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-exchange-alt me-2"></i>Branch demands</h1>
            <p>Request stock from other branches, track fulfillment and inter-branch settlement</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
            <span class="purch-index-tag is-alt"><i class="fas fa-warehouse me-1"></i>Inter-branch</span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>BranchDemand/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New demand
            </a>
            <a href="<?= BASE_URL ?>BranchDemand/pending" class="btn btn-warning btn-sm">
                <i class="fas fa-hourglass-half me-1"></i> Pending for me
            </a>
            <a href="<?= BASE_URL ?>BranchDemand/weekly" class="btn btn-outline-light btn-sm">
                <i class="fas fa-chart-line me-1"></i> Weekly control
            </a>
            <button type="button" class="btn btn-outline-light btn-sm collapsed" data-bs-toggle="collapse"
                    data-bs-target="#bdFiltersCollapse" aria-expanded="false">
                <i class="fas fa-filter me-1"></i> Filters
            </button>
        </div>
    </header>

    <div class="purch-index-filters-shell">
        <div class="collapse show" id="bdFiltersCollapse">
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
                        <label class="form-label small mb-1">View</label>
                        <select name="demand_type" class="form-select form-select-sm">
                            <option value="both" <?= ($filters['demand_type'] ?? 'both') === 'both' ? 'selected' : '' ?>>All related</option>
                            <option value="my_demands" <?= ($filters['demand_type'] ?? '') === 'my_demands' ? 'selected' : '' ?>>My requests (to others)</option>
                            <option value="to_me" <?= ($filters['demand_type'] ?? '') === 'to_me' ? 'selected' : '' ?>>Incoming (to me)</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?= ($filters['status'] ?? 'all') === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="received" <?= ($filters['status'] ?? '') === 'received' ? 'selected' : '' ?>>Received</option>
                            <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="reversed" <?= ($filters['status'] ?? '') === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="purch-index-results-card">
        <div class="purch-index-results-head d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="fas fa-list me-1"></i> Results</span>
            <div class="d-flex gap-2 align-items-center">
                <span class="text-muted small"><?= count($demands) ?> record(s)</span>
                <a href="<?= BASE_URL ?>BranchDemand/export?<?= htmlspecialchars(http_build_query($_GET ?? []), ENT_QUOTES) ?>"
                   class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv me-1"></i> Export
                </a>
            </div>
        </div>
        <div class="table-responsive p-2">
            <table class="table table-hover align-middle mb-0 bd-index-table">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Code</th>
                        <th>From</th>
                        <th>To</th>
                        <th class="text-end">Value</th>
                        <th class="text-end">Outstanding</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($demands)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="bd-empty-state">
                                <div><i class="fas fa-inbox d-block"></i>No branch demands found for these filters.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($demands as $d):
                        $isReversed = !empty($d['is_reversed']);
                        $status = $isReversed ? 'reversed' : ($d['status'] ?? 'pending');
                        $outstanding = (float)($d['outstanding'] ?? 0);
                        ?>
                    <tr class="<?= $isReversed ? 'is-reversed' : '' ?>">
                        <td><?= !empty($d['demand_date']) ? date('d M Y', strtotime($d['demand_date'])) : '—' ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($d['id'] ?? 0) ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($d['demand_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($d['from_branch'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($d['to_branch'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($d['total_value'] ?? 0), 2) ?></td>
                        <td class="text-end <?= $outstanding > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">
                            <?= $status === 'received' ? number_format($outstanding, 2) : '—' ?>
                        </td>
                        <td><span class="badge-status <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES) ?></span></td>
                        <td class="text-center text-nowrap">
                            <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($d['id'] ?? 0) ?>"
                               class="btn btn-outline-primary btn-sm" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (!$isReversed && $status === 'pending'): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm"
                                    onclick="deleteDemand(<?= (int)$d['id'] ?>, <?= json_encode($d['demand_code'] ?? '') ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php elseif (!$isReversed && $status === 'received'): ?>
                            <button type="button" class="btn btn-outline-warning btn-sm"
                                    onclick="reverseDemand(<?= (int)$d['id'] ?>, <?= json_encode($d['demand_code'] ?? '') ?>)">
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

<script>
window.BD_BOOT = { baseUrl: <?= json_encode($baseUrl) ?> };
</script>
<script src="<?= BASE_URL ?>assets/js/BranchDemand.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';