<?php

// app/controllers/BranchController.php



require_once '../core/BaseController.php';

require_once '../app/models/BranchModel.php';

require_once '../core/UserAudit.php';

require_once __DIR__ . '/../helpers/MasterDataAuditHelper.php';



class BranchController extends BaseController {



    private BranchModel $branchModel;

    private UserAudit $userAudit;



    public function __construct() {

        $this->requireLogin();

        $this->branchModel = new BranchModel();

        $this->userAudit = new UserAudit();

    }



    public function index() {

        $this->requireRouteAccess();



        if (isset($_GET['draw'])) {

            $params = $_GET;

            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';



            $response = $this->branchModel->getBranchesForDataTable($params);

            header('Content-Type: application/json');

            echo json_encode($response);

            exit;

        }



        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';



        $data = [

            'title'       => 'Branch Management',

            'showDeleted' => $showDeleted,

            'stats'       => $this->branchModel->getBranchIndexStats(),

        ];

        $this->view('branch/index', $data);

    }



    public function create() {

        $this->requireRouteAccess();



        $data = ['title' => 'Create New Branch'];

        $this->view('branch/create', $data);

    }



    public function store() {

        $this->requireRouteAccess();



        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $this->validateCSRF();



            $validated = $this->branchModel->validateBranchPayload($_POST);

            if (!$validated['ok']) {

                $_SESSION['error'] = $validated['error'];

                $this->redirect('branch/create');

            }



            $newId = $this->branchModel->createBranch($validated['data']);

            if ($newId) {

                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'branch_created', $newId, [

                    'branch_code' => $validated['data']['branch_code'] ?? '',

                    'branch_name' => $validated['data']['branch_name'] ?? '',

                    'phone'       => $validated['data']['phone'] ?? '',

                    'email'       => $validated['data']['email'] ?? '',

                ]);



                $_SESSION['success'] = "Branch created successfully!";

                $this->redirect('branch/index');

            } else {

                $_SESSION['error'] = "Failed to create branch!";

                $this->redirect('branch/create');

            }

        }

    }



    public function edit($id = null) {

        $this->requireRouteAccess();



        if (!$id) $this->redirect('branch/index');



        $branch = $this->branchModel->getBranchById($id);

        if (!$branch) {

            $_SESSION['error'] = "Branch not found!";

            $this->redirect('branch/index');

        }



        $data = [

            'title'  => 'Edit Branch',

            'branch' => $branch,

            'usage'  => $this->branchModel->getBranchUsage((int)$id),

        ];

        $this->view('branch/edit', $data);

    }



    public function update($id = null) {

        $this->requireRouteAccess();



        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {

            $this->validateCSRF();



            $branchId = (int)$id;

            $before = $this->branchModel->getBranchById($branchId);

            if (!$before) {

                $_SESSION['error'] = 'Branch not found!';

                $this->redirect('branch/index');

            }



            $validated = $this->branchModel->validateBranchPayload($_POST, $branchId);

            if (!$validated['ok']) {

                $_SESSION['error'] = $validated['error'];

                $this->redirect('branch/edit/' . $branchId);

            }



            if ($this->branchModel->updateBranch($branchId, $validated['data'])) {

                $details = MasterDataAuditHelper::buildUpdateDetails(

                    $before,

                    $validated['data'],

                    MasterDataAuditHelper::BRANCH_FIELDS

                );



                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'branch_updated', $branchId, $details);



                $_SESSION['success'] = "Branch updated successfully!";

            } else {

                $_SESSION['error'] = "Failed to update branch!";

            }

        }

        $this->redirect('branch/index');

    }



    public function toggle($id = null) {

        $this->requireRouteAccess();



        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {

            $this->validateCSRF();



            $branchId = (int)$id;

            $branch = $this->branchModel->getBranchById($branchId);

            if (!$branch) {

                echo json_encode(['status' => 'error', 'message' => 'Branch not found.']);

                exit;

            }



            $isCurrentlyActive = !empty($branch['is_active']);



            if ($isCurrentlyActive && !$this->branchModel->canDeactivateBranch($branchId)) {

                echo json_encode([

                    'status'  => 'error',

                    'message' => $this->branchModel->getDeactivationMessage($branchId),

                ]);

                exit;

            }



            if ($this->branchModel->toggleStatus($branchId)) {

                $updatedBranch = $this->branchModel->getBranchById($branchId);

                $nowActive = !empty($updatedBranch['is_active']);



                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'branch_status_changed', $branchId, [

                    'branch_code' => $branch['branch_code'] ?? '',

                    'branch_name' => $branch['branch_name'] ?? '',

                    'old_status'  => $isCurrentlyActive ? 'active' : 'inactive',

                    'new_status'  => $nowActive ? 'active' : 'inactive',

                ]);



                echo json_encode(['status' => 'success', 'message' => 'Branch status updated!']);

            } else {

                echo json_encode(['status' => 'error', 'message' => 'Failed to update status!']);

            }

            exit;

        }

        $this->redirect('branch/index');

    }



    public function show($id = null) {

        $this->requireRouteAccess();



        if (!$id) {

            $this->redirect('branch/index');

        }



        $branchId = (int)$id;

        $branch = $this->branchModel->getBranchById($branchId);

        if (!$branch) {

            $_SESSION['error'] = 'Branch not found!';

            $this->redirect('branch/index');

        }



        $this->view('branch/show', [

            'title'           => ($branch['branch_name'] ?? 'Branch') . ' — Hub',

            'branch'          => $branch,

            'usage'           => $this->branchModel->getBranchUsage($branchId),

            'stock'           => $this->branchModel->getBranchStockSummary($branchId),

            'stockByCategory' => $this->branchModel->getBranchStockByCategory($branchId),

            'stockByGroup'    => $this->branchModel->getBranchStockByGroup($branchId),

            'warehouses'      => $this->branchModel->getBranchWarehouses($branchId),

            'employees'       => $this->branchModel->getBranchEmployeesPreview($branchId),

        ]);

    }



    public function audit() {

        $this->requireRouteAccess();



        $logs = $this->userAudit->getRecentLogs(300, 'branch_');

        $logs = MasterDataAuditHelper::enrichLogsWithUserNames($logs);



        $this->view('branch/audit', [

            'title' => 'Branch Audit Logs',

            'logs'  => $logs,

        ]);

    }

}

