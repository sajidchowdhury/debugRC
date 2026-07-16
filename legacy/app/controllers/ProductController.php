<?php
// app/controllers/ProductController.php

require_once '../core/BaseController.php';
require_once '../app/models/ProductModel.php';
require_once '../core/UserAudit.php';

class ProductController extends BaseController {

    private ProductModel $model;
    private UserAudit $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new ProductModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        $this->requireRouteAccess();

        if (isset($_GET['draw'])) {
            $params = $_GET;
            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';
            header('Content-Type: application/json');
            echo json_encode($this->model->getProductsForDataTable($params));
            exit;
        }

        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';

        $this->view('products/index', [
            'title'       => 'Product Management',
            'showDeleted' => $showDeleted,
            'categories'  => $this->model->getCategories(),
            'groups'      => $this->model->getGroups(true),
            'units'       => ProductModel::ALLOWED_UNITS,
            'stats'       => $this->model->getProductIndexStats(),
            'publicUrl'   => defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL,
        ]);
    }

    public function create() {
        $this->requireRouteAccess();

        $this->view('products/create', [
            'title'      => 'Create New Product',
            'categories' => $this->model->getCategories(),
            'groups'     => $this->model->getGroups(true),
            'defaultGroupId' => $this->model->getDefaultGroupId(),
            'units'      => ProductModel::ALLOWED_UNITS,
            'publicUrl'  => defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL,
        ]);
    }

    public function edit($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            $this->redirect('product');
        }

        $product = $this->model->getById($id);
        if (!$product) {
            $this->abortPage('Product not found.', 'product');
        }

        $this->view('products/edit', [
            'title'      => 'Edit Product',
            'product'    => $product,
            'categories' => $this->model->getCategories(),
            'groups'     => $this->model->getGroups(true),
            'snapshot'   => $this->model->getProductStockAndPrice((int)$id),
            'publicUrl'  => defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL,
            'units'      => ProductModel::ALLOWED_UNITS,
        ]);
    }

    public function update($id) {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $this->validateCSRF();

        $product = $this->model->getById($id);
        if (!$product) {
            $_SESSION['error'] = 'Product not found!';
            $this->redirect('product');
            return;
        }

        $payload = $this->parseProductFormPayload();
        if ($payload === null) {
            $this->redirect('product/edit/' . $id);
            return;
        }

        $imageUploadFailed = false;
        if (!empty($_FILES['image']['name'])) {
            if (!empty($product['image'])) {
                $this->model->deleteProductImage($product['image']);
            }
            $imagePath = $this->model->uploadProductImage($_FILES['image']);
            if ($imagePath) {
                $payload['image'] = $imagePath;
            } else {
                $imageUploadFailed = true;
            }
        }

        if ($this->model->update($id, $payload)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_updated', (int)$id, [
                'product_name' => $payload['product_name'],
            ]);

            if ($imageUploadFailed) {
                $_SESSION['error'] = 'Product updated, but new image upload failed (invalid file type or too large).';
            } else {
                $_SESSION['success'] = 'Product updated successfully!';
            }
            $this->redirect('product');
            return;
        }

        $_SESSION['error'] = 'Failed to update product.';
        $this->redirect('product/edit/' . $id);
    }

    public function price_history($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            $this->redirect('product');
        }

        $product = $this->model->getById($id);
        if (!$product) {
            $this->abortPage('Product not found.', 'product');
        }

        $currentPrice = $this->model->getCurrentPrice((int)$id);

        $this->view('products/price_history', [
            'title'        => 'Price History - ' . $product['product_name'],
            'product'      => $product,
            'history'      => $this->model->getPriceHistory($id),
            'currentPrice' => $currentPrice,
        ]);
    }

    public function add_price($id = null) {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            return;
        }

        $this->validateCSRF();

        $product = $this->model->getById($id);
        if (!$product) {
            $_SESSION['error'] = 'Product not found.';
            $this->redirect('product');
            return;
        }

        $min     = (float)($_POST['min_rate'] ?? 0);
        $max     = (float)($_POST['max_rate'] ?? 0);
        $default = (float)($_POST['default_rate'] ?? 0);

        if ($min <= 0 || $max <= 0 || $default <= 0) {
            $_SESSION['error'] = 'Min, max, and default rates must be greater than zero.';
            $this->redirect('product/price_history/' . $id);
            return;
        }

        if ($min > $default || $default > $max) {
            $_SESSION['error'] = 'Rates must satisfy: min ≤ default ≤ max.';
            $this->redirect('product/price_history/' . $id);
            return;
        }

        $previous = $this->model->getCurrentPrice((int)$id);

        if ($this->model->addPriceHistory((int)$id, $min, $max, $default)) {
            $details = [
                'min_rate'     => $min,
                'max_rate'     => $max,
                'default_rate' => $default,
            ];
            if ($previous) {
                $details['previous_min_rate']     = $previous['min_rate'];
                $details['previous_max_rate']     = $previous['max_rate'];
                $details['previous_default_rate'] = $previous['default_rate'];
            }

            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_price_added', (int)$id, $details);
            $_SESSION['success'] = 'Price range updated successfully.';
            $this->redirect('product/price_history/' . $id . '?success=1');
            return;
        }

        $_SESSION['error'] = 'Failed to save price range.';
        $this->redirect('product/price_history/' . $id);
    }

    public function store() {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $this->validateCSRF();

        $payload = $this->parseProductFormPayload();
        if ($payload === null) {
            $this->redirect('product/create');
            return;
        }

        $payload['product_code'] = $this->model->generateProductCode();

        $imageUploadFailed = false;
        if (!empty($_FILES['image']['name'])) {
            $imagePath = $this->model->uploadProductImage($_FILES['image']);
            if ($imagePath) {
                $payload['image'] = $imagePath;
            } else {
                $imageUploadFailed = true;
            }
        }

        if ($this->model->create($payload)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_created', null, [
                'product_name' => $payload['product_name'],
                'product_code' => $payload['product_code'],
                'group_id'     => $payload['group_id'],
            ]);

            if ($imageUploadFailed) {
                $_SESSION['error'] = 'Product created, but image upload failed (invalid file type or too large).';
            } else {
                $_SESSION['success'] = 'Product created successfully!';
            }
            $this->redirect('product');
            return;
        }

        $_SESSION['error'] = 'Failed to create product.';
        $this->redirect('product/create');
    }

    public function delete_price($price_id = null) {
        $this->requireRouteAccess();

        echo json_encode([
            'status'  => 'error',
            'message' => 'Price history is append-only. Add a new entry to change the active range.',
        ]);
        exit;
    }

    public function delete($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->model->delete($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_deactivated', (int)$id);
            echo json_encode(['status' => 'success', 'message' => 'Product deactivated successfully']);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Cannot deactivate this product! It has been used in transactions.',
            ]);
        }
        exit;
    }

    public function restore($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->model->restore($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_restored', (int)$id);
            echo json_encode(['status' => 'success', 'message' => 'Product restored successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to restore product']);
        }
        exit;
    }

    public function audit() {
        $this->requireRouteAccess();

        $logs = $this->userAudit->getRecentLogs(300, 'product_');
        $logs = $this->enrichAuditLogsWithUserNames($logs);

        $this->view('products/audit', [
            'title' => 'Product Audit Logs',
            'logs'  => $logs,
        ]);
    }

    public function categories() {
        $this->requireRouteAccess();

        if (isset($_GET['draw'])) {
            $params = $_GET;
            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';
            header('Content-Type: application/json');
            echo json_encode($this->model->getCategoriesForDataTable($params));
            exit;
        }

        $this->view('products/categories/index', [
            'title'       => 'Category Management',
            'showDeleted' => isset($_GET['deleted']) && $_GET['deleted'] == '1',
            'stats'       => $this->model->getCategoryIndexStats(),
        ]);
    }

    public function categoryCreate() {
        $this->requireRouteAccess();
        $this->view('products/categories/create', ['title' => 'Create Category']);
    }

    public function categoryStore() {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $name = trim($_POST['category_name'] ?? '');

            if ($name === '') {
                $_SESSION['error'] = 'Category name is required.';
                $this->redirect('product/categoryCreate');
                return;
            }

            if ($this->model->createCategory($name)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_category_created', null, ['category_name' => $name]);
                $_SESSION['success'] = 'Category created successfully!';
                $this->redirect('product/categories');
                return;
            }

            $_SESSION['error'] = 'Failed to create category.';
            $this->redirect('product/categoryCreate');
        }
    }

    public function categoryEdit($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            $this->redirect('product/categories');
        }

        $category = $this->model->getCategoryById($id);
        if (!$category) {
            $_SESSION['error'] = 'Category not found.';
            $this->redirect('product/categories');
            return;
        }

        $this->view('products/categories/edit', [
            'title'    => 'Edit Category',
            'category' => $category,
        ]);
    }

    public function categoryUpdate($id = null) {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();
            $name = trim($_POST['category_name'] ?? '');

            if ($name === '') {
                $_SESSION['error'] = 'Category name is required.';
                $this->redirect('product/categoryEdit/' . $id);
                return;
            }

            if ($this->model->updateCategory($id, $name)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_category_updated', (int)$id, ['category_name' => $name]);
                $_SESSION['success'] = 'Category updated successfully!';
                $this->redirect('product/categories');
                return;
            }

            $_SESSION['error'] = 'Failed to update category.';
            $this->redirect('product/categoryEdit/' . $id);
        }
    }

    public function categoryDelete($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->model->softDeleteCategory($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_category_deactivated', (int)$id);
            echo json_encode(['status' => 'success', 'message' => 'Category deactivated successfully.']);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Cannot deactivate this category because it is still assigned to active products.',
            ]);
        }
        exit;
    }

    public function categoryRestore($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->model->restoreCategory($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_category_restored', (int)$id);
            echo json_encode(['status' => 'success', 'message' => 'Category restored successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to restore category.']);
        }
        exit;
    }

    public function groups() {
        $this->requireRouteAccess();

        if (isset($_GET['draw'])) {
            $params = $_GET;
            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';
            header('Content-Type: application/json');
            echo json_encode($this->model->getGroupsForDataTable($params));
            exit;
        }

        $this->view('products/groups/index', [
            'title'       => 'Product Group Management',
            'showDeleted' => isset($_GET['deleted']) && $_GET['deleted'] == '1',
            'stats'       => $this->model->getGroupIndexStats(),
        ]);
    }

    public function groupCreate() {
        $this->requireRouteAccess();
        $this->view('products/groups/create', ['title' => 'Create Product Group']);
    }

    public function groupStore() {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $name = trim($_POST['group_name'] ?? '');

            if ($name === '') {
                $_SESSION['error'] = 'Group name is required.';
                $this->redirect('product/groupCreate');
                return;
            }

            if ($this->model->createGroup($name)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_group_created', null, ['group_name' => $name]);
                $_SESSION['success'] = 'Group created successfully!';
                $this->redirect('product/groups');
                return;
            }

            $_SESSION['error'] = 'Failed to create group (name may already exist).';
            $this->redirect('product/groupCreate');
        }
    }

    public function groupEdit($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            $this->redirect('product/groups');
        }

        $group = $this->model->getGroupById((int)$id);
        if (!$group) {
            $_SESSION['error'] = 'Group not found.';
            $this->redirect('product/groups');
            return;
        }

        $this->view('products/groups/edit', [
            'title' => 'Edit Product Group',
            'group' => $group,
        ]);
    }

    public function groupUpdate($id = null) {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();
            $name = trim($_POST['group_name'] ?? '');

            if ($name === '') {
                $_SESSION['error'] = 'Group name is required.';
                $this->redirect('product/groupEdit/' . $id);
                return;
            }

            if ($this->model->updateGroup((int)$id, $name)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_group_updated', (int)$id, ['group_name' => $name]);
                $_SESSION['success'] = 'Group updated successfully!';
                $this->redirect('product/groups');
                return;
            }

            $_SESSION['error'] = 'Failed to update group.';
            $this->redirect('product/groupEdit/' . $id);
        }
    }

    public function groupDelete($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ((int)$id === ProductModel::DEFAULT_GROUP_ID) {
            echo json_encode(['status' => 'error', 'message' => 'The default China group cannot be deactivated.']);
            exit;
        }

        if ($this->model->softDeleteGroup((int)$id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_group_deactivated', (int)$id);
            echo json_encode(['status' => 'success', 'message' => 'Group deactivated successfully.']);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Cannot deactivate this group because it is still assigned to active products, or it is the system default.',
            ]);
        }
        exit;
    }

    public function groupRestore($id = null) {
        $this->requireRouteAccess();

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->model->restoreGroup((int)$id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_group_restored', (int)$id);
            echo json_encode(['status' => 'success', 'message' => 'Group restored successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to restore group.']);
        }
        exit;
    }

    public function bulkAction() {
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
            exit;
        }

        $this->validateCSRF();

        $action = $_POST['action'] ?? '';
        $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
        $ids = array_values(array_filter($ids));

        if (empty($ids) || $action === '') {
            echo json_encode(['status' => 'error', 'message' => 'No items selected or invalid action']);
            exit;
        }

        $success = false;
        $message = '';

        switch ($action) {
            case 'deactivate':
                $success = $this->model->bulkDeactivate($ids);
                if ($success) {
                    $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_bulk_deactivated', null, ['count' => count($ids)]);
                    $message = count($ids) . ' product(s) deactivated successfully.';
                } else {
                    $message = 'Some products could not be deactivated (they may have active transactions or other restrictions).';
                }
                break;

            case 'restore':
                $success = $this->model->bulkRestore($ids);
                if ($success) {
                    $this->userAudit->log($_SESSION['user_id'] ?? 0, 'product_bulk_restored', null, ['count' => count($ids)]);
                    $message = count($ids) . ' product(s) restored successfully.';
                } else {
                    $message = 'Failed to restore some products.';
                }
                break;

            default:
                $message = 'Invalid bulk action.';
        }

        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        exit;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseProductFormPayload(): ?array
    {
        $name = trim($_POST['product_name'] ?? '');
        if ($name === '') {
            $_SESSION['error'] = 'Product name is required.';
            return null;
        }

        $unit = $_POST['unit'] ?? '';
        if (!$this->model->isValidUnit($unit)) {
            $_SESSION['error'] = 'Invalid unit selected.';
            return null;
        }

        $groupId = (int)($_POST['group_id'] ?? $this->model->getDefaultGroupId());
        if (!$this->model->isActiveGroup($groupId)) {
            $_SESSION['error'] = 'Invalid or inactive product group.';
            return null;
        }

        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        if ($categoryId && !$this->model->isActiveCategory($categoryId)) {
            $_SESSION['error'] = 'Invalid or inactive category.';
            return null;
        }

        return [
            'product_name'   => $name,
            'category_id'    => $categoryId,
            'group_id'       => $groupId,
            'unit'           => $unit,
            'pcs_per_carton' => $_POST['pcs_per_carton'] ?? 0,
            'safety_stock'   => $_POST['safety_stock'] ?? 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     * @return array<int, array<string, mixed>>
     */
    private function enrichAuditLogsWithUserNames(array $logs): array
    {
        if (empty($logs)) {
            return $logs;
        }

        require_once __DIR__ . '/../models/UserModel.php';
        $userModel = new UserModel();

        foreach ($logs as &$log) {
            $uid = (int)($log['performed_by'] ?? 0);
            if ($uid > 0) {
                $user = $userModel->getUserById($uid);
                $log['performed_by_name'] = $user['username'] ?? ('User #' . $uid);
            } else {
                $log['performed_by_name'] = '—';
            }
        }
        unset($log);

        return $logs;
    }
}
