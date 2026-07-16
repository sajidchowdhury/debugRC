<?php

// app/controllers/WarehouseController.php



require_once '../core/BaseController.php';

require_once '../app/models/WarehouseModel.php';

require_once '../app/models/BranchModel.php';

require_once '../core/UserAudit.php';

require_once __DIR__ . '/../helpers/MasterDataAuditHelper.php';



class WarehouseController extends BaseController {



    private WarehouseModel $warehouseModel;

    private BranchModel $branchModel;

    private UserAudit $userAudit;



    public function __construct() {

        $this->requireLogin();

        $this->warehouseModel = new WarehouseModel();

        $this->branchModel = new BranchModel();

        $this->userAudit = new UserAudit();

    }



    public function index() {

        $this->requireRouteAccess();



        if (isset($_GET['draw'])) {

            $params = $_GET;

            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';



            $response = $this->warehouseModel->getWarehousesForDataTable($params);

            header('Content-Type: application/json');

            echo json_encode($response);

            exit;

        }



        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';

        $branches = $this->branchModel->getAllActiveBranches();



        $data = [

            'title'       => 'Warehouse Management',

            'showDeleted' => $showDeleted,

            'branches'    => $branches,

            'stats'       => $this->warehouseModel->getWarehouseIndexStats(),

        ];

        $this->view('warehouse/index', $data);

    }



    public function create() {

        $this->requireRouteAccess();



        $branches = $this->branchModel->getAllActiveBranches();

        $data = [

            'title'           => 'Create New Warehouse',

            'branches'        => $branches,

            'preselectBranch' => (int)($_GET['branch'] ?? 0),

        ];

        $this->view('warehouse/create', $data);

    }



