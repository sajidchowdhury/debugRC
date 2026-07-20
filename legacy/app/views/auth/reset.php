<?php
// app/views/auth/reset.php
require_once __DIR__ . '/../../../core/PasswordPolicy.php';

$title = $title ?? 'Reset Password';
$error = $error ?? null;
$token = $token ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - <?= htmlspecialchars($title, ENT_QUOTES) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .login-card { border: none; border-radius: 15px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="d-flex align-items-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5 col-lg-4">
                <div class="card login-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h2 class="fw-bold text-primary">Reset password</h2>
                            <p class="text-muted small mb-0">Choose a new password for your account.</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger text-center"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
                        <?php endif; ?>

                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

                            <div class="mb-3">
                                <label class="form-label">New password</label>
                                <input type="password" name="password" class="form-control form-control-lg" required autocomplete="new-password">
                                <div class="form-text"><?= htmlspecialchars(PasswordPolicy::requirementsText(), ENT_QUOTES) ?></div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Confirm password</label>
                                <input type="password" name="confirm_password" class="form-control form-control-lg" required autocomplete="new-password">
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-key me-2"></i> Update password
                            </button>
                        </form>

                        <div class="text-center mt-4">
                            <a href="<?= BASE_URL ?>auth/login" class="text-decoration-none">
                                <i class="fas fa-arrow-left me-1"></i> Back to login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
