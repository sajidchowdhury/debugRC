<?php
// app/models/ProductModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';

class ProductModel extends Helper {

    protected $db;

    /** @var string[] */
    public const ALLOWED_UNITS = ['Pcs', 'Carton', 'KG', 'Bag', 'Dobe', 'Set'];

    /** System default group (China) — must match migration seed id=1 */
    public const DEFAULT_GROUP_ID = 1;

    public function __construct() {
        $this->db = new Database();
    }

    public function generateProductCode() {
        $this->db->query("SELECT MAX(CAST(SUBSTRING(product_code, 4) AS UNSIGNED)) as last_num FROM products");
        $row = $this->db->single();
        $next = ($row['last_num'] ?? 0) + 1;
        return 'P-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    public function getAll() {
        return $this->Get_All_Active_Product();
    }

    public function getById($id) {
        return $this->Get_Product_By_Id($id);
    }

    public function canDelete($id) {
        $tables = [
            'stock_transactions'       => 'product_id',
            'sales_invoice_items'      => 'product_id',
            'purchase_receive_items'   => 'product_id',
            'branch_demand_items'      => 'product_id',
        ];

        foreach ($tables as $table => $column) {
            $this->db->query("SELECT COUNT(*) as total FROM $table WHERE $column = :id");
            $this->db->bind(':id', $id);
            $count = $this->db->single()['total'] ?? 0;
            if ($count > 0) {
                return false;
            }
        }
        return true;
    }

