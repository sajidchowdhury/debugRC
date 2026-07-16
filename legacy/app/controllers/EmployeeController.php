<?php
// app/controllers/EmployeeController.php

require_once '../core/BaseController.php';
require_once '../app/models/EmployeeModel.php';
require_once '../app/models/BranchModel.php';
require_once '../core/UserAudit.php';
require_once '../core/RoleRegistry.php';

class EmployeeController extends BaseController {

    private $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new EmployeeModel();
    }

    public function index() {
        $this->requireAdmin();

        // Handle DataTables server-side request
        if (isset($_GET['draw'])) {
            $params = $_GET;
            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';

            $response = $this->model->getEmployeesForDataTable($params);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        // Normal page load
        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';

        // For filters
        $branchModel = new BranchModel();
        $branches = $branchModel->getAllActiveBranches();

        $roles = RoleRegistry::filterList();

        $data = [
            'title'       => $showDeleted ? 'Deleted employees' : 'Workforce directory',
            'showDeleted' => $showDeleted,
            'branches'    => $branches,
            'roles'       => $roles,
            'stats'       => $this->model->getEmployeeIndexStats(),
        ];
        $this->view('employee/index', $data);
    }

public function create() {
    $this->requireAdmin();
    $branchModel = new BranchModel();   // Load Branch Model

    $data = [
        'title'    => 'Create New Employee',
        'branches' => $branchModel->getAllActiveBranches(),
        'roles'    => $this->assignableRoleList(),
    ];
    $this->view('employee/create', $data);
}
    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireAdmin();
            $this->validateCSRF();

            if (!$this->guardRoleAssignment($_POST['role'] ?? '')) {
                return;
            }

            $validation = $this->model->validateEmployeePayload($_POST);
            if ($validation['status'] !== 'success') {
                $_SESSION['error'] = $validation['message'];
                $this->redirect('employee/create');
                return;
            }

            $employee_id = $this->model->createEmployee($validation['data']);

            if ($employee_id) {
                // Handle photo upload after employee is created (so we have the real code)
                $photoUploadFailed = false;
                if (!empty($_FILES['photo']['name'])) {
                    $employee = $this->model->getEmployeeById($employee_id);
                    if ($employee && $employee['employee_code']) {
                        $photoPath = $this->model->uploadEmployeePhoto($_FILES['photo'], $employee['employee_code']);
                        if ($photoPath) {
                            $this->model->updateEmployeePhoto($employee_id, $photoPath);
                        } else {
                            $photoUploadFailed = true;
                        }
                    }
                }

                $audit = new UserAudit();
                $audit->log($_SESSION['user_id'] ?? 0, 'employee_created', $employee_id, ['name' => $_POST['name'] ?? '']);

                if ($photoUploadFailed) {
                    $_SESSION['error'] = "Employee created, but the photo could not be uploaded (check file type/size).";
                } else {
                    $_SESSION['success'] = "Employee created successfully! Set up their login account next.";
                }
                $this->redirect('employee/account/' . $employeeId);
            } else {
                $_SESSION['error'] = 'Failed to create employee!';
                $this->redirect('employee/create');
            }
        }
    }

