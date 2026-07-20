<?php
// app/controllers/CustomerController.php

require_once '../core/BaseController.php';
require_once '../app/models/CustomerModel.php';
require_once '../app/models/EmployeeModel.php';
require_once '../core/UserAudit.php';
require_once __DIR__ . '/../helpers/MasterDataAuditHelper.php';

class CustomerController extends BaseController {

    private $customerModel;
    private $employeeModel;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->customerModel = new CustomerModel();
        $this->employeeModel = new EmployeeModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $params = $_GET;
            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';

            $response = $this->customerModel->getCustomersForDataTable($params);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';

        $salesPersons = $this->employeeModel->getAllEmployees();

        $data = [
            'title'        => $showDeleted ? 'Inactive customers' : 'Customer directory',
            'showDeleted'  => $showDeleted,
            'salesPersons' => $salesPersons,
            'stats'        => $this->customerModel->getCustomerIndexStats(),
        ];
        $this->view('customer/index', $data);
    }

    public function show($id = null) {
        if (!$id) {
            $this->redirect('customer/index');
        }

        $customerId = (int)$id;
        $customer = $this->customerModel->getCustomerById($customerId);
        if (!$customer) {
            $_SESSION['error'] = 'Customer not found!';
            $this->redirect('customer/index');
        }

        $this->view('customer/show', [
            'title'    => ($customer['shop_name'] ?? 'Customer') . ' — Hub',
            'customer' => $customer,
            'summary'  => $this->customerModel->getCustomerHubSummary($customerId),
            'ledger'   => $this->customerModel->getRecentLedgerEntries($customerId),
            'invoices' => $this->customerModel->getRecentInvoices($customerId),
            'payments' => $this->customerModel->getRecentPayments($customerId),
        ]);
    }

    public function create() {
        $salesPersons = $this->employeeModel->getAllEmployees();
        $data = [
            'title' => 'Create New Customer',
            'salesPersons' => $salesPersons
        ];
        $this->view('customer/create', $data);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();

            $result = $this->customerModel->createCustomer($_POST);

            if ($result['status'] === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'customer_created', (int)($result['id'] ?? 0), [
                    'customer_code' => $result['customer_code'] ?? '',
                    'shop_name'     => $_POST['shop_name'] ?? '',
                    'customer_name' => $_POST['customer_name'] ?? '',
                    'mobile'        => $_POST['mobile'] ?? '',
                ]);

                $_SESSION['success'] = $result['message'];
                $this->redirect('customer/index');
            } else {
                $_SESSION['error'] = $result['message'];
                $this->redirect('customer/create');
            }
        }
    }

    public function edit($id = null) {
        if (!$id) $this->redirect('customer/index');

        $customer = $this->customerModel->getCustomerById($id);
        if (!$customer) {
            $_SESSION['error'] = "Customer not found!";
            $this->redirect('customer/index');
        }

        $salesPersons = $this->employeeModel->getAllEmployees();

        $data = [
            'title'        => 'Edit Customer',
            'customer'     => $customer,
            'salesPersons' => $salesPersons,
            'usage'        => $this->customerModel->getCustomerUsage((int)$id),
        ];
        $this->view('customer/edit', $data);
    }

    public function update($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();

            $customerId = (int)$id;
            $before = $this->customerModel->getCustomerById($customerId);
            if (!$before) {
                $_SESSION['error'] = 'Customer not found!';
                $this->redirect('customer/index');
            }

            $result = $this->customerModel->updateCustomer($customerId, $_POST);

            if ($result['status'] === 'success') {
                $after = array_merge($before, $this->customerModel->getCustomerById($customerId) ?: []);
                $displayOverrides = $this->buildCustomerAuditDisplayOverrides($before, $after);

                $details = MasterDataAuditHelper::buildUpdateDetails(
                    $before,
                    $after,
                    MasterDataAuditHelper::CUSTOMER_FIELDS,
                    $displayOverrides
                );

                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'customer_updated', $customerId, $details);

                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
        }
        $this->redirect('customer/index');
    }

    public function toggle($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();

            $customer = $this->customerModel->getCustomerById($id);
            if (!$customer) {
                echo json_encode(['status' => 'error', 'message' => 'Customer not found.']);
                exit;
            }

            $isCurrentlyActive = (int)$customer['is_active'] === 1;

            if ($isCurrentlyActive) {
                $safety = $this->customerModel->getDeactivationSafetyStatus((int)$id);
                if (!$safety['can_deactivate']) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => $this->customerModel->getDeactivationMessage((int)$id),
                    ]);
                    exit;
                }
            }

            if ($this->customerModel->toggleStatus($id)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'customer_status_changed', (int)$id);

                echo json_encode(['status' => 'success', 'message' => 'Customer status updated!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update status!']);
            }
            exit;
        }
        $this->redirect('customer/index');
    }

    public function delete($id = null) {
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        $safety = $this->customerModel->getDeactivationSafetyStatus((int)$id);

        if (!$safety['can_deactivate']) {
            echo json_encode([
                'status'  => 'error',
                'message' => $this->customerModel->getDeactivationMessage((int)$id)
                    . ' Please clear all dues or review related records before archiving.',
                'safety'  => $safety,
            ]);
            exit;
        }

        if ($this->customerModel->softDeleteCustomer($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'customer_deactivated', (int)$id);

            echo json_encode(['status' => 'success', 'message' => 'Customer deactivated successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to deactivate customer.']);
        }
        exit;
    }

    public function restore($id = null) {
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->customerModel->restoreCustomer($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'customer_restored', (int)$id);

            echo json_encode(['status' => 'success', 'message' => 'Customer restored successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to restore customer.']);
        }
        exit;
    }

    public function audit() {
        $logs = $this->userAudit->getRecentLogs(300, 'customer_');
        $logs = MasterDataAuditHelper::enrichLogsWithUserNames($logs);

        $data = [
            'title' => 'Customer Audit Logs',
            'logs' => $logs
        ];

        $this->view('customer/audit', $data);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: string, to: string}>
     */
    private function buildCustomerAuditDisplayOverrides(array $before, array $after): array
    {
        $overrides = [];

        if ((int)($before['sales_person_id'] ?? 0) !== (int)($after['sales_person_id'] ?? 0)) {
            $overrides['sales_person_id'] = [
                'from' => $this->resolveEmployeeName((int)($before['sales_person_id'] ?? 0)),
                'to'   => $this->resolveEmployeeName((int)($after['sales_person_id'] ?? 0)),
            ];
        }

        if ((float)($before['credit_limit'] ?? 0) !== (float)($after['credit_limit'] ?? 0)) {
            $overrides['credit_limit'] = [
                'from' => number_format((float)($before['credit_limit'] ?? 0), 2),
                'to'   => number_format((float)($after['credit_limit'] ?? 0), 2),
            ];
        }

        return $overrides;
    }

    private function resolveEmployeeName(int $employeeId): string
    {
        if ($employeeId <= 0) {
            return '—';
        }

        $employee = $this->employeeModel->getEmployeeById($employeeId);

        return trim((string)($employee['name'] ?? $employee['employee_name'] ?? '')) ?: ('#' . $employeeId);
    }
}
