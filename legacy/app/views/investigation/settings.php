<?php
ob_start();
$title = $title ?? 'Investigation mode setup';
$activators = $activators ?? [];
$users = $users ?? [];
$scanUrl = $scanUrl ?? '';
$companyEmail = $companyEmail ?? '';
$qrConfigured = !empty($qrConfigured);
$qrDataUri = $qrDataUri ?? '';
$isActive = !empty($isActive);
$investigation_period = $investigation_period ?? null;
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="branch-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-qrcode me-2"></i>Investigation mode</h1>
            <p>Configure QR activation, trained staff, and company email for deactivation OTP.</p>
            <?php if ($isActive): ?>
                <span class="hero-badge"><i class="fas fa-circle-exclamation"></i> Currently ON</span>
            <?php endif; ?>
        </div>
    </header>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">QR code for trained staff</div>
                <div class="card-body text-center">
                    <?php if (!$qrConfigured): ?>
                        <div class="alert alert-warning text-start small">
                            Set <code>INVESTIGATION_QR_SECRET</code> in <code>config/local.php</code>, then reload this page.
                        </div>
                    <?php else: ?>
                        <?php if ($qrDataUri): ?>
                            <img src="<?= htmlspecialchars($qrDataUri, ENT_QUOTES) ?>" alt="Investigation QR" width="240" height="240" class="border rounded mb-3">
                        <?php endif; ?>
                        <p class="small text-muted">Print and keep at reception or manager desk. Only pre-registered staff can use it after logging in.</p>
                        <code class="d-block small text-break user-select-all p-2 bg-light rounded"><?= htmlspecialchars($scanUrl, ENT_QUOTES) ?></code>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">Company email (deactivation OTP)</div>
                <div class="card-body">
                    <?php if ($companyEmail === ''): ?>
                        <div class="alert alert-danger mb-0 small">
                            Not configured. Add to <code>config/local.php</code>:<br>
                            <code>define('INVESTIGATION_COMPANY_EMAIL', 'compliance@yourcompany.com');</code>
                        </div>
                    <?php else: ?>
                        <p class="mb-0">Deactivation codes are sent to <strong><?= htmlspecialchars($companyEmail, ENT_QUOTES) ?></strong></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">Authorized activators</div>
                <div class="card-body">
                    <form method="POST" action="<?= BASE_URL ?>investigation/save_activator" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                        <div class="col-md-6">
                            <label class="form-label">Staff user</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">Select user…</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>">
                                        <?= htmlspecialchars(($u['employee_name'] ?? '') . ' (' . ($u['username'] ?? '') . ')', ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Label</label>
                            <input type="text" name="label" class="form-control" placeholder="e.g. Managing Director">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Add</button>
                        </div>
                    </form>

                    <?php if ($activators === []): ?>
                        <p class="text-muted small mb-0">No activators yet. Add the owner, director, or trusted HR staff who will scan the QR.</p>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Staff</th><th>Label</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($activators as $a): ?>
                                <tr>
                                    <td><?= htmlspecialchars($a['employee_name'] ?? $a['username'] ?? '', ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($a['label'] ?? '—', ENT_QUOTES) ?></td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>investigation/remove_activator/<?= (int)$a['id'] ?>"
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Remove this activator?')">Remove</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Normal vs investigation mode</div>
                <div class="card-body small">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="fw-semibold">Normal</h6>
                            <ul class="mb-0 ps-3 text-muted">
                                <li>Reports: any date range</li>
                                <li>Sales, users, settings: full role access</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-semibold text-warning">Investigation ON</h6>
                            <ul class="mb-0 ps-3 text-muted">
                                <li>Reports: Jul–Jun fiscal year only (1 year)</li>
                                <li>Everything else: unchanged</li>
                            </ul>
                            <?php if (!empty($investigation_period)): ?>
                                <p class="mt-2 mb-0"><strong>Current window:</strong> <?= htmlspecialchars($investigation_period['label'], ENT_QUOTES) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header fw-semibold">Workflow</div>
                <div class="card-body small text-muted">
                    <ol class="mb-0 ps-3">
                        <li>Employee logs in, scans QR — investigation mode turns <strong>ON</strong> automatically.</li>
                        <li>Everyone keeps normal access; only <strong>report dates</strong> are capped to Jul–Jun.</li>
                        <li>Employee scans QR again — enter OTP — mode <strong>OFF</strong>.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
