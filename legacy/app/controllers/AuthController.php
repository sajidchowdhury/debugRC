<?php
// app/controllers/AuthController.php
// Secure authentication controller

require_once '../core/BaseController.php';
require_once '../core/Auth.php';
require_once '../core/RateLimiter.php';
require_once '../core/LoginAudit.php';
require_once '../core/Database.php';
require_once '../core/AccountLockout.php';
require_once '../core/PasswordReset.php';
require_once '../core/RememberMe.php';
// Phase 0: TOTP 2FA + PendingLogin removed per migration decision (login is username+password only).

class AuthController extends BaseController {

    public function login() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();

            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $rememberMe = !empty($_POST['remember_me']);

            if (empty($username) || empty($password)) {
                $this->showLoginForm('Username and password are required.');
                return;
            }

            $rateLimiter = new RateLimiter(5, 900);
            if ($rateLimiter->isLimited($username)) {
                $audit = new LoginAudit();
                $audit->logFailure($username, true, 'rate_limited');
                $this->showLoginForm('Too many failed login attempts. Please try again in a few minutes.');
                return;
            }

            $db = new Database();
            $db->query("
                SELECT 
                    u.id, u.username, u.employee_id, u.password_hash,
                    u.failed_login_count, u.locked_until, u.updated_at,
                    e.role, e.branch_id, e.name as employee_name, 
                    b.branch_name, e.photo
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                JOIN branches b ON e.branch_id = b.id
                WHERE u.username = :username 
                  AND u.is_active = 1
                  AND u.deleted_at IS NULL
                LIMIT 1
            ");
            $db->bind(':username', $username);
            $user = $db->single();

            $audit = new LoginAudit();

            if ($user && AccountLockout::isLocked($user)) {
                $audit->logFailure($username, false, 'account_locked');
                $this->showLoginForm(AccountLockout::lockMessage($user) ?? 'This account is temporarily locked.');
                return;
            }

            if ($user && password_verify($password, $user['password_hash'])) {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $db->query('UPDATE users SET password_hash = :hash WHERE id = :id');
                    $db->bind(':hash', $newHash);
                    $db->bind(':id', (int)$user['id']);
                    $db->execute();
                    require_once '../core/CredentialVersion.php';
                    CredentialVersion::bump((int)$user['id']);
                }

                AccountLockout::clear((int)$user['id']);
                $rateLimiter->clear($username);

                $this->completeLogin($user, $rememberMe, $username);
            } else {
                if ($user) {
                    AccountLockout::recordFailure((int)$user['id']);
                }
                $rateLimiter->recordFailure($username);
                $audit->logFailure($username, false, 'invalid_credentials');
                $this->showLoginForm('Invalid username or password.');
            }
        } else {
            $this->showLoginForm();
        }
    }

    public function forgot() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();

            $username = trim($_POST['username'] ?? '');
            $genericMessage = 'If an account exists for that username, reset instructions have been sent when possible.';

            if ($username === '') {
                $this->showForgotForm('Username is required.', $username);
                return;
            }

            $rateLimiter = new RateLimiter(3, 900, 'forgot:');
            if ($rateLimiter->isLimited($username)) {
                $this->showForgotForm('Too many reset requests. Please try again later.', $username);
                return;
            }

            $db = new Database();
            $db->query('
                SELECT u.id
                FROM users u
                WHERE u.username = :username
                  AND u.is_active = 1
                  AND u.deleted_at IS NULL
                LIMIT 1
            ');
            $db->bind(':username', $username);
            $user = $db->single();

            if ($user) {
                $tokenData = PasswordReset::createToken((int)$user['id']);
                if ($tokenData !== null) {
                    PasswordReset::sendResetEmail((int)$user['id'], $tokenData[0]);
                }
            }

            $rateLimiter->recordFailure($username);

            Flash::set($genericMessage, 'success');
            $this->redirect('auth/login');
            return;
        }

        $this->showForgotForm();
    }

    public function reset($token = null) {
        $token = trim((string)($token ?? $_POST['token'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();

            $newPassword = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $result = PasswordReset::resetPassword($token, $newPassword, $confirm);

            if ($result['status'] === 'success') {
                Flash::set($result['message'], 'success');
                $this->redirect('auth/login');
                return;
            }

            $this->showResetForm($token, $result['message']);
            return;
        }

        if ($token === '' || PasswordReset::validateToken($token) === null) {
            Flash::set('This reset link is invalid or has expired.', 'error');
            $this->redirect('auth/forgot');
            return;
        }

        $this->showResetForm($token);
    }

    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flash::set('Please use the Logout button to sign out.', 'error');
            $this->redirect(Auth::isLoggedIn() ? 'dashboard' : 'auth/login');
            return;
        }

        $this->validateCSRF();
        Auth::logout();
        $this->redirect('auth/login');
    }

    /**
     * @param array<string, mixed> $user
     */
    private function completeLogin(array $user, bool $rememberMe, string $usernameForAudit): void
    {
        Auth::login($user);

        if ($rememberMe) {
            RememberMe::create((int)$user['id']);
        }

        $audit = new LoginAudit();
        $audit->logSuccess($usernameForAudit);

        $this->redirectAfterLogin();
    }

    private function redirectAfterLogin(): void
    {
        $target = $_SESSION['post_login_redirect'] ?? 'dashboard';
        unset($_SESSION['post_login_redirect']);

        if (!$this->isSafeInternalRedirect($target)) {
            $target = 'dashboard';
        }

        $this->redirect($target);
    }

    private function isSafeInternalRedirect(string $path): bool
    {
        if ($path === '' || preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
            return false;
        }

        return (bool)preg_match('#^[a-zA-Z0-9_/\.?=&%-]+$#', $path);
    }

    private function showLoginForm($error = null) {
        $this->view('auth/login', [
            'title' => 'Login - Remote Center ERP',
            'error' => $error,
        ]);
    }

    private function showForgotForm(?string $error = null, string $username = '') {
        $this->view('auth/forgot', [
            'title'    => 'Forgot Password',
            'error'    => $error,
            'username' => $username,
        ]);
    }

    private function showResetForm(string $token, ?string $error = null) {
        $this->view('auth/reset', [
            'title' => 'Reset Password',
            'token' => $token,
            'error' => $error,
        ]);
    }
}
?>
