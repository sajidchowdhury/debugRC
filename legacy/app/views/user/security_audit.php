<?php
ob_start();
require_once __DIR__ . '/../../helpers/SecurityAuditHelper.php';

$title = $title ?? 'Security audit';
$entries = $entries ?? [];
$stats = $stats ?? ['total' => 0, 'login' => 0, 'login_success' => 0, 'login_failure' => 0, 'user' => 0, 'employee' => 0];
$filters = $filters ?? ['source' => 'all', 'outcome' => 'all', 'q' => ''];
$source = (string)($filters['source'] ?? 'all');
$outcome = (string)($filters['outcome'] ?? 'all');
$searchQuery = (string)($filters['q'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/user-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/security-audit.css">

<div class="branch-hub user-theme security-audit-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-shield-halved me-2"></i>Security audit</h1>
            <p>Login attempts, user account changes, and employee workforce events — one timeline across three audit stores.</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>employee/create" class="btn btn-outline-light btn-sm">
                <i class="fas fa-user-plus me-1"></i> New employee
            </a>
            <a href="<?= BASE_URL ?>user/create" class="btn btn-outline-light btn-sm">
                <i class="fas fa-user-shield me-1"></i> New user
            </a>
            <a href="<?= BASE_URL ?>employee" class="btn btn-light btn-sm">
                <i class="fas fa-id-badge me-1"></i> Employees
            </a>
            <a href="<?= BASE_URL ?>user" class="btn btn-light btn-sm">
                <i class="fas fa-users-cog me-1"></i> Users
            </a>
        </div>
    </header>

    <div class="branch-audit-summary security-audit-summary">
        <span class="branch-audit-chip"><i class="fas fa-list"></i> <?= (int)$stats['total'] ?> shown</span>
        <span class="branch-audit-chip login"><i class="fas fa-right-to-bracket"></i> <?= (int)$stats['login'] ?> login</span>
        <span class="branch-audit-chip created"><i class="fas fa-circle-check"></i> <?= (int)$stats['login_success'] ?> success</span>
        <span class="branch-audit-chip status"><i class="fas fa-triangle-exclamation"></i> <?= (int)$stats['login_failure'] ?> failed</span>
        <span class="branch-audit-chip user"><i class="fas fa-users-cog"></i> <?= (int)$stats['user'] ?> user</span>
        <span class="branch-audit-chip employee"><i class="fas fa-id-badge"></i> <?= (int)$stats['employee'] ?> employee</span>
    </div>

    <form method="get" action="<?= BASE_URL ?>user/security_audit" class="security-audit-filters branch-hub-panel">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="filterSource">Source</label>
                <select id="filterSource" name="source" class="form-select form-select-sm">
                    <?php foreach (SecurityAuditHelper::sourceOptions() as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $source === $value ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1" for="filterOutcome">Login outcome</label>
                <select id="filterOutcome" name="outcome" class="form-select form-select-sm">
                    <?php foreach (SecurityAuditHelper::outcomeOptions() as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $outcome === $value ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1" for="filterQ">Search</label>
                <input type="search" id="filterQ" name="q" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>"
                       placeholder="Username, action, IP, details…">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                    <i class="fas fa-filter me-1"></i> Apply
                </button>
                <a href="<?= BASE_URL ?>user/security_audit" class="btn btn-sm btn-outline-secondary" title="Reset filters">
                    <i class="fas fa-rotate-left"></i>
                </a>
            </div>
        </div>
    </form>

    <div class="branch-hub-panel branch-audit-panel">
        <div class="table-responsive">
            <table class="table table-borderless mb-0 align-middle w-100" id="securityAuditTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Source</th>
                        <th>Actor / username</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 opacity-50 d-block"></i>
                            No security audit events match your filters.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($entries as $row):
                        $rowSource = (string)($row['source'] ?? '');
                        $targetUrl = $row['target_url'] ?? null;
                    ?>
                    <tr>
                        <td><small class="text-nowrap"><?= htmlspecialchars((string)($row['timestamp'] ?? ''), ENT_QUOTES) ?></small></td>
                        <td>
                            <span class="security-source-chip <?= SecurityAuditHelper::sourceChipClass($rowSource) ?>">
                                <?= htmlspecialchars(SecurityAuditHelper::sourceLabel($rowSource), ENT_QUOTES) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge rounded-pill bg-light text-dark border">
                                <?= htmlspecialchars((string)($row['performed_by_label'] ?? '—'), ENT_QUOTES) ?>
                            </span>
                        </td>
                        <td>
                            <span class="branch-audit-action <?= htmlspecialchars((string)($row['action_class'] ?? 'other'), ENT_QUOTES) ?>">
                                <?= htmlspecialchars((string)($row['action_label'] ?? ''), ENT_QUOTES) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($targetUrl): ?>
                                <a href="<?= BASE_URL . htmlspecialchars((string)$targetUrl, ENT_QUOTES) ?>" class="text-decoration-none fw-semibold">
                                    <?= htmlspecialchars((string)($row['target_label'] ?? '—'), ENT_QUOTES) ?>
                                </a>
                            <?php else: ?>
                                <strong><?= htmlspecialchars((string)($row['target_label'] ?? '—'), ENT_QUOTES) ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['details_html'] ?? '<span class="text-muted">—</span>' ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars((string)($row['ip'] ?? 'unknown'), ENT_QUOTES) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="branch-audit-meta-foot security-audit-meta">
            <span><i class="fas fa-database me-1"></i> Sources:</span>
            <code>logs/login_audit.log</code>
            <code>user_audit_log</code> / <code>logs/user_audit.log</code> (<code>user_</code>)
            <code>logs/user_audit.log</code> (<code>employee_</code>)
            <span class="ms-2">
                <a href="<?= BASE_URL ?>user/audit" class="text-decoration-none">User-only view</a>
                ·
                <a href="<?= BASE_URL ?>employee/audit" class="text-decoration-none">Employee-only view</a>
            </span>
        </div>
    </div>
</div>

<script>
$(function() {
    const $table = $('#securityAuditTable');
    const hasRows = $table.find('tbody tr').length > 0 && $table.find('tbody tr td').length > 1;
    if (!hasRows) return;

    $table.DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        language: {
            emptyTable: 'No security audit events found.',
            search: 'Filter table:'
        },
        columnDefs: [
            { orderable: false, targets: [5] }
        ]
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
