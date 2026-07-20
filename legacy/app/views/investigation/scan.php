<?php
ob_start();
$title = $title ?? 'Investigation mode';
$mode = $mode ?? 'deactivate';
$scanToken = $scanToken ?? '';
$companyEmail = $companyEmail ?? '';
$otpSent = !empty($otpSent);
$otpError = $otpError ?? null;
$devOtp = $devOtp ?? null;
$activeWindow = $activeWindow ?? null;
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="branch-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <?php if ($mode === 'activated'): ?>
                <h1><i class="fas fa-check-circle me-2"></i>Investigation mode is ON</h1>
                <p>Reports are limited to the current Jul–Jun year. Everything else works as normal.</p>
            <?php elseif ($mode === 'error'): ?>
                <h1><i class="fas fa-exclamation-triangle me-2"></i>Could not activate</h1>
                <p><?= htmlspecialchars($errorMessage ?? 'Investigation mode could not be turned on.', ENT_QUOTES) ?></p>
            <?php else: ?>
                <h1><i class="fas fa-unlock me-2"></i>Restore normal access</h1>
                <p>Enter the 6-digit code sent to the company email to turn investigation mode off.</p>
            <?php endif; ?>
        </div>
    </header>

    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <?php if ($mode === 'activated'): ?>
                <div class="alert alert-success mb-0">
                    <i class="fas fa-shield-halved me-1"></i>
                    All users (including superadmin) see report data for the current fiscal year only.
                </div>
                <div class="text-center mt-4">
                    <a href="<?= BASE_URL ?>dashboard" class="btn btn-primary">Go to dashboard</a>
                </div>

            <?php elseif ($mode === 'error'): ?>
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-circle-exclamation me-1"></i>
                    <?= htmlspecialchars($errorMessage ?? 'Please try again or contact an administrator.', ENT_QUOTES) ?>
                </div>
                <div class="text-center mt-4">
                    <a href="<?= BASE_URL ?>dashboard" class="btn btn-secondary">Go to dashboard</a>
                </div>

            <?php else: ?>
                <?php if ($otpError): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($otpError, ENT_QUOTES) ?></div>
                <?php elseif (!empty($devOtp)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-key me-1"></i>
                        Email is not configured on this server. Your deactivation code:
                        <strong class="fs-4 d-block mt-2"><?= htmlspecialchars($devOtp, ENT_QUOTES) ?></strong>
                    </div>
                <?php elseif ($otpSent && $companyEmail !== ''): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-envelope me-1"></i>
                        A code was sent to <strong><?= htmlspecialchars($companyEmail, ENT_QUOTES) ?></strong>.
                        Check the inbox and enter it below.
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST" action="<?= BASE_URL ?>investigation/scan?t=<?= urlencode($scanToken) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                            <label class="form-label">6-digit code</label>
                            <input type="text" name="otp" class="form-control form-control-lg text-center mb-3"
                                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
                                   autocomplete="one-time-code" placeholder="000000">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-unlock me-1"></i> Turn off investigation mode
                            </button>
                        </form>
                        <?php if ($activeWindow): ?>
                            <p class="small text-muted mt-3 mb-0">
                                Active since <?= htmlspecialchars($activeWindow['started_at'] ?? '', ENT_QUOTES) ?>.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