    public function store() {

        $this->requireRouteAccess();



        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $this->validateCSRF();



            $validated = $this->warehouseModel->validateWarehousePayload($_POST);

            if (!$validated['ok']) {

                $_SESSION['error'] = $validated['error'];

                $this->redirect('warehouse/create');

            }



            $branch = $this->branchModel->getBranchById((int)$validated['data']['branch_id']);

            $newId = $this->warehouseModel->createWarehouse($validated['data']);



            if ($newId) {

                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'warehouse_created', $newId, [

                    'warehouse_code' => $validated['data']['warehouse_code'] ?? '',

                    'warehouse_name' => $validated['data']['warehouse_name'] ?? '',

                    'branch_id'      => (int)($validated['data']['branch_id'] ?? 0),

                    'branch_name'    => $branch['branch_name'] ?? '',

                    'address'        => $validated['data']['address'] ?? '',

                ]);



                $_SESSION['success'] = "Warehouse created successfully!";

                $this->redirect('warehouse/index');

            } else {

                $_SESSION['error'] = "Failed to create warehouse!";

                $this->redirect('warehouse/create');

            }

        }

    }



    public function edit($id = null) {

        $this->requireRouteAccess();



        if (!$id) $this->redirect('warehouse/index');



        $warehouse = $this->warehouseModel->getWarehouseById($id);

        if (!$warehouse) {

            $_SESSION['error'] = "Warehouse not found!";

            $this->redirect('warehouse/index');

        }



        $branches = $this->branchModel->getAllActiveBranches();



        $data = [

            'title'     => 'Edit Warehouse',

            'warehouse' => $warehouse,

            'branches'  => $branches,

            'usage'     => $this->warehouseModel->getWarehouseUsage((int)$id),

        ];

        $this->view('warehouse/edit', $data);

    }



    public function update($id = null) {

        $this->requireRouteAccess();



        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {

            $this->validateCSRF();



            $warehouseId = (int)$id;

            $before = $this->warehouseModel->getWarehouseById($warehouseId);

            if (!$before) {

                $_SESSION['error'] = 'Warehouse not found!';

                $this->redirect('warehouse/index');

            }



            $validated = $this->warehouseModel->validateWarehousePayload($_POST, $warehouseId);

            if (!$validated['ok']) {

                $_SESSION['error'] = $validated['error'];

                $this->redirect('warehouse/edit/' . $warehouseId);

            }



            $afterData = $validated['data'];

            $newBranch = $this->branchModel->getBranchById((int)$afterData['branch_id']);

            $afterData['branch_name'] = $newBranch['branch_name'] ?? '';



            $displayOverrides = [];

            if ((int)($before['branch_id'] ?? 0) !== (int)($afterData['branch_id'] ?? 0)) {

                $oldBranch = $this->branchModel->getBranchById((int)$before['branch_id']);

                $displayOverrides['branch_id'] = [

                    'from' => $oldBranch['branch_name'] ?? (string)($before['branch_id'] ?? '—'),

                    'to'   => $afterData['branch_name'] ?: (string)$afterData['branch_id'],

                ];

            }



            if ($this->warehouseModel->updateWarehouse($warehouseId, $afterData)) {

                $details = MasterDataAuditHelper::buildUpdateDetails(

                    $before,

                    $afterData,

                    MasterDataAuditHelper::WAREHOUSE_FIELDS,

                    $displayOverrides

                );



                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'warehouse_updated', $warehouseId, $details);



                $_SESSION['success'] = "Warehouse updated successfully!";

            } else {

                $_SESSION['error'] = "Failed to update warehouse!";

            }

        }

        $this->redirect('warehouse/index');

    }



    public function toggle($id = null) {

        $this->requireRouteAccess();



        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {

            $this->validateCSRF();



            $warehouseId = (int)$id;

            $warehouse = $this->warehouseModel->getWarehouseById($warehouseId);

            if (!$warehouse) {

                echo json_encode(['status' => 'error', 'message' => 'Warehouse not found.']);

                exit;

            }



            $isCurrentlyActive = !empty($warehouse['is_active']);



            if ($isCurrentlyActive && !$this->warehouseModel->canDeactivateWarehouse($warehouseId)) {

                echo json_encode([

                    'status'  => 'error',

                    'message' => $this->warehouseModel->getDeactivationMessage($warehouseId),

                ]);

                exit;

            }



            if ($this->warehouseModel->toggleStatus($warehouseId)) {

                $updated = $this->warehouseModel->getWarehouseById($warehouseId);

                $nowActive = !empty($updated['is_active']);



                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'warehouse_status_changed', $warehouseId, [

                    'warehouse_code' => $warehouse['warehouse_code'] ?? '',

                    'warehouse_name' => $warehouse['warehouse_name'] ?? '',

                    'branch_name'    => $warehouse['branch_name'] ?? '',

                    'old_status'     => $isCurrentlyActive ? 'active' : 'inactive',

                    'new_status'     => $nowActive ? 'active' : 'inactive',

                ]);



                echo json_encode(['status' => 'success', 'message' => 'Warehouse status updated!']);

            } else {

                echo json_encode(['status' => 'error', 'message' => 'Failed to update status!']);

            }

            exit;

        }

        $this->redirect('warehouse/index');

    }



    public function show($id = null) {

        $this->requireRouteAccess();



        if (!$id) {

            $this->redirect('warehouse/index');

        }



        $warehouseId = (int)$id;

        $warehouse = $this->warehouseModel->getWarehouseById($warehouseId);

        if (!$warehouse) {

            $_SESSION['error'] = 'Warehouse not found!';

            $this->redirect('warehouse/index');

        }



        $this->view('warehouse/show', [

            'title'           => ($warehouse['warehouse_name'] ?? 'Warehouse') . ' — Hub',

            'warehouse'       => $warehouse,

            'usage'           => $this->warehouseModel->getWarehouseUsage($warehouseId),

            'stockByCategory' => $this->warehouseModel->getWarehouseStockByCategory($warehouseId),

            'stockByGroup'    => $this->warehouseModel->getWarehouseStockByGroup($warehouseId),

            'transfers'       => $this->warehouseModel->getRecentTransfersForWarehouse($warehouseId),

            'adjustments'     => $this->warehouseModel->getRecentAdjustmentsForWarehouse($warehouseId),

        ]);

    }



    public function stock_search($id = null) {

        $this->requireRouteAccess();

        header('Content-Type: application/json');



        if (!$id) {

            echo json_encode(['status' => 'error', 'message' => 'Warehouse not found.']);

            exit;

        }



        $warehouseId = (int)$id;

        if (!$this->warehouseModel->getWarehouseById($warehouseId)) {

            echo json_encode(['status' => 'error', 'message' => 'Warehouse not found.']);

            exit;

        }



        $search = trim((string)($_GET['q'] ?? ''));

        $page = max(1, (int)($_GET['page'] ?? 1));

        $result = $this->warehouseModel->searchWarehouseStock($warehouseId, $search, $page, 20);



        echo json_encode([

            'status' => 'success',

            'data'   => $result,

        ]);

        exit;

    }



    public function audit() {

        $this->requireRouteAccess();



        $logs = $this->userAudit->getRecentLogs(300, 'warehouse_');

        $logs = MasterDataAuditHelper::enrichLogsWithUserNames($logs);



        $this->view('warehouse/audit', [

            'title' => 'Warehouse Audit Logs',

            'logs'  => $logs,

        ]);

    }

}