    public function create($data) {
        $this->db->query("INSERT INTO products 
            (product_code, product_name, category_id, group_id, unit, pcs_per_carton, safety_stock, image, created_by) 
            VALUES (:code, :name, :cat, :grp, :unit, :pcs, :safety, :image, :created_by)");

        $this->db->bind(':code', $data['product_code']);
        $this->db->bind(':name', $data['product_name']);
        $this->db->bind(':cat', !empty($data['category_id']) ? $data['category_id'] : null);
        $this->db->bind(':grp', (int)($data['group_id'] ?? self::DEFAULT_GROUP_ID));
        $this->db->bind(':unit', $data['unit']);
        $this->db->bind(':pcs', $data['pcs_per_carton'] ?? 0);
        $this->db->bind(':safety', $data['safety_stock'] ?? 0);
        $this->db->bind(':image', $data['image'] ?? null);
        $this->db->bind(':created_by', $_SESSION['employee_id'] ?? 1);

        return $this->db->execute();
    }

    public function update($id, $data) {
        $sql = "UPDATE products SET 
            product_name = :name,
            category_id = :cat,
            group_id = :grp,
            unit = :unit,
            pcs_per_carton = :pcs,
            safety_stock = :safety";

        if (isset($data['image'])) {
            $sql .= ", image = :image";
        }

        $sql .= " WHERE id = :id";

        $this->db->query($sql);

        $this->db->bind(':name', $data['product_name']);
        $this->db->bind(':cat', !empty($data['category_id']) ? $data['category_id'] : null);
        $this->db->bind(':grp', (int)($data['group_id'] ?? self::DEFAULT_GROUP_ID));
        $this->db->bind(':unit', $data['unit']);
        $this->db->bind(':pcs', $data['pcs_per_carton'] ?? 0);
        $this->db->bind(':safety', $data['safety_stock'] ?? 0);
        $this->db->bind(':id', $id);

        if (isset($data['image'])) {
            $this->db->bind(':image', $data['image']);
        }

        return $this->db->execute();
    }

    public function delete($id) {
        if (!$this->canDelete($id)) {
            return false;
        }
        $this->db->query("UPDATE products SET is_active = 0 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function restore($id) {
        $this->db->query("UPDATE products SET is_active = 1 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    /**
     * Current effective price range (SSOT).
     *
     * @return array{min_rate: float, max_rate: float, default_rate: float, effective_from: ?string, has_price: bool}|null
     */
    public function getCurrentPrice(int $productId): ?array
    {
        $this->db->query("
            SELECT min_rate, max_rate, default_rate, effective_from
            FROM product_price_history
            WHERE product_id = :pid
            ORDER BY effective_from DESC, created_at DESC, id DESC
            LIMIT 1
        ");
        $this->db->bind(':pid', $productId);
        $row = $this->db->single();

        if (!$row || (float)($row['default_rate'] ?? 0) <= 0) {
            return null;
        }

        return [
            'min_rate'       => (float)$row['min_rate'],
            'max_rate'       => (float)$row['max_rate'],
            'default_rate'   => (float)$row['default_rate'],
            'effective_from' => $row['effective_from'] ?? null,
            'has_price'      => true,
        ];
    }

    public function addPriceHistory(int $product_id, float $min_rate, float $max_rate, float $default_rate): bool
    {
        $this->db->query("INSERT INTO product_price_history 
            (product_id, min_rate, max_rate, default_rate, effective_from, created_by) 
            VALUES (:pid, :min, :max, :def, NOW(), :created_by)");

        $this->db->bind(':pid', $product_id);
        $this->db->bind(':min', $min_rate);
        $this->db->bind(':max', $max_rate);
        $this->db->bind(':def', $default_rate);
        $this->db->bind(':created_by', $_SESSION['employee_id'] ?? 1);
        return $this->db->execute();
    }

    public function getPriceHistory($product_id) {
        $this->db->query("
            SELECT ph.*, e.name AS created_by_name
            FROM product_price_history ph
            LEFT JOIN employees e ON e.id = ph.created_by
            WHERE ph.product_id = :pid 
            ORDER BY ph.effective_from DESC, ph.created_at DESC, ph.id DESC
        ");
        $this->db->bind(':pid', $product_id);
        return $this->db->resultSet();
    }

    /** Append-only — price rows are not deleted. */
    public function deletePriceHistory($price_id): bool
    {
        return false;
    }

    public function getCategories() {
        return $this->Get_All_Categories();
    }

    public function getGroups(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM product_groups";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY group_name";
        $this->db->query($sql);
        return $this->db->resultSet();
    }

    public function getDefaultGroupId(): int
    {
        $this->db->query("SELECT id FROM product_groups WHERE group_name = 'China' AND is_active = 1 LIMIT 1");
        $row = $this->db->single();
        return (int)($row['id'] ?? self::DEFAULT_GROUP_ID);
    }

    public function isValidUnit(string $unit): bool
    {
        return in_array($unit, self::ALLOWED_UNITS, true);
    }

    public function isActiveGroup(int $groupId): bool
    {
        $this->db->query("SELECT id FROM product_groups WHERE id = :id AND is_active = 1");
        $this->db->bind(':id', $groupId);
        return (bool)$this->db->single();
    }

    public function isActiveCategory(?int $categoryId): bool
    {
        if (!$categoryId) {
            return true;
        }
        $this->db->query("SELECT id FROM product_categories WHERE id = :id AND is_active = 1");
        $this->db->bind(':id', $categoryId);
        return (bool)$this->db->single();
    }

    public function uploadProductImage(array $file): ?string
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$mime])) {
            return null;
        }

        $uploadDir = __DIR__ . '/../../public/uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = $allowedMimes[$mime];
        $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $imgInfo = @getimagesize($destination);
            if ($imgInfo === false) {
                @unlink($destination);
                return null;
            }
            return 'uploads/products/' . $filename;
        }

        return null;
    }

    public function deleteProductImage(?string $imagePath): void
    {
        if (!$imagePath) {
            return;
        }

        $fullPath = __DIR__ . '/../../public/' . $imagePath;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }

    public function getProductIndexStats(): array
    {
        $stats = [
            'active'     => 0,
            'inactive'   => 0,
            'categories' => 0,
            'groups'     => 0,
            'with_stock' => 0,
        ];

        $this->db->query('SELECT COUNT(*) AS c FROM products WHERE is_active = 1');
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM products WHERE is_active = 0');
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM product_categories WHERE is_active = 1');
        $stats['categories'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM product_groups WHERE is_active = 1');
        $stats['groups'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('
            SELECT COUNT(DISTINCT ws.product_id) AS c
            FROM warehouse_stock ws
            INNER JOIN products p ON p.id = ws.product_id AND p.is_active = 1
            WHERE ws.qty > 0.0001
        ');
        $stats['with_stock'] = (int)($this->db->single()['c'] ?? 0);

        return $stats;
    }

    public function getProductStockAndPrice(int $productId): array
    {
        $this->db->query('
            SELECT COALESCE((SELECT SUM(ws.qty) FROM warehouse_stock ws WHERE ws.product_id = :id), 0) AS total_stock
        ');
        $this->db->bind(':id', $productId);
        $row = $this->db->single() ?: [];
        $price = $this->getCurrentPrice($productId);

        return [
            'total_stock'    => (float)($row['total_stock'] ?? 0),
            'current_price'  => (float)($price['default_rate'] ?? 0),
            'min_rate'       => (float)($price['min_rate'] ?? 0),
            'max_rate'       => (float)($price['max_rate'] ?? 0),
            'default_rate'   => (float)($price['default_rate'] ?? 0),
            'has_price'      => $price !== null,
        ];
    }

    public function getCategoryIndexStats(): array
    {
        $this->db->query('SELECT COUNT(*) AS c FROM product_categories WHERE is_active = 1');
        $active = (int)($this->db->single()['c'] ?? 0);
        $this->db->query('SELECT COUNT(*) AS c FROM product_categories WHERE is_active = 0');
        $inactive = (int)($this->db->single()['c'] ?? 0);
        $this->db->query('SELECT COUNT(*) AS c FROM products WHERE is_active = 1');
        $products = (int)($this->db->single()['c'] ?? 0);

        return ['active' => $active, 'inactive' => $inactive, 'products' => $products];
    }

    public function getGroupIndexStats(): array
    {
        $this->db->query('SELECT COUNT(*) AS c FROM product_groups WHERE is_active = 1');
        $active = (int)($this->db->single()['c'] ?? 0);
        $this->db->query('SELECT COUNT(*) AS c FROM product_groups WHERE is_active = 0');
        $inactive = (int)($this->db->single()['c'] ?? 0);
        $this->db->query('SELECT COUNT(*) AS c FROM products WHERE is_active = 1');
        $products = (int)($this->db->single()['c'] ?? 0);

        return ['active' => $active, 'inactive' => $inactive, 'products' => $products];
    }

    private function sanitizeOrderDir(string $dir): string
    {
        return strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
    }

    public function getProductsForDataTable(array $params): array
    {
        $start          = (int)($params['start'] ?? 0);
        $length         = (int)($params['length'] ?? 25);
        $searchValue    = trim($params['search']['value'] ?? '');
        $orderColumn    = (int)($params['order'][0]['column'] ?? 0);
        $orderDir       = $this->sanitizeOrderDir($params['order'][0]['dir'] ?? 'asc');

        $filterCategory = $params['filterCategory'] ?? '';
        $filterUnit     = $params['filterUnit'] ?? '';
        $filterGroup    = $params['filterGroup'] ?? '';
        $includeDeleted = !empty($params['includeDeleted']);

        $columns = [
            'p.product_code',
            'p.product_name',
            'g.group_name',
            'c.category_name',
            'p.unit',
            'total_stock',
            'current_default_rate',
        ];

        $baseQuery = "
            FROM products p
            LEFT JOIN product_categories c ON p.category_id = c.id
            LEFT JOIN product_groups g ON p.group_id = g.id
        ";

        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "p.is_active = 1";
        }

        if ($searchValue !== '') {
            $where[] = "(p.product_name LIKE :search OR p.product_code LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        if ($filterCategory) {
            $where[] = "p.category_id = :category";
            $bindParams[':category'] = $filterCategory;
        }

        if ($filterGroup) {
            $where[] = "p.group_id = :grp";
            $bindParams[':grp'] = $filterGroup;
        }

        if ($filterUnit) {
            $where[] = "p.unit = :unit";
            $bindParams[':unit'] = $filterUnit;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalQuery = "SELECT COUNT(p.id) as total {$baseQuery}";
        if (!$includeDeleted) {
            $totalQuery .= " WHERE p.is_active = 1";
        }
        $this->db->query($totalQuery);
        $recordsTotal = $this->db->single()['total'] ?? 0;

        $filteredQuery = "SELECT COUNT(p.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = $this->db->single()['total'] ?? 0;

        $orderBy = $columns[$orderColumn] ?? 'p.product_name';

        $latestPriceSub = "
            SELECT ph2.min_rate, ph2.max_rate, ph2.default_rate
            FROM product_price_history ph2
            WHERE ph2.product_id = p.id
            ORDER BY ph2.effective_from DESC, ph2.created_at DESC, ph2.id DESC
            LIMIT 1
        ";

        $dataQuery = "
            SELECT 
                p.id, 
                p.product_code, 
                p.product_name, 
                p.unit, 
                p.is_active,
                p.image,
                c.category_name,
                g.group_name,
                COALESCE((
                    SELECT SUM(ws.qty) FROM warehouse_stock ws WHERE ws.product_id = p.id
                ), 0) AS total_stock,
                COALESCE((
                    SELECT lp.default_rate FROM product_price_history lp
                    WHERE lp.product_id = p.id
                    ORDER BY lp.effective_from DESC, lp.created_at DESC, lp.id DESC
                    LIMIT 1
                ), 0) AS current_price,
                COALESCE((
                    SELECT lp.default_rate FROM product_price_history lp
                    WHERE lp.product_id = p.id
                    ORDER BY lp.effective_from DESC, lp.created_at DESC, lp.id DESC
                    LIMIT 1
                ), 0) AS current_default_rate,
                COALESCE((
                    SELECT lp.min_rate FROM product_price_history lp
                    WHERE lp.product_id = p.id
                    ORDER BY lp.effective_from DESC, lp.created_at DESC, lp.id DESC
                    LIMIT 1
                ), 0) AS min_rate,
                COALESCE((
                    SELECT lp.max_rate FROM product_price_history lp
                    WHERE lp.product_id = p.id
                    ORDER BY lp.effective_from DESC, lp.created_at DESC, lp.id DESC
                    LIMIT 1
                ), 0) AS max_rate
            {$baseQuery}
            {$whereSql}
            ORDER BY {$orderBy} {$orderDir}
            LIMIT {$start}, {$length}
        ";

        $this->db->query($dataQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $data = $this->db->resultSet();

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ];
    }

    // =====================================================
    // CATEGORY MANAGEMENT
    // =====================================================

    public function getCategoriesForDataTable(array $params): array
    {
        $start       = (int)($params['start'] ?? 0);
        $length      = (int)($params['length'] ?? 25);
        $searchValue = trim($params['search']['value'] ?? '');
        $orderColumn = (int)($params['order'][0]['column'] ?? 0);
        $orderDir    = $this->sanitizeOrderDir($params['order'][0]['dir'] ?? 'asc');
        $includeDeleted = !empty($params['includeDeleted']);

        $columns = ['c.category_name'];
        $baseQuery = "FROM product_categories c";
        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "c.is_active = 1";
        }

        if ($searchValue !== '') {
            $where[] = "c.category_name LIKE :search";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalQuery = "SELECT COUNT(c.id) as total {$baseQuery}";
        if (!$includeDeleted) {
            $totalQuery .= " WHERE c.is_active = 1";
        }
        $this->db->query($totalQuery);
        $recordsTotal = $this->db->single()['total'] ?? 0;

        $filteredQuery = "SELECT COUNT(c.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = $this->db->single()['total'] ?? 0;

        $orderBy = $columns[$orderColumn] ?? 'c.category_name';
        $dataQuery = "
            SELECT c.id, c.category_name, c.is_active,
                (SELECT COUNT(*) FROM products WHERE category_id = c.id AND is_active = 1) AS product_count
            {$baseQuery} {$whereSql}
            ORDER BY {$orderBy} {$orderDir}
            LIMIT {$start}, {$length}
        ";

        $this->db->query($dataQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $this->db->resultSet(),
        ];
    }

    public function createCategory($name) {
        $this->db->query("INSERT INTO product_categories (category_name, is_active) VALUES (:name, 1)");
        $this->db->bind(':name', trim($name));
        return $this->db->execute();
    }

    public function updateCategory($id, $name) {
        $this->db->query("UPDATE product_categories SET category_name = :name WHERE id = :id");
        $this->db->bind(':name', trim($name));
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function softDeleteCategory($id) {
        if (!$this->canDeleteCategory($id)) {
            return false;
        }
        $this->db->query("UPDATE product_categories SET is_active = 0 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function restoreCategory($id) {
        $this->db->query("UPDATE product_categories SET is_active = 1 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function canDeleteCategory($id) {
        $this->db->query("SELECT COUNT(*) as total FROM products WHERE category_id = :id AND is_active = 1");
        $this->db->bind(':id', $id);
        return ($this->db->single()['total'] ?? 0) == 0;
    }

    public function getCategoryById($id) {
        $this->db->query("SELECT * FROM product_categories WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    // =====================================================
    // PRODUCT GROUP MANAGEMENT
    // =====================================================

    public function getGroupsForDataTable(array $params): array
    {
        $start       = (int)($params['start'] ?? 0);
        $length      = (int)($params['length'] ?? 25);
        $searchValue = trim($params['search']['value'] ?? '');
        $orderColumn = (int)($params['order'][0]['column'] ?? 0);
        $orderDir    = $this->sanitizeOrderDir($params['order'][0]['dir'] ?? 'asc');
        $includeDeleted = !empty($params['includeDeleted']);

        $columns = ['g.group_name'];
        $baseQuery = "FROM product_groups g";
        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "g.is_active = 1";
        }

        if ($searchValue !== '') {
            $where[] = "g.group_name LIKE :search";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalQuery = "SELECT COUNT(g.id) AS total {$baseQuery}";
        if (!$includeDeleted) {
            $totalQuery .= " WHERE g.is_active = 1";
        }
        $this->db->query($totalQuery);
        $recordsTotal = $this->db->single()['total'] ?? 0;

        $filteredQuery = "SELECT COUNT(g.id) AS total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = $this->db->single()['total'] ?? 0;

        $orderBy = $columns[$orderColumn] ?? 'g.group_name';
        $dataQuery = "
            SELECT g.id, g.group_name, g.is_active,
                (SELECT COUNT(*) FROM products WHERE group_id = g.id AND is_active = 1) AS product_count
            {$baseQuery} {$whereSql}
            ORDER BY {$orderBy} {$orderDir}
            LIMIT {$start}, {$length}
        ";

        $this->db->query($dataQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $this->db->resultSet(),
        ];
    }

    public function createGroup(string $name): bool
    {
        $this->db->query("INSERT INTO product_groups (group_name, is_active) VALUES (:name, 1)");
        $this->db->bind(':name', trim($name));
        return $this->db->execute();
    }

    public function updateGroup(int $id, string $name): bool
    {
        $this->db->query("UPDATE product_groups SET group_name = :name WHERE id = :id");
        $this->db->bind(':name', trim($name));
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function softDeleteGroup(int $id): bool
    {
        if ($id === self::DEFAULT_GROUP_ID || $id === $this->getDefaultGroupId()) {
            return false;
        }
        if (!$this->canDeleteGroup($id)) {
            return false;
        }
        $this->db->query("UPDATE product_groups SET is_active = 0 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function restoreGroup(int $id): bool
    {
        $this->db->query("UPDATE product_groups SET is_active = 1 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function canDeleteGroup(int $id): bool
    {
        $this->db->query("SELECT COUNT(*) AS total FROM products WHERE group_id = :id AND is_active = 1");
        $this->db->bind(':id', $id);
        return ($this->db->single()['total'] ?? 0) == 0;
    }

    public function getGroupById(int $id): ?array
    {
        $this->db->query("SELECT * FROM product_groups WHERE id = :id");
        $this->db->bind(':id', $id);
        $row = $this->db->single();
        return $row ?: null;
    }

    public function countActiveProductsWithoutPrice(): int
    {
        $this->db->query("
            SELECT COUNT(*) AS c FROM products p
            WHERE p.is_active = 1
              AND NOT EXISTS (
                SELECT 1 FROM product_price_history ph
                WHERE ph.product_id = p.id AND ph.default_rate > 0
              )
        ");
        return (int)($this->db->single()['c'] ?? 0);
    }

    public function countActiveProductsWithoutGroup(): int
    {
        $this->db->query("
            SELECT COUNT(*) AS c FROM products p
            WHERE p.is_active = 1 AND (p.group_id IS NULL OR p.group_id = 0)
        ");
        return (int)($this->db->single()['c'] ?? 0);
    }

    // =====================================================
    // BULK ACTIONS
    // =====================================================

    public function bulkDeactivate(array $ids) {
        if (empty($ids)) {
            return false;
        }

        $deletableIds = [];
        foreach ($ids as $id) {
            if ($this->canDelete($id)) {
                $deletableIds[] = (int)$id;
            }
        }

        if (empty($deletableIds)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($deletableIds), '?'));
        $this->db->query("UPDATE products SET is_active = 0 WHERE id IN ($placeholders)");

        foreach ($deletableIds as $i => $id) {
            $this->db->bind($i, $id);
        }

        return $this->db->execute();
    }

    public function bulkRestore(array $ids) {
        if (empty($ids)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->query("UPDATE products SET is_active = 1 WHERE id IN ($placeholders)");

        foreach ($ids as $i => $id) {
            $this->db->bind($i, (int)$id);
        }

        return $this->db->execute();
    }
}
