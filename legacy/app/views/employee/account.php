<?php
ob_start();
require_once __DIR__ . '/../../../core/AccountLockout.php';
require_once __DIR__ . '/../../../core/RoleRegistry.php';

$title = $title ?? 'Employee hub';
$employee = $employee ?? [];
$usage = $usage ?? [];
$account = $account ?? null;
$permissionStats = $permissionStats ?? ['view_count' => 0, 'edit_count' => 0, 'menu_count' => 0];
$employeeId = (int)($employee['id'] ?? 0);
$userId = (int)($account['id'] ?? 0);
$hasUser = !empty($account);
$isActiveEmp = !empty($employee['is_active']);
$roleLabel = RoleRegistry::label((string)($employee['role'] ?? ''));
$isLocked = $hasUser && AccountLockout::isLocked($account);
$lockMessage = $isLocked ? AccountLockout::lockMessage($account) : null;
// Phase 0: 2FA removed — $twoFaEnabled variable no longer needed.
$loginActive = $hasUser && !empty($account['is_active']);
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$publicUrl = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') : rtrim(BASE_URL, '/');

$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : '?';
};

$photoPath = !empty($employee['photo'])
    ? $publicUrl . '/' . ltrim((string)$employee['photo'], '/')
    : null;

$lastLoginLabel = '—';
if (!empty($account['last_login'])) {
    $ts = strtotime((string)$account['last_login']);
    $lastLoginLabel = $ts ? date('M j, Y g:i A', $ts) : htmlspecialchars((string)$account['last_login'], ENT_QUOTES);
}

$viewCount = (int)($permissionStats['view_count'] ?? 0);
$editCount = (int)($permissionStats['edit_count'] ?? 0);
$menuCount = (int)($permissionStats['menu_count'] ?? 0);
$accessTotal = max($menuCount, 1);
$viewPct = min(100, round(($viewCount / $accessTotal) * 100));
$editPct = min(100, round(($editCount / $accessTotal) * 100));
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/employee-theme.css">