public function edit($id = null) {
    $this->requireAdmin();
    if (!$id) $this->redirect('employee/index');

    $employee = $this->model->getEmployeeById($id);
    if (!$employee) {
        $_SESSION['error'] = "Employee not found!";
        $this->redirect('employee/index');
    }

    // Only a superadmin may open/modify a superadmin account.
    if (strtolower((string)($employee['role'] ?? '')) === Auth::ROLE_SUPERADMIN && !Auth::isSuperadmin()) {
        $_SESSION['error'] = "Only a super-admin can modify a super-admin account.";
        $this->redirect('employee/index');
    }

    $branchModel = new BranchModel();

    $data = [
        'title'    => 'Edit Employee',
        'employee' => $employee,
        'branches' => $branchModel->getAllActiveBranches(),
        'roles'    => $this->assignableRoleList((string)($employee['role'] ?? '')),
        'usage'    => $this->model->getEmployeeUsage((int)$id),
    ];
    $this->view('employee/edit', $data);
}

    public function update($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->requireAdmin();
            $this->validateCSRF();

            $employee = $this->model->getEmployeeById($id);
            if (!$employee) {
                $_SESSION['error'] = "Employee not found!";
                $this->redirect('employee/index');
                return;
            }

            // Protect existing superadmin accounts from non-superadmins.
            if (strtolower((string)($employee['role'] ?? '')) === Auth::ROLE_SUPERADMIN && !Auth::isSuperadmin()) {
                $_SESSION['error'] = "Only a super-admin can modify a super-admin account.";
                $this->redirect('employee/index');
                return;
            }

            // Prevent privilege escalation via the role field.
            if (!$this->guardRoleAssignment($_POST['role'] ?? '')) {
                return;
            }

            if (!$this->guardRoleAssignment($_POST['role'] ?? '')) {
                return;
            }

            $validation = $this->model->validateEmployeePayload($_POST, (int)$id);
            if ($validation['status'] !== 'success') {
                $_SESSION['error'] = $validation['message'];
                $this->redirect('employee/edit/' . (int)$id);
                return;
            }

            $payload = $validation['data'];
            $before = $employee;

            // Handle photo update / removal
            $removePhoto = !empty($_POST['remove_photo']);

            $photoUploadIssue = false;
            if (!empty($_FILES['photo']['name'])) {
                // Delete old photo if exists (we are replacing)
                if (!empty($employee['photo'])) {
                    $this->model->deleteEmployeePhoto($employee['photo']);
                }

                $photoPath = $this->model->uploadEmployeePhoto($_FILES['photo'], $employee['employee_code']);
                if ($photoPath) {
                    $payload['photo'] = $photoPath;
                } else {
                    $photoUploadIssue = true;
                }
            } elseif ($removePhoto && !empty($employee['photo'])) {
                // User explicitly wants to remove current photo
                $this->model->deleteEmployeePhoto($employee['photo']);
                $payload['photo'] = '';
            }

            if ($this->model->updateEmployee($id, $payload)) {
                $this->syncSessionAfterEmployeeUpdate((int)$id, $before, $payload);

                $audit = new UserAudit();
                $audit->log($_SESSION['user_id'] ?? 0, 'employee_updated', (int)$id, ['name' => $payload['name'] ?? '']);

                if ($photoUploadIssue) {
                    $_SESSION['error'] = "Employee updated, but new photo could not be uploaded (invalid file).";
                } else {
                    $_SESSION['success'] = "Employee updated successfully!";
                }
            } else {
                $_SESSION['error'] = 'Failed to update employee!';
            }
        }
        $this->redirect('employee/index');
    }

    public function toggle($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('employee/index');
            return;
        }

        $this->validateCSRF();

        if ($this->model->toggleStatus($id)) {
            $audit = new UserAudit();
            $audit->log($_SESSION['user_id'] ?? 0, 'employee_status_changed', (int)$id);
            $this->finishEmployeeMutation(true, 'Employee status updated!', 'employee/index');
            return;
        }

        $refs = $this->model->getReferenceCounts((int)$id);
        $msg = 'Cannot deactivate this employee.';

        if ($refs['users'] > 0) {
            $msg = 'Cannot deactivate this employee because they have a linked user account. Please manage or deactivate the user account first.';
        }

        $this->finishEmployeeMutation(false, $msg, 'employee/index');
    }

    // Soft delete employee
    public function delete($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('employee/index');
            return;
        }

        $this->validateCSRF();

        if ($this->model->softDeleteEmployee((int)$id, $_SESSION['user_id'] ?? 0)) {
            $audit = new UserAudit();
            $audit->log($_SESSION['user_id'] ?? 0, 'employee_soft_deleted', (int)$id);
            $this->finishEmployeeMutation(true, 'Employee has been deleted (soft delete).', 'employee/index');
            return;
        }

        $refs = $this->model->getReferenceCounts((int)$id);
        $msg = 'Cannot delete this employee.';

        if ($refs['users'] > 0) {
            $msg = 'Cannot delete this employee because they have a linked user account. Please handle the user first.';
        } elseif ($refs['sales_invoices'] > 0 || $refs['customers'] > 0) {
            $msg = 'Cannot delete this employee because they have historical sales or customer records.';
        }

        $this->finishEmployeeMutation(false, $msg, 'employee/index');
    }

    // Restore soft-deleted employee
    public function restore($id = null) {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('employee/index?deleted=1');
            return;
        }

        $this->validateCSRF();

        if ($this->model->restoreEmployee((int)$id)) {
            $audit = new UserAudit();
            $audit->log($_SESSION['user_id'] ?? 0, 'employee_restored', (int)$id);
            $this->finishEmployeeMutation(true, 'Employee has been restored.', 'employee/index?deleted=1');
            return;
        }

        $this->finishEmployeeMutation(false, 'Failed to restore employee.', 'employee/index?deleted=1');
    }

    private function finishEmployeeMutation(bool $success, string $message, string $redirectPath): void
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

    /**
     * Unified employee profile + system login hub (C3).
     */
    public function account($id = null) {
        $this->requireAdmin();

        if (!$id) {
            $this->redirect('employee/index');
            return;
        }

        $employeeId = (int)$id;
        $employee = $this->model->getEmployeeById($employeeId);

        if (!$employee) {
            $_SESSION['error'] = 'Employee not found.';
            $this->redirect('employee/index');
            return;
        }

        require_once '../app/models/UserModel.php';
        $userModel = new UserModel();
        $account = $userModel->getUserAccountSummaryByEmployeeId($employeeId);
        $permissionStats = ['view_count' => 0, 'edit_count' => 0, 'menu_count' => 0];

        if ($account) {
            $permissionStats = $userModel->getPermissionStats((int)$account['id']);
        }

        $this->view('employee/account', [
            'title'           => 'Employee & account',
            'employee'        => $employee,
            'usage'           => $this->model->getEmployeeUsage($employeeId),
            'account'         => $account,
            'permissionStats' => $permissionStats,
        ]);
    }

    /**
     * Simple Audit Log viewer for Employee-related actions
     */
    public function audit() {
        $this->requireAdmin();
        $audit = new UserAudit();
        $logs = $audit->enrichWithPerformerNames($audit->getRecentLogs(300, 'employee_'));

        $data = [
            'title' => 'Employee Audit Logs',
            'logs' => $logs
        ];

        $this->view('employee/audit', $data);
    }

    /**
     * Quick View Modal - Phase 2.1
     * Returns HTML fragment for the modal
     */
    public function quickView($id = null) {
        $this->requireAdmin();

        if (!$id) {
            http_response_code(404);
            echo "Employee not found";
            exit;
        }

        $employee = $this->model->getEmployeeById((int)$id);

        if (!$employee) {
            http_response_code(404);
            echo "Employee not found";
            exit;
        }

        $usage = $this->model->getEmployeeUsage((int)$id);
        $employee['user_id'] = $usage['user_id'] ?? null;
        $employee['has_user_account'] = $usage['has_user_account'] ?? false;
        $employee['has_active_user'] = $usage['has_active_user'] ?? false;

        // Load the modal partial
        require '../app/views/employee/partials/quick_view.php';
        exit;
    }

    /**
     * Build the list of roles the current actor is allowed to assign.
     * Elevated tiers are only offered to users who may grant them.
     * $currentRole keeps an existing (e.g. superadmin) value selectable on edit.
     */
    private function assignableRoleList(string $currentRole = ''): array
    {
        return RoleRegistry::assignableForActor($currentRole);
    }

    /**
     * Block privilege escalation through the posted role field.
     * Returns true when the role may be assigned, otherwise redirects.
     */
    private function guardRoleAssignment(string $role): bool
    {
        $role = strtolower(trim($role));
        if ($role === '' || Auth::canAssignRole($role)) {
            return true;
        }

        $_SESSION['error'] = "You are not allowed to assign the '" . htmlspecialchars($role) . "' role.";
        $this->redirect('employee/index');
        return false;
    }

    /**
     * Keep the active session in sync when the logged-in employee record changes.
     * Force re-login for other sessions when role/branch changes on a linked account.
     */
    private function syncSessionAfterEmployeeUpdate(int $employeeId, array $before, array $after): void
    {
        $roleChanged = RoleRegistry::normalize((string)($before['role'] ?? ''))
            !== RoleRegistry::normalize((string)($after['role'] ?? ''));
        $branchChanged = (int)($before['branch_id'] ?? 0) !== (int)($after['branch_id'] ?? 0);
        $isSelf = (int)($_SESSION['employee_id'] ?? 0) === $employeeId;

        if ($isSelf) {
            $employee = $this->model->getEmployeeById($employeeId);
            if ($employee) {
                $_SESSION['role'] = (string)($employee['role'] ?? $_SESSION['role'] ?? 'user');
                $_SESSION['employee_name'] = (string)($employee['name'] ?? $_SESSION['employee_name'] ?? '');
                $_SESSION['branch_id'] = $employee['branch_id'] ?? $_SESSION['branch_id'] ?? null;
                $_SESSION['branch_name'] = (string)($employee['branch_name'] ?? $_SESSION['branch_name'] ?? '');
                $_SESSION['photo'] = (string)($employee['photo'] ?? '');
                if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['role'] = $_SESSION['role'];
                    $_SESSION['user']['branch_id'] = $_SESSION['branch_id'];
                }
            }
        }

        if (!$this->model->hasUserAccount($employeeId)) {
            return;
        }

        if ($roleChanged || $branchChanged) {
            $this->model->touchLinkedUserCredential($employeeId);
        }

        if ($isSelf) {
            $this->model->refreshSessionCredentialForEmployee($employeeId);
        }
    }
}