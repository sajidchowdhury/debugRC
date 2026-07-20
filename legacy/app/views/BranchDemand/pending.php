<?php
$title = $title ?? 'Pending Demands';
$demands = $demands ?? [];
$branchName = $branch_name ?? ($_SESSION['branch_name'] ?? 'Branch');

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-demand.css">

<div class="purch-index-app bd-demand-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-hourglass-half me-2"></i>Pending for my branch</h1>
            <p>Incoming requests — approve by selecting warehouses and sending stock</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All demands
            </a>
            <a href="<?= BASE_URL ?>BranchDemand/create" class="btn btn-outline-light btn-sm">
                <i class="fas fa-plus me-1"></i> New demand
            </a>
        </div>
    </header>

    <div class="purch-index-results-card">
        <div class="purch-index-results-head">
            <span class="fw-semibold"><i class="fas fa-inbox me-1"></i> Awaiting fulfillment</span>
            <span class="text-muted small"><?= count($demands) ?> pending</span>
        </div>
        <div class="table-responsive p-2">
            <table class="table table-hover align-middle mb-0 bd-index-table">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Code</th>
                        <th>Requesting branch</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($demands)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="bd-empty-state">
                                <div><i class="fas fa-check-circle d-block text-success"></i>No pending demands — you're caught up.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($demands as $d):
                        $status = $d['status'] ?? 'pending';
                        ?>
                    <tr>
                        <td><?= !empty($d['demand_date']) ? date('d M Y', strtotime($d['demand_date'])) : '—' ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($d['demand_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($d['from_branch'] ?? '', ENT_QUOTES) ?></td>
                        <td><span class="badge-status <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES) ?></span></td>
                        <td class="text-center">
                            <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($d['id'] ?? 0) ?>"
                               class="btn btn-success btn-sm">
                                <i class="fas fa-truck me-1"></i> Process
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';