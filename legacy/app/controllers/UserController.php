<?php
// app/controllers/UserController.php

require_once '../core/BaseController.php';
require_once '../app/models/UserModel.php';
require_once '../app/models/EmployeeModel.php';
require_once '../app/models/BranchModel.php';
require_once '../core/UserAudit.php';

class UserController extends BaseController {

    private $userModel;
    private $employeeModel;
    private $branchModel;

    public function __construct() {
        $this->requireLogin();
        // NOTE: account-management actions are gated to admin tier individually
        // (see requireAdmin() calls below). change_password/update_password stay
        // available to every logged-in user for their own password.
        $this->userModel = new UserModel();
        $this->employeeModel = new EmployeeModel();
        $this->branchModel = new BranchModel();
    }

    // List all users
    public function index() {
        $this->requireAdmin();
        // Handle DataTables server-side AJAX request
        if (isset($_GET['draw'])) {
            $params = $_GET;
            $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';
            $params['includeDeleted'] = $showDeleted;
            $params['deletedOnly'] = $showDeleted;

            try {
                $response = $this->userModel->getUsersForDataTable($params);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($response);
            } catch (Exception $e) {
                // Always return valid JSON even on error so DataTables doesn't break
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode([
                    'draw' => (int)($params['draw'] ?? 1),
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => [],
                    'error' => 'Server error: ' . $e->getMessage()
                ]);
            }
            exit;
        }

        // Normal page load - load filter data
        $branches = $this->branchModel->getAllActiveBranches();
        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';

        $data = [
            'title'       => $showDeleted ? 'Deleted users' : 'System users',
            'branches'    => $branches,
            'stats'       => $this->userModel->getUserIndexStats(),
            'showDeleted' => $showDeleted,
        ];
        $this->view('user/index', $data);
    }

    // Show create user form
    public function create() {
        $this->requireAdmin();
        $employee_id = $_GET['employee_id'] ?? null;
        $preSelectedEmployee = null;

        if ($employee_id) {
            $preSelectedEmployee = $this->employeeModel->getEmployeeById($employee_id);
        }

        $employees = $this->employeeModel->getEmployeesWithoutUserAccount();

        $data = [
            'title' => 'Create New User',
            'employees' => $employees,
            'preSelectedEmployee' => $preSelectedEmployee
        ];
        $this->view('user/create', $data);
    }