<div class="branch-hub employee-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-id-card-clip me-2"></i>
                <?= htmlspecialchars($employee['name'] ?? 'Employee', ENT_QUOTES) ?>
            </h1>
            <p>Workforce profile and system login — one place to manage identity and access.</p>
            <span class="hero-badge">
                <?= $isActiveEmp ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($employee['employee_code'] ?? '', ENT_QUOTES) ?>
            </span>
            <span class="hero-badge ms-2">
                <i class="fas fa-user-tag"></i> <?= htmlspecialchars($roleLabel, ENT_QUOTES) ?>
            </span>
            <?php if ($hasUser): ?>
                <span class="hero-badge ms-2">
                    <?= $loginActive ? '<i class="fas fa-user-shield"></i> Login active' : '<i class="fas fa-user-slash"></i> Login inactive' ?>
                </span>
            <?php else: ?>
                <span class="hero-badge ms-2"><i class="fas fa-user-plus"></i> No login</span>
            <?php endif; ?>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>employee/edit/<?= $employeeId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit profile
            </a>
            <?php if ($hasUser): ?>
            <a href="<?= BASE_URL ?>user/permission/<?= $userId ?>" class="btn btn-light btn-sm">
                <i class="fas fa-shield-halved me-1"></i> Permissions
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>employee" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Directory
            </a>
        </div>
    </header>

    <?php if ($isLocked): ?>
    <div class="hub-alert-strip">
        <span class="hub-alert-chip warn">
            <i class="fas fa-lock"></i>
            <?= htmlspecialchars($lockMessage ?? 'Account locked.', ENT_QUOTES) ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-file-invoice"></i></div>
            <div>
                <div class="stat-value"><?= (int)($usage['sales_invoices'] ?? 0) ?></div>
                <div class="stat-label">Sales invoices</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value"><?= (int)($usage['customers'] ?? 0) ?></div>
                <div class="stat-label">Linked customers</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-eye"></i></div>
            <div>
                <div class="stat-value"><?= $viewCount ?></div>
                <div class="stat-label">Menu view grants</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-pen"></i></div>
            <div>
                <div class="stat-value"><?= $editCount ?></div>
                <div class="stat-label">Menu edit grants</div>
            </div>
        </div>
    </div>

    <nav class="branch-hub-quick">
        <a href="<?= BASE_URL ?>employee"><i class="fas fa-id-badge"></i> Workforce</a>
        <a href="<?= BASE_URL ?>user"><i class="fas fa-users-cog"></i> System users</a>
        <?php if ($hasUser): ?>
        <a href="<?= BASE_URL ?>user/edit/<?= $userId ?>"><i class="fas fa-user-pen"></i> Edit login</a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>user/create?employee_id=<?= $employeeId ?>"><i class="fas fa-user-plus"></i> Create login</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>user/security_audit"><i class="fas fa-shield-halved"></i> Security audit</a>
    </nav>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="branch-hub-panel h-100 hub-contact-card emp-account-profile">
                <div class="hub-panel-body">
                    <div class="emp-account-profile-head">
                        <?php if ($photoPath): ?>
                            <img src="<?= htmlspecialchars($photoPath, ENT_QUOTES) ?>" alt="" class="emp-account-photo">
                        <?php else: ?>
                            <div class="emp-account-photo emp-account-photo-fallback">
                                <?= htmlspecialchars($initials($employee['name'] ?? ''), ENT_QUOTES) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h2><?= htmlspecialchars($employee['name'] ?? '', ENT_QUOTES) ?></h2>
                            <span class="branch-code-pill"><?= htmlspecialchars($employee['employee_code'] ?? '', ENT_QUOTES) ?></span>
                            <span class="employee-role-pill mt-2"><?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></span>
                        </div>
                    </div>

                    <div class="branch-form-section-head mb-3 mt-2">
                        <span class="icon-wrap indigo"><i class="fas fa-address-card"></i></span>
                        Contact &amp; placement
                    </div>

                    <?php if (!empty($employee['branch_name'])): ?>
                    <div class="branch-contact">
                        <i class="fas fa-sitemap"></i>
                        <?= htmlspecialchars($employee['branch_name'], ENT_QUOTES) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['mobile'])): ?>
                    <div class="branch-contact">
                        <i class="fas fa-phone"></i>
                        <?= htmlspecialchars($employee['mobile'], ENT_QUOTES) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['email'])): ?>
                    <div class="branch-contact">
                        <i class="fas fa-envelope"></i>
                        <?= htmlspecialchars($employee['email'], ENT_QUOTES) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['designation']) || !empty($employee['department'])): ?>
                    <div class="branch-contact">
                        <i class="fas fa-briefcase"></i>
                        <?php
                        $jobLine = trim($employee['designation'] ?? '');
                        if (!empty($employee['department'])) {
                            $jobLine = $jobLine !== ''
                                ? $jobLine . ' · ' . $employee['department']
                                : (string)$employee['department'];
                        }
                        echo htmlspecialchars($jobLine, ENT_QUOTES);
                        ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($employee['joining_date'])): ?>
                    <div class="branch-contact">
                        <i class="fas fa-calendar-day"></i>
                        Joined <?= htmlspecialchars(date('M j, Y', strtotime((string)$employee['joining_date'])), ENT_QUOTES) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="branch-hub-panel mb-3">
                <div class="hub-panel-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div class="branch-form-section-head mb-0">
                            <span class="icon-wrap teal"><i class="fas fa-user-shield"></i></span>
                            System login
                        </div>
                        <?php if ($hasUser): ?>
                        <span class="employee-user-pill <?= $loginActive ? 'active' : 'inactive' ?>">
                            <i class="fas fa-<?= $loginActive ? 'check' : 'minus' ?>"></i>
                            <?= $loginActive ? 'Active account' : 'Inactive account' ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$hasUser): ?>
                    <div class="hub-empty-state py-4">
                        <i class="fas fa-user-plus d-block"></i>
                        <p class="mb-3">This employee does not have a system user account yet.</p>
                        <a href="<?= BASE_URL ?>user/create?employee_id=<?= $employeeId ?>" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i> Create user account
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="emp-account-login-summary mb-4">
                        <div class="emp-account-login-main">
                            <div class="small text-muted mb-1">Username</div>
                            <div class="emp-account-username">
                                <span class="branch-code-pill"><?= htmlspecialchars($account['username'] ?? '', ENT_QUOTES) ?></span>
                            </div>
                        </div>
                        <div class="emp-account-login-meta">
                            <div class="emp-account-meta-item">
                                <span class="label">2FA</span>
                                <span class="value <?= $twoFaEnabled ? 'text-success' : 'text-muted' ?>">
                                    <?= $twoFaEnabled ? 'Enabled' : 'Off' ?>
                                </span>
                            </div>
                            <div class="emp-account-meta-item">
                                <span class="label">Last login</span>
                                <span class="value"><?= $lastLoginLabel ?></span>
                            </div>
                            <?php if (!empty($account['last_login_ip'])): ?>
                            <div class="emp-account-meta-item">
                                <span class="label">Last IP</span>
                                <span class="value"><?= htmlspecialchars($account['last_login_ip'], ENT_QUOTES) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="branch-form-section-head mb-3">
                        <span class="icon-wrap slate"><i class="fas fa-bolt"></i></span>
                        Quick actions
                    </div>
                    <div class="hub-action-grid">
                        <a href="<?= BASE_URL ?>user/edit/<?= $userId ?>" class="hub-action-tile">
                            <span class="hub-action-icon indigo"><i class="fas fa-pen"></i></span>
                            <span class="hub-action-text">
                                <strong>Edit login</strong>
                                <small>Username &amp; password</small>
                            </span>
                        </a>
                        <a href="<?= BASE_URL ?>user/permission/<?= $userId ?>" class="hub-action-tile">
                            <span class="hub-action-icon teal"><i class="fas fa-shield-halved"></i></span>
                            <span class="hub-action-text">
                                <strong>Permissions</strong>
                                <small>Menu access grants</small>
                            </span>
                        </a>
                        <form method="POST" action="<?= BASE_URL ?>user/generate_reset_link/<?= $userId ?>" class="hub-action-tile-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <button type="submit" class="hub-action-tile" onclick="return confirm('Generate a password reset link for this user?')">
                                <span class="hub-action-icon amber"><i class="fas fa-link"></i></span>
                                <span class="hub-action-text">
                                    <strong>Reset link</strong>
                                    <small>One-time password reset</small>
                                </span>
                            </button>
                        </form>
                        <?php if ($isLocked): ?>
                        <form method="POST" action="<?= BASE_URL ?>user/unlock/<?= $userId ?>" class="hub-action-tile-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <button type="submit" class="hub-action-tile warn">
                                <span class="hub-action-icon warn"><i class="fas fa-unlock"></i></span>
                                <span class="hub-action-text">
                                    <strong>Unlock</strong>
                                    <small>Clear lockout</small>
                                </span>
                            </button>
                        </form>
                        <?php endif; ?>
                        <!-- Phase 0: Disable 2FA admin form removed (2FA disabled). -->
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($hasUser): ?>
            <div class="branch-hub-panel">
                <div class="hub-panel-body">
                    <div class="branch-form-section-head mb-3">
                        <span class="icon-wrap amber"><i class="fas fa-sitemap"></i></span>
                        Effective access
                    </div>
                    <p class="small text-muted mb-3">
                        Role is set on the employee record (<strong><?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></strong>).
                        Menu permissions control module visibility and write access for operational users.
                    </p>

                    <div class="hub-breakdown-grid">
                        <div class="hub-breakdown-card">
                            <div class="hub-breakdown-card-head">
                                <h3><i class="fas fa-eye me-1"></i> View access</h3>
                                <span class="hub-breakdown-count"><?= $viewCount ?></span>
                            </div>
                            <div class="hub-breakdown-row">
                                <div class="hub-breakdown-label">Menus with view</div>
                                <div class="hub-breakdown-qty"><?= $viewCount ?></div>
                                <div class="hub-breakdown-meta">
                                    <div class="hub-breakdown-bar"><span style="width: <?= $viewPct ?>%"></span></div>
                                    <span class="hub-breakdown-skus"><?= $viewPct ?>% of grants</span>
                                </div>
                            </div>
                        </div>
                        <div class="hub-breakdown-card">
                            <div class="hub-breakdown-card-head">
                                <h3><i class="fas fa-pen me-1"></i> Edit access</h3>
                                <span class="hub-breakdown-count"><?= $editCount ?></span>
                            </div>
                            <div class="hub-breakdown-row">
                                <div class="hub-breakdown-label">Menus with edit</div>
                                <div class="hub-breakdown-qty"><?= $editCount ?></div>
                                <div class="hub-breakdown-meta">
                                    <div class="hub-breakdown-bar group"><span style="width: <?= $editPct ?>%"></span></div>
                                    <span class="hub-breakdown-skus"><?= $editPct ?>% of grants</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <span class="branch-audit-chip"><i class="fas fa-list"></i> <?= $menuCount ?> total grants</span>
                        <span class="branch-audit-chip updated"><i class="fas fa-user-tag"></i> <?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
