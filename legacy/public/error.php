<?php
// public/error.php
// Creative, standalone error page for 404 / 403 / etc.

$code = $code ?? ($_GET['code'] ?? 404);
$message = $message ?? ($_GET['message'] ?? '');

$title = match($code) {
    403 => 'Access Forbidden',
    404 => 'Page Not Found',
    500 => 'Server Error',
    default => 'Something Went Wrong'
};

$icon = match($code) {
    403 => 'fa-lock',
    404 => 'fa-search',
    500 => 'fa-exclamation-triangle',
    default => 'fa-exclamation-circle'
};

$description = match($code) {
    403 => 'You don\'t have permission to access this area. This section is restricted.',
    404 => 'The page you\'re looking for doesn\'t exist or has been moved.',
    500 => 'Something unexpected happened on our end. Please try again later.',
    default => 'An unknown error occurred.'
};

if ($message) {
    $description = htmlspecialchars($message);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $code ?> | Remote Center ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: #fff;
            overflow: hidden;
        }

        .error-container {
            text-align: center;
            max-width: 520px;
            padding: 2rem;
        }

        .error-code {
            font-size: 9rem;
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(90deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 40px rgba(102, 126, 234, 0.3);
            margin-bottom: 0.5rem;
        }

        .error-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .error-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #e0e0e0;
        }

        .error-description {
            font-size: 1.05rem;
            color: #a0a0b0;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .btn-custom {
            padding: 12px 28px;
            font-weight: 500;
            border-radius: 50px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary-custom {
            background: linear-gradient(90deg, #667eea, #764ba2);
            color: white;
            border: none;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-outline-custom {
            border: 2px solid #4a4a6a;
            color: #c0c0d0;
        }

        .btn-outline-custom:hover {
            background: #2a2a4a;
            color: #fff;
            border-color: #667eea;
        }

        .glitch {
            animation: glitch 1.5s infinite;
        }

        @keyframes glitch {
            0% { transform: translate(0); }
            20% { transform: translate(-2px, 2px); }
            40% { transform: translate(-2px, -2px); }
            60% { transform: translate(2px, 2px); }
            80% { transform: translate(2px, -2px); }
            100% { transform: translate(0); }
        }

        .floating {
            animation: float 3s ease-in-out infinite;
        }

        .error-details {
            font-size: 0.85rem;
            color: #555;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <!-- Big Error Code -->
        <div class="error-code glitch"><?= $code ?></div>

        <!-- Icon -->
        <div class="error-icon floating">
            <i class="fas <?= $icon ?>"></i>
        </div>

        <!-- Title -->
        <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>

        <!-- Description -->
        <p class="error-description"><?= htmlspecialchars($description) ?></p>

        <!-- Action Buttons -->
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="auth/login" class="btn btn-custom btn-primary-custom">
                <i class="fas fa-sign-in-alt"></i>
                Go to Login
            </a>
            <a href="javascript:history.back()" class="btn btn-custom btn-outline-custom">
                <i class="fas fa-arrow-left"></i>
                Go Back
            </a>
        </div>

        <div class="error-details">
            Remote Center ERP • If you believe this is an error, please contact your administrator.
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Optional: Log the error
if (in_array($code, [403, 404])) {
    error_log("Error $code: " . $_SERVER['REQUEST_URI'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
?>