    // Save new user
    public function store() {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $result = $this->userModel->createUser($_POST);

            if ($result['status'] === 'success') {
                $audit = new UserAudit();
                $audit->logUserCreated(
                    (int)($_SESSION['user_id'] ?? 0),
                    (int)($result['user_id'] ?? 0),
                    (string)($_POST['username'] ?? 'unknown')
                );

                $_SESSION['success'] = $result['message'];
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                if ($employeeId > 0) {
                    $this->redirect('employee/account/' . $employeeId);
                    return;
                }
                $this->redirect('user/index');
            } else {
                $_SESSION['error'] = $result['message'];
                $redirect = 'user/create';
                if (!empty($_POST['employee_id'])) {
                    $redirect .= '?employee_id=' . $_POST['employee_id'];
                }
                $this->redirect($redirect);
            }
        }
    }
    // Show edit user form
    public function edit($id = null) {
        $this->requireAdmin();
        if (!$id) $this->redirect('user/index');

        $user = $this->userModel->getUserById($id);
        if (!$user) {
            $_SESSION['error'] = "User not found!";
            $this->redirect('user/index');
        }

        $data = [
            'title'                  => 'Edit User',
            'user'                   => $user,
            'telegram_column_ready'  => $this->userModel->usersColumnExists('telegram_user_id'),
        ];
        $this->view('user/edit', $data);
    }

    // Update user
    public function update($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();
            $result = $this->userModel->updateUser($id, $_POST);

            if ($result['status'] === 'success') {
                $audit = new UserAudit();
                $audit->logUserUpdated((int)($_SESSION['user_id'] ?? 0), (int)$id, [
                    'username'         => trim((string)($_POST['username'] ?? '')),
                    'is_active'        => (int)($_POST['is_active'] ?? 1),
                    'password_changed' => !empty($_POST['password']),
                ]);

                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
        }
        $this->redirect('user/index');
    }

    // Toggle user active/inactive
    public function toggle($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('user/index');
            return;
        }

        $this->validateCSRF();

        if ($this->userModel->toggleStatus($id)) {
            $audit = new UserAudit();
            $audit->logUserStatusChanged($_SESSION['user_id'] ?? 0, (int)$id, true);
            $this->finishUserMutation(true, 'User status updated successfully!', 'user/index');
            return;
        }

        $this->finishUserMutation(
            false,
            'Cannot deactivate this user. This is the last active user in the system.',
            'user/index'
        );
    }

    // Soft delete user (safer than hard delete)
    public function delete($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('user/index');
            return;
        }

        $this->validateCSRF();

        if ($this->userModel->softDeleteUser((int)$id, $_SESSION['user_id'] ?? 0)) {
            $audit = new UserAudit();
            $audit->log($_SESSION['user_id'] ?? 0, 'user_soft_deleted', (int)$id);
            $this->finishUserMutation(true, 'User has been deleted (soft delete).', 'user/index');
            return;
        }

        $this->finishUserMutation(false, 'Cannot delete this user. It may be the last active user.', 'user/index');
    }

    // Restore soft-deleted user
    public function restore($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('user/index?deleted=1');
            return;
        }

        $this->validateCSRF();

        $result = $this->userModel->restoreUser((int)$id);

        if ($result['status'] === 'success') {
            $audit = new UserAudit();
            $audit->log($_SESSION['user_id'] ?? 0, 'user_restored', (int)$id);
            $this->finishUserMutation(true, $result['message'], 'user/index?deleted=1');
            return;
        }

        $this->finishUserMutation(false, $result['message'], 'user/index?deleted=1');
    }

    private function finishUserMutation(bool $success, string $message, string $redirectPath): void
    {
        if ($this->isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => $success ? 'success' : 'error',
                'message' => $message,
            ]);
            exit;
        }

        if ($success) {
            $_SESSION['success'] = $message;
        } else {
            $_SESSION['error'] = $message;
        }

        $this->redirect($redirectPath);
    }

    // Clear persisted account lockout
    public function unlock($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('user/index');
            return;
        }

        $this->validateCSRF();
        $result = $this->userModel->unlockUser((int)$id);

        if ($result['status'] === 'success') {
            $audit = new UserAudit();
            $audit->log($_SESSION['user_id'] ?? 0, 'user_unlocked', (int)$id);
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }

        $this->redirect('user/edit/' . (int)$id);
    }

    // Admin-generated one-time password reset link
    public function generate_reset_link($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('user/index');
            return;
        }

        $this->validateCSRF();

        $user = $this->userModel->getUserById((int)$id);
        if (!$user || !empty($user['deleted_at'])) {
            $_SESSION['error'] = 'User not found.';
            $this->redirect('user/index');
            return;
        }

        require_once '../core/PasswordReset.php';
        $tokenData = PasswordReset::createToken((int)$id);

        if ($tokenData === null) {
            $_SESSION['error'] = 'Failed to generate reset link.';
            $this->redirect('user/edit/' . (int)$id);
            return;
        }

        $resetUrl = BASE_URL . 'auth/reset/' . urlencode($tokenData[0]);
        PasswordReset::sendResetEmail((int)$id, $tokenData[0]);

        require_once '../core/RememberMe.php';
        RememberMe::revokeAllForUser((int)$id);

        $audit = new UserAudit();
        $audit->log($_SESSION['user_id'] ?? 0, 'user_reset_link_generated', (int)$id);

        $_SESSION['success'] = 'Reset link generated (valid until ' . $tokenData[1] . '): ' . $resetUrl;
        $this->redirect('user/edit/' . (int)$id);
    }


    // Show Menu Permission Page
    public function permission($user_id = null) {
        $this->requireAdmin();
        if (!$user_id) $this->redirect('user/index');

        $user = $this->userModel->getUserById($user_id);
        if (!$user) {
            $_SESSION['error'] = "User not found!";
            $this->redirect('user/index');
        }

        // Get all menus
        require_once '../app/models/MenuModel.php';
        $menuModel = new MenuModel();
        $menus = $menuModel->getAllMenusHierarchical();

        // Get current permissions for this user
        $currentPermissions = $this->userModel->getUserPermissions($user_id);
        $copyCandidates = $this->userModel->getUsersForPermissionCopy((int)$user_id);

        $viewCount = 0;
        $editCount = 0;
        foreach ($currentPermissions as $perm) {
            if (!empty($perm['view'])) {
                $viewCount++;
            }
            if (!empty($perm['edit'])) {
                $editCount++;
            }
        }

        $data = [
            'title' => 'Menu Permissions - ' . $user['username'],
            'user' => $user,
            'menus' => $menus,
            'currentPermissions' => $currentPermissions,
            'copyCandidates' => $copyCandidates,
            'permissionStats' => [
                'view_count'  => $viewCount,
                'edit_count'  => $editCount,
                'menu_count'  => count($currentPermissions),
            ],
        ];

        $this->view('user/permission', $data);
    }

    /**
     * JSON: permission map for copy-from-user (admin only).
     */
    public function permission_json($user_id = null) {
        $this->requireAdmin();

        $userId = (int)$user_id;
        if ($userId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Invalid user id.']);
            exit;
        }

        $user = $this->userModel->getUserById($userId);
        if (!$user) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'User not found.']);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'        => 'success',
            'user_id'       => $userId,
            'username'      => (string)($user['username'] ?? ''),
            'employee_name' => (string)($user['employee_name'] ?? ''),
            'permissions'   => $this->userModel->getUserPermissions($userId),
        ]);
        exit;
    }

    // Save Menu Permissions
    public function save_permissions() {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $user_id = (int)$_POST['user_id'];

            // Use the model to handle the update safely
            $result = $this->userModel->saveUserPermissions($user_id, $_POST['permissions'] ?? []);

            if ($result['status'] === 'success') {
                $audit = new UserAudit();
                $audit->logPermissionsUpdated($_SESSION['user_id'] ?? 0, $user_id);

                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }

            $this->redirect('user/permission/' . $user_id);
        }
    }

    /**
     * Show Change Password form for the currently logged-in user
     */
    public function change_password() {
        $data = [
            'title' => 'Change Password'
        ];
        $this->view('user/change_password', $data);
    }

    /**
     * Handle password change for the logged-in user
     */
    public function update_password() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('user/change_password');
            return;
        }

        $this->validateCSRF();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            $this->redirect('auth/login');
            return;
        }

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $result = $this->userModel->changeUserPassword($userId, $current, $new, $confirm);

        if ($result['status'] === 'success') {
            $_SESSION['success'] = $result['message'];
            $this->redirect('dashboard');
        } else {
            $_SESSION['error'] = $result['message'];
            $this->redirect('user/change_password');
        }
    }

    /**
     * Save Telegram chat id for the logged-in user (self-service, for business alerts).
     * Phase 0: previously hosted on the (now-removed) two_factor page; redirects repointed to dashboard.
     */
    public function update_telegram() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('dashboard');
            return;
        }

        $this->validateCSRF();

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->redirect('auth/login');
            return;
        }

        $result = $this->userModel->updateTelegramUserId($userId, $_POST['telegram_user_id'] ?? null);
        if (($result['status'] ?? '') === 'success') {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'] ?? 'Could not save Telegram User ID.';
        }

        $this->redirect('dashboard');
    }

    // Phase 0: TOTP 2FA methods removed (two_factor, two_factor_setup, two_factor_confirm,
    // two_factor_disable, two_factor_qr, admin_disable_two_factor). Login is username+password only.

    /**
     * Unified security audit — login + user + employee events.
     */
    public function security_audit() {
        $this->requireAdmin();
        require_once __DIR__ . '/../services/Security/SecurityAuditService.php';

        $filters = [
            'source'  => $_GET['source'] ?? 'all',
            'outcome' => $_GET['outcome'] ?? 'all',
            'q'       => $_GET['q'] ?? '',
            'limit'   => 300,
        ];

        $service = new SecurityAuditService();
        $result = $service->getUnifiedAudit($filters);

        $this->view('user/security_audit', [
            'title'   => 'Security audit',
            'entries' => $result['entries'],
            'stats'   => $result['stats'],
            'filters' => $filters,
        ]);
    }

    /**
     * Simple Audit Log viewer for User-related actions
     */
    public function audit() {
        $this->requireAdmin();
        $audit = new UserAudit();
        $logs = $audit->enrichWithPerformerNames($audit->getRecentLogs(300, 'user_'));

        $data = [
            'title' => 'User Audit Logs',
            'logs' => $logs
        ];

        $this->view('user/audit', $data);
    }

}