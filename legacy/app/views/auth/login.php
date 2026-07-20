<?php
// app/views/auth/login.php
$title = $title ?? 'Login';
$error = $error ?? null;
$rememberDays = defined('AUTH_REMEMBER_DAYS') ? (int)AUTH_REMEMBER_DAYS : 30;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - <?= $title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="d-flex align-items-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5 col-lg-4">
                <div class="card login-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h2 class="fw-bold text-primary">Remote Center</h2>
                            <p class="text-muted">Enterprise Resource Planning</p>
                        </div>

                        <?php 
                        // Support both direct $error and Flash messages
                        $flash = null;
                        if (class_exists('Flash') && Flash::has()) {
                            $flash = Flash::get();
                        }
                        $displayError = $error ?? ($flash['message'] ?? null);
                        $displayType  = $flash['type'] ?? 'error';
                        ?>
                        <?php if ($displayError): ?>
                            <div class="alert alert-<?= $displayType === 'success' ? 'success' : 'danger' ?> text-center">
                                <?= htmlspecialchars($displayError) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" autocomplete="off">
                            <!-- CSRF Protection -->
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" 
                                       name="username" 
                                       class="form-control form-control-lg" 
                                       required 
                                       autofocus
                                       autocomplete="username">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" 
                                       name="password" 
                                       class="form-control form-control-lg" 
                                       required
                                       autocomplete="current-password">
                            </div>

                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" name="remember_me" value="1" id="rememberMe">
                                <label class="form-check-label" for="rememberMe">Remember me for <?= (int)$rememberDays ?> days</label>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-sign-in-alt me-2"></i> Login
                            </button>
                        </form>

                        <div class="text-center mt-3">
                            <a href="<?= BASE_URL ?>auth/forgot" class="text-decoration-none small">Forgot password?</a>
                        </div>

                        <div class="text-center mt-4 text-muted small">
                           
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>