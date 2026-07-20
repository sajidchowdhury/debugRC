<?php
ob_start();
require_once __DIR__ . '/../../../core/PasswordPolicy.php';
require_once __DIR__ . '/../../../core/RoleRegistry.php';

$title = $title ?? 'Create user';
$employees = $employees ?? [];
$preSelectedEmployee = $preSelectedEmployee ?? null;
$selectedEmployeeId = (int)($preSelectedEmployee['id'] ?? 0);
$hasEmployees = !empty($employees) || $preSelectedEmployee;
$roleLabel = $preSelectedEmployee
    ? RoleRegistry::label((string)($preSelectedEmployee['role'] ?? ''))
    : '';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/user-theme.css">

<div class="branch-hub user-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-user-plus me-2"></i>Create system user</h1>
            <p>Link a login account to an employee — one account per staff member.</p>
            <?php if ($preSelectedEmployee): ?>
                <span class="hero-badge">
                    <i class="fas fa-id-badge"></i>
                    <?= htmlspecialchars($preSelectedEmployee['name'] ?? '', ENT_QUOTES) ?>
                    · <?= htmlspecialchars($preSelectedEmployee['employee_code'] ?? '', ENT_QUOTES) ?>
                </span>
            <?php else: ?>
                <span class="hero-badge"><i class="fas fa-users"></i> <?= count($employees) ?> employee(s) without login</span>
            <?php endif; ?>
        </div>
        <div class="branch-hub-actions">
            <?php if ($selectedEmployeeId > 0): ?>
            <a href="<?= BASE_URL ?>employee/account/<?= $selectedEmployeeId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-id-card-clip me-1"></i> Employee hub
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>user" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Users
            </a>
        </div>
    </header>

    <?php if (!$hasEmployees): ?>
    <div class="branch-hub-panel">
        <div class="hub-panel-body">
            <div class="hub-empty-state py-4">
                <i class="fas fa-user-check d-block"></i>
                <h2 class="h5 mb-2">No employees waiting for a login</h2>
                <p class="mb-3 text-muted">
                    Every current employee already has a system user account.
                    Add a <strong>new employee</strong> first, then return here to create their login.
                </p>
                <a href="<?= BASE_URL ?>employee/create" class="btn btn-primary me-2">
                    <i class="fas fa-user-plus me-1"></i> New employee
                </a>
                <a href="<?= BASE_URL ?>employee" class="btn btn-outline-secondary me-2">Workforce directory</a>
                <a href="<?= BASE_URL ?>user" class="btn btn-outline-secondary">Back to users</a>
            </div>
        </div>
    </div>
    <?php else: ?>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form method="POST" action="<?= BASE_URL ?>user/store" id="createUserForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap indigo"><i class="fas fa-link"></i></span>
                        Link to employee
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="employee_id">Employee <span class="text-danger">*</span></label>
                            <select id="employee_id" name="employee_id" class="form-select" required>
                                <option value="">Select employee</option>
                                <?php if ($preSelectedEmployee): ?>
                                <option value="<?= (int)$preSelectedEmployee['id'] ?>" selected>
                                    <?= htmlspecialchars($preSelectedEmployee['name'] ?? '', ENT_QUOTES) ?>
                                    (<?= htmlspecialchars($preSelectedEmployee['employee_code'] ?? '', ENT_QUOTES) ?>)
                                </option>
                                <?php endif; ?>
                                <?php foreach ($employees as $emp): ?>
                                    <?php if ($preSelectedEmployee && (int)$emp['id'] === (int)$preSelectedEmployee['id']) continue; ?>
                                <option value="<?= (int)$emp['id'] ?>"
                                    data-name="<?= htmlspecialchars($emp['name'] ?? '', ENT_QUOTES) ?>"
                                    data-code="<?= htmlspecialchars($emp['employee_code'] ?? '', ENT_QUOTES) ?>"
                                    data-role="<?= htmlspecialchars($emp['role'] ?? '', ENT_QUOTES) ?>"
                                    data-branch="<?= htmlspecialchars($emp['branch_name'] ?? '', ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($emp['name'] ?? '', ENT_QUOTES) ?>
                                    (<?= htmlspecialchars($emp['employee_code'] ?? '', ENT_QUOTES) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only employees without an existing login are listed.</div>
                        </div>
                    </div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head">
                        <span class="icon-wrap teal"><i class="fas fa-user-shield"></i></span>
                        Login credentials
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                            <input type="text" id="username" name="username" class="form-control" required autocomplete="off">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                            <input type="password" id="password" name="password" class="form-control" required autocomplete="new-password">
                            <div class="form-text"><?= htmlspecialchars(PasswordPolicy::requirementsText(), ENT_QUOTES) ?></div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="confirm_password">Confirm password <span class="text-danger">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create user
                    </button>
                    <a href="<?= BASE_URL ?>user" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="usrPreviewAvatar">?</div>
                <div class="preview-name" id="usrPreviewName">Employee name</div>
                <div class="preview-code" id="usrPreviewCode">Code</div>
                <div class="mt-2 small text-muted" id="usrPreviewMeta">Branch · role</div>
            </div>
            <div class="aside-title mt-4">Next steps</div>
            <p class="small text-muted mb-2">After creating the account, set menu permissions so the user can access the right modules.</p>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                You will be taken to the employee &amp; account hub to finish setup.
            </div>
        </aside>
    </div>
    <?php endif; ?>
</div>

<?php if ($hasEmployees): ?>
<script>
(function() {
    const sel = document.getElementById('employee_id');
    const pre = <?= json_encode([
        'name' => $preSelectedEmployee['name'] ?? '',
        'code' => $preSelectedEmployee['employee_code'] ?? '',
        'role' => $roleLabel,
        'branch' => $preSelectedEmployee['branch_name'] ?? '',
    ], JSON_UNESCAPED_UNICODE) ?>;

    function upd() {
        const opt = sel?.selectedOptions?.[0];
        const name = opt?.dataset?.name || pre.name || 'Employee name';
        const code = opt?.dataset?.code || pre.code || 'Code';
        const role = opt?.dataset?.role ? opt.dataset.role.replace(/_/g, ' ') : (pre.role || 'Role');
        const branch = opt?.dataset?.branch || pre.branch || 'Branch';
        document.getElementById('usrPreviewName').textContent = name;
        document.getElementById('usrPreviewAvatar').textContent = name.charAt(0).toUpperCase() || '?';
        document.getElementById('usrPreviewCode').textContent = code;
        document.getElementById('usrPreviewMeta').textContent = branch + ' · ' + role;
    }
    sel?.addEventListener('change', upd);
    upd();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
