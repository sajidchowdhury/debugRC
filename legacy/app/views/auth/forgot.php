<?php
// app/views/auth/forgot.php
$title = $title ?? 'Forgot Password';
$error = $error ?? null;
$username = $username ?? '';
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
                            <h2 class="fw-bold text-primary">Forgot password</h2>
                            <p class="text-muted small mb-0">Enter your username. If the account has an employee email on file, a reset link will be sent.</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger text-center"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
                        <?php endif; ?>

                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

                            <div class="mb-4">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control form-control-lg" required autofocus
                                       value="<?= htmlspecialchars($username, ENT_QUOTES) ?>" autocomplete="username">
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-envelope me-2"></i> Send reset link
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
