<?php
// app/models/Helper.php

require_once __DIR__ . '/../../core/Database.php';

 class Helper {

    protected $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? new Database();
    }

    public function setDatabase(Database $db): void
    {
        $this->db = $db;
    }

    public function getDatabase(): Database
    {
        return $this->db;
    }

    // ================= COMMON REUSABLE METHODS =================

    
     /** EmployeeModel */
    public function All_Active_Employees() {
        $this->db->query("
            SELECT e.*, b.branch_name 
        FROM employees e
        LEFT JOIN branches b ON e.branch_id = b.id
        WHERE e.is_active = 1 
        ORDER BY e.name ASC
        ");
        return $this->db->resultSet();
    }

    public function All_Employees(bool $includeDeleted = false) {
        $sql = "
            SELECT e.*, b.branch_name 
            FROM employees e
            LEFT JOIN branches b ON e.branch_id = b.id
        ";

        if (!$includeDeleted) {
            $sql .= " WHERE e.deleted_at IS NULL";
        }

        $sql .= " ORDER BY e.name ASC";

        $this->db->query($sql);
        return $this->db->resultSet();
    }


    public function Get_Employee_By_Id($id) {
        $this->db->query("
            SELECT e.*, b.branch_name 
            FROM employees e
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE e.id = :id
        ");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

        public function Get_Employees_By_Branch($branch_id) {
        $this->db->query("
            SELECT id, employee_code, name 
            FROM employees 
            WHERE branch_id = :branch_id AND is_active = 1 
            ORDER BY name ASC
        ");
        $this->db->bind(':branch_id', $branch_id);
        return $this->db->resultSet();
    }

        public function Get_Employee_By_Role($role) { 
        
        $this->db->query("
            SELECT id, name FROM employees 
            WHERE role =  :role AND is_active = 1 
            ORDER BY name
        ");
        $this->db->bind(':role', $role);
        return $this->db->resultSet();
     }



    /** CustomerModel */

 public function Get_All_Customers() {
        $this->db->query("
            SELECT c.*, e.name as sales_person_name 
            FROM customers c
            LEFT JOIN employees e ON c.sales_person_id = e.id
            ORDER BY c.shop_name ASC
        ");
        return $this->db->resultSet();
    }

     public function Get_All_Active_Customers() {
        $this->db->query("
            SELECT c.*, e.name as sales_person_name 
            FROM customers c
            LEFT JOIN employees e ON c.sales_person_id = e.id
            WHERE C.is_active = 1
            ORDER BY c.shop_name ASC
        ");
        return $this->db->resultSet();
    }


    public function Get_Customer_By_Id($id) {
        $this->db->query("
            SELECT c.*, e.name as sales_person_name 
            FROM customers c
            LEFT JOIN employees e ON c.sales_person_id = e.id
            WHERE c.id = :id
        ");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }


                /** UserModel */

        public function get_All_Users(bool $includeDeleted = false) {
            $sql = "
                SELECT u.*, e.name as employee_name, e.employee_code, e.is_active as employee_active,
                        b.branch_name
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                LEFT JOIN branches b ON e.branch_id = b.id
            ";

            if (!$includeDeleted) {
                $sql .= " WHERE u.deleted_at IS NULL";
            }

            $sql .= " ORDER BY e.name ASC";

            $this->db->query($sql);
            return $this->db->resultSet();
        }

    public function get_User_By_Id($id) {
        $this->db->query("
            SELECT u.*, e.name as employee_name, e.employee_code 
            FROM users u
            JOIN employees e ON u.employee_id = e.id
            WHERE u.id = :id
        ");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }


   /** BranchModel */

  public function Get_All_Branches() {
        $this->db->query("
            SELECT * FROM branches 
            ORDER BY branch_name ASC
        ");
        return $this->db->resultSet();
    }

    
public function Get_All_Active_Branches() {
    $this->db->query("SELECT id, branch_name FROM branches 
                      WHERE is_active = 1 
                      ORDER BY branch_name ASC");
    return $this->db->resultSet();
}

public function Get_Branch_By_Id($id) {
        $this->db->query("SELECT * FROM branches WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    // ================= BRANCH SCOPE (Sales Phase 3) =================

    public static function sessionBranchId(): int
    {
        return (int)($_SESSION['branch_id'] ?? 1);
    }

    public function canOverrideBranch(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    /** Admin/manager see all invoices for their branch on Today list; others see own only. */
    public function canSeeAllBranchInvoices(): bool
    {
        $role = $_SESSION['role'] ?? '';
        return in_array($role, ['admin', 'manager'], true);
    }

    public function isActiveBranch(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        $this->db->query("SELECT id FROM branches WHERE id = :id AND is_active = 1");
        $this->db->bind(':id', $branchId);
        return (bool)$this->db->single();
    }

    /**
     * Branch used when saving sales documents.
     * Non-admins: always session branch (or locked invoice branch on edit).
     * Admins: may choose another active branch on create.
     */
    public function resolveBranchIdForWrite(?int $requestedBranchId = null, ?int $lockedInvoiceBranchId = null): int
    {
        $sessionBranch = self::sessionBranchId();

        if ($lockedInvoiceBranchId !== null && !$this->canOverrideBranch()) {
            return (int)$lockedInvoiceBranchId;
        }

        if (!$this->canOverrideBranch()) {
            return $sessionBranch;
        }

        if ($requestedBranchId > 0 && $this->isActiveBranch($requestedBranchId)) {
            return $requestedBranchId;
        }

        return $lockedInvoiceBranchId ?? $sessionBranch;
    }

    public function assertInvoiceAccessible(int $invoiceBranchId): void
    {
        if ($this->canOverrideBranch()) {
            return;
        }
        if ((int)$invoiceBranchId !== self::sessionBranchId()) {
            throw new Exception('You do not have access to invoices from another branch.');
        }
    }

    public function warehouseBelongsToBranch(int $warehouseId, int $branchId): bool
    {
        if ($warehouseId <= 0 || $branchId <= 0) {
            return false;
        }
        $this->db->query("
            SELECT id FROM warehouses
            WHERE id = :wid AND branch_id = :bid AND is_active = 1
        ");
        $this->db->bind(':wid', $warehouseId);
        $this->db->bind(':bid', $branchId);
        return (bool)$this->db->single();
    }

/** ProductModel */





 public function Get_All_Product() {
        $this->db->query("SELECT p.*, c.category_name 
                          FROM products p 
                          LEFT JOIN product_categories c ON p.category_id = c.id 
                          ORDER BY p.product_code");
        return $this->db->resultSet();
    }
    
    public function Get_All_Active_Product() {
        $this->db->query("SELECT p.*, c.category_name 
                          FROM products p 
                          LEFT JOIN product_categories c ON p.category_id = c.id 
                          WHERE p.is_active = 1 
                          ORDER BY p.product_code");
        return $this->db->resultSet();
    }

    public function Get_Product_By_Id($id) {
        $this->db->query("SELECT * FROM products WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    public function Get_Active_Categories() {
        $this->db->query("SELECT * FROM product_categories WHERE is_active = 1 ORDER BY category_name");
        return $this->db->resultSet();
    }

        public function Get_All_Categories() {
        $this->db->query("SELECT * FROM product_categories  ORDER BY category_name");
        return $this->db->resultSet();
    }

 public function Product_Price_Now($id) {
    require_once __DIR__ . '/../models/ProductModel.php';
    $model = new ProductModel();
    $price = $model->getCurrentPrice((int)$id);

    if (!$price) {
        return ['price' => null, 'min_rate' => null, 'max_rate' => null, 'default_rate' => null];
    }

    return [
        'price'         => $price['default_rate'],
        'min_rate'      => $price['min_rate'],
        'max_rate'      => $price['max_rate'],
        'default_rate'  => $price['default_rate'],
    ];
}



/** WarehouseModel */

 public function Get_All_Warehouses() {
        $this->db->query("
            SELECT w.*, b.branch_name 
            FROM warehouses w
            LEFT JOIN branches b ON w.branch_id = b.id
            ORDER BY b.branch_name ASC, w.warehouse_name ASC
        ");
        return $this->db->resultSet();
    }

     public function Get_All_Active_Warehouses() {
        $this->db->query("
            SELECT w.*, b.branch_name 
            FROM warehouses w
            LEFT JOIN branches b ON w.branch_id = b.id
            where w.is_active = 1
            ORDER BY b.branch_name ASC, w.warehouse_name ASC
        ");
        return $this->db->resultSet();
    }

    public function Get_Warehouse_By_Branch($branch_id) {
        $this->db->query("
            SELECT *
            FROM warehouses 
            WHERE branch_id = :branch_id AND is_active = 1
            ORDER BY warehouse_name ASC
        ");
        $this->db->bind(':branch_id', $branch_id);
        return $this->db->resultSet();
    }

    public function Get_Warehouse_By_Id($id) {
        $this->db->query("
            SELECT w.*, b.branch_name 
            FROM warehouses w
            LEFT JOIN branches b ON w.branch_id = b.id
            WHERE w.id = :id
        ");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

 
/** SupplierModel */


  public function Get_All_Supplier() {
        $this->db->query("
            SELECT * FROM suppliers 
            ORDER BY supplier_name ASC
        ");
        return $this->db->resultSet();
    }

        public function Get_All_Active_Supplier() {
        $this->db->query("SELECT id, supplier_code, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");
        return $this->db->resultSet();
    }


    public function Get_Supplier_By_Id($id) {
        $this->db->query("SELECT * FROM suppliers WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }


   /** BankModel*/

    public function Get_All_Active_Bank() {
        $this->db->query("SELECT id, bank_name, account_number, branch_name 
                          FROM banks 
                          WHERE is_active = 1 
                          ORDER BY bank_name");
        return $this->db->resultSet();
    }

    public function Get_Bank_By_Id($id) {
        $this->db->query("SELECT * FROM banks WHERE id = :id AND is_active = 1");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }


    public function Get_All_Bank() {
        $this->db->query("SELECT * FROM banks ORDER BY bank_name");
        return $this->db->resultSet();
    }


  /** ledgerModel*/
    
   public function Get_All_Ledger() {
        $this->db->query("
            SELECT l.*, p.ledger_name as parent_name
            FROM ledgers l
            LEFT JOIN ledgers p ON l.parent_id = p.id
            ORDER BY l.parent_id ASC, l.sort_order ASC, l.ledger_name ASC
        ");
        return $this->db->resultSet();
    }


        public function Get_Ledger_By_Type($type) {
        $this->db->query("
            SELECT id, ledger_code, ledger_name 
            FROM ledgers 
            WHERE account_type = :type AND is_active = 1 
            ORDER BY ledger_name ASC
        ");
         $this->db->bind(':type', $type);
        return $this->db->resultSet();
    }


    public function Get_All_Ledger_By_Id($id) {
        $this->db->query("SELECT * FROM ledgers WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }


    public function Customer_Due_Break_Down($customer_id, $invoice_id = null) { 

        if (!$customer_id) {
            return [
                'previous_due'         => 0,
                'this_invoice_gross'   => 0,
                'this_invoice_payment' => 0,
                'this_invoice_net'     => 0,
                'cumulative_due'       => 0,
                'current_due'          => 0
            ];
        }

        // 1. This invoice gross amount
        $thisInvoiceGross = 0;
        if ($invoice_id) {
            $this->db->query("SELECT total_amount FROM sales_invoices WHERE id = :id and is_reversed = 0 ");
            $this->db->bind(':id', $invoice_id);
            $thisInvoiceGross = $this->db->single()['total_amount'] ?? 0;
        }

        // 2. This invoice payment (from allocation table)
        $thisInvoicePayment = 0;
        if ($invoice_id) {
            $this->db->query("SELECT COALESCE(SUM(allocated_amount), 0) as paid 
                              FROM invoice_payment_allocations 
                              WHERE invoice_id = :invoice_id");
            $this->db->bind(':invoice_id', $invoice_id);
            $thisInvoicePayment = $this->db->single()['paid'] ?? 0;
        }

        $thisInvoiceNet = $thisInvoiceGross - $thisInvoicePayment;

        $prevDue = $this->Customer_This_Invoice_Previous_Due($customer_id, $invoice_id);

        // 4. Cumulative due up to this invoice
        $cumulativeDue = $prevDue + $thisInvoiceNet;

        // 5. Overall current due (for reference)
        $currentDue = $this->Get_Customer_Now_Due($customer_id);

        return [
            'previous_due'         => $prevDue,
            'this_invoice_gross'   => $thisInvoiceGross,
            'this_invoice_payment' => $thisInvoicePayment,
            'this_invoice_net'     => $thisInvoiceNet,
            'cumulative_due'       => $cumulativeDue,     // ← This is "মোট বকেয়া"
            'current_due'          => $currentDue
        ];
     }

    public function Get_Customer_Due($customer_id) {

        $this->db->query("SELECT COALESCE(SUM(CASE WHEN debit > 0 THEN debit ELSE -credit END), 0) as due 
                          FROM customer_ledger WHERE customer_id = :id");
        $this->db->bind(':id', $customer_id);
        return  $this->db->single();
    }

        public function Get_Customer_Now_Due($customer_id) {

     $this->db->query("
            SELECT COALESCE(running_balance, 0) as due_balance
            FROM customer_ledger 
            WHERE customer_id = :customer_id 
            ORDER BY id DESC LIMIT 1
        ");
        $this->db->bind(':customer_id', $customer_id);
        $row = $this->db->single();
        return (float)($row['due_balance'] ?? 0);
    }

    public function Get_Supplier_Due($supplier_id) {
        $this->db->query("SELECT COALESCE(SUM(CASE WHEN debit > 0 THEN debit ELSE -credit END), 0) as due 
                          FROM supplier_ledger WHERE supplier_id = :id");
        $this->db->bind(':id', $supplier_id);
        return $this->db->single();
    }

    public function Get_Supplier_Now_Due($supplier_id) {
        if (!$supplier_id) {
            return 0.0;
        }
        $this->db->query("
            SELECT COALESCE(running_balance, 0) as due_balance
            FROM supplier_ledger 
            WHERE supplier_id = :supplier_id 
            ORDER BY id DESC LIMIT 1
        ");
        $this->db->bind(':supplier_id', $supplier_id);
        $row = $this->db->single();
        return (float)($row['due_balance'] ?? 0);
    }

     function Customer_This_Invoice_Previous_Due($customer_id, $invoice_id){

        if (!$customer_id || !$invoice_id) {
            return 0.0;
        }

        $this->db->query("
            SELECT invoice_date, created_at
            FROM sales_invoices
            WHERE id = :id AND is_reversed = 0
        ");
        $this->db->bind(':id', $invoice_id);
        $current = $this->db->single();
        if (!$current) {
            return 0.0;
        }

        $this->db->query("
            SELECT COALESCE(SUM(
                si.total_amount - COALESCE(alloc.total_paid, 0)
            ), 0) AS previous_due
            FROM sales_invoices si
            LEFT JOIN (
                SELECT invoice_id, SUM(allocated_amount) AS total_paid
                FROM invoice_payment_allocations
                GROUP BY invoice_id
            ) alloc ON si.id = alloc.invoice_id
            WHERE si.customer_id = :cid
              AND si.is_reversed = 0
              AND (
                si.invoice_date < :inv_date
                OR (
                    si.invoice_date = :inv_date2
                    AND si.created_at < :created_at
                )
                OR (
                    si.invoice_date = :inv_date3
                    AND si.created_at = :created_at2
                    AND si.id < :current_invoice_id
                )
              )
        ");
        $this->db->bind(':cid', $customer_id);
        $this->db->bind(':inv_date', $current['invoice_date']);
        $this->db->bind(':inv_date2', $current['invoice_date']);
        $this->db->bind(':inv_date3', $current['invoice_date']);
        $this->db->bind(':created_at', $current['created_at']);
        $this->db->bind(':created_at2', $current['created_at']);
        $this->db->bind(':current_invoice_id', $invoice_id);

        return (float)($this->db->single()['previous_due'] ?? 0);


     }

/** search */

    public function Search_Customers($term) {
        $this->db->query("SELECT id, customer_code, customer_name, shop_name, mobile, credit_limit
                          FROM customers
                          WHERE (customer_name LIKE :term OR shop_name LIKE :term OR mobile LIKE :term OR customer_code LIKE :term)
                            AND is_active = 1
                          ORDER BY shop_name ASC
                          LIMIT 20");
        $this->db->bind(':term', "%$term%");
        return $this->db->resultSet();
    }

    public function Search_Suppliers($term) {
        $this->db->query("SELECT id, supplier_code, supplier_name, mobile, address
                          FROM suppliers
                          WHERE (supplier_name LIKE :term OR mobile LIKE :term OR supplier_code LIKE :term OR address LIKE :term)
                            AND is_active = 1
                          ORDER BY supplier_name ASC
                          LIMIT 20");
        $this->db->bind(':term', "%$term%");
        return $this->db->resultSet();
    }

 public function Search_Product_With_Stock($term, $branch_id) {
        require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
        $svc = new StockAvailabilityService($this->db);

        return $svc->searchProductsWithStock($term, (int)$branch_id);
    }

    /**
     * Barcode / scanner — exact product_code lookup with branch stock.
     *
     * @return array<string, mixed>|null
     */
    public function Search_Product_By_Exact_Code($code, $branch_id)
    {
        require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
        $svc = new StockAvailabilityService($this->db);

        return $svc->findProductByExactCode((string)$code, (int)$branch_id);
    }


/** STOCK  */

// Add at the end of Helper.php (before last })

/**
 * Get Accurate Current Stock with Pending Transactions Considered
 */
public function Get_Product_Stock_Balance($product_id, $branch_id = null, $warehouse_id = null)
{
    require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
    $svc = new StockAvailabilityService($this->db);

    if ($warehouse_id) {
        $physical = 0.0;
        $this->db->query("
            SELECT COALESCE(qty, 0) AS qty FROM warehouse_stock
            WHERE product_id = :pid AND warehouse_id = :wid
        ");
        $this->db->bind(':pid', $product_id);
        $this->db->bind(':wid', $warehouse_id);
        $physical = (float)($this->db->single()['qty'] ?? 0);
        $available = $svc->getWarehouseAvailableQty((int)$product_id, (int)$warehouse_id);
        $pending = max(0.0, $physical - $available);

        return [
            'physical'    => $physical,
            'pending_out' => $pending,
            'available'   => $available,
        ];
    }

    $branch_id = $branch_id ? (int)$branch_id : (int)($_SESSION['branch_id'] ?? 1);
    $summary = $svc->getBranchStockSummary((int)$product_id, $branch_id);

    return [
        'physical'    => $summary['physical_qty'],
        'pending_out' => $summary['pipeline_qty'],
        'available'   => $summary['available_qty'],
    ];
}




public function Get_Warehouse_Wise_Product_Stock($product_id, $branch_id) {
    require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
    $svc = new StockAvailabilityService($this->db);

    return $svc->getWarehouseWiseStock((int)$product_id, (int)$branch_id);
}

     public function Get_Product_Total_Available_Stock($product_id, $branch_id = null)
{
    require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
    $svc = new StockAvailabilityService($this->db);

    return $svc->getBranchAvailableQty((int)$product_id, $branch_id ? (int)$branch_id : null);
}

/**
 * Available qty in one warehouse (physical minus pending dispatches on open invoices).
 *
 * @param int      $product_id
 * @param int      $warehouse_id
 * @param int|null $exclude_invoice_id Exclude this invoice's pending rows (godown re-prep)
 */
public function Get_Warehouse_Available_Stock($product_id, $warehouse_id, $exclude_invoice_id = null)
{
    require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
    $svc = new StockAvailabilityService($this->db);

    return $svc->getWarehouseAvailableQty((int)$product_id, (int)$warehouse_id, $exclude_invoice_id ? (int)$exclude_invoice_id : null);
}

/**
 * Fail when outbound qty exceeds warehouse available (physical minus sales pipeline).
 *
 * @param array<int, array{product_id:int, warehouse_id:int, qty:float}> $lines
 */
public function Assert_Warehouse_Lines_Available(array $lines, ?int $exclude_invoice_id = null): void
{
    $needed = [];
    foreach ($lines as $line) {
        $productId = (int)($line['product_id'] ?? 0);
        $warehouseId = (int)($line['warehouse_id'] ?? 0);
        $qty = (float)($line['qty'] ?? 0);
        if ($productId <= 0 || $warehouseId <= 0 || $qty <= 0) {
            continue;
        }
        $key = $warehouseId . ':' . $productId;
        $needed[$key] = [
            'product_id'   => $productId,
            'warehouse_id' => $warehouseId,
            'qty'          => ($needed[$key]['qty'] ?? 0) + $qty,
        ];
    }

    foreach ($needed as $row) {
        $this->Assert_Warehouse_Stock_Available(
            (int)$row['product_id'],
            (int)$row['warehouse_id'],
            (float)$row['qty'],
            $exclude_invoice_id
        );
    }
}

public function Assert_Warehouse_Stock_Available(
    int $product_id,
    int $warehouse_id,
    float $requested_qty,
    ?int $exclude_invoice_id = null
): void {
    if ($requested_qty <= 0) {
        return;
    }

    $available = $this->Get_Warehouse_Available_Stock($product_id, $warehouse_id, $exclude_invoice_id);
    if ($requested_qty > $available + 0.0001) {
        $balance = $this->Get_Product_Stock_Balance($product_id, null, $warehouse_id);
        throw new Exception(
            'Insufficient available stock for product #' . $product_id
            . ': requested ' . number_format($requested_qty, 2)
            . ', available ' . number_format($available, 2)
            . ' (physical ' . number_format((float)($balance['physical'] ?? 0), 2)
            . ', sales pipeline ' . number_format((float)($balance['pending_out'] ?? 0), 2) . ').'
        );
    }
}

/**
 * Allocate next sequence inside an open DB transaction (SELECT … FOR UPDATE).
 * Document codes (invoice, payment, challan, return) are globally unique — not per branch.
 *
 * @param int $branchId Ignored (kept for backward-compatible call sites).
 */
public function allocateDocumentSequence(string $docType, string $periodKey, int $branchId = 0): int
{
    unset($branchId);
    $globalBranchId = 0;

    if ($this->documentSequencesHaveBranchColumn()) {
        $this->db->query("
            SELECT last_number FROM document_sequences
            WHERE doc_type = :type AND period_key = :period AND branch_id = :bid
            FOR UPDATE
        ");
        $this->db->bind(':type', $docType);
        $this->db->bind(':period', $periodKey);
        $this->db->bind(':bid', $globalBranchId);
        $row = $this->db->single();

        if (!$row) {
            $this->db->query("
                INSERT INTO document_sequences (doc_type, branch_id, period_key, last_number)
                VALUES (:type, :bid, :period, 1)
            ");
            $this->db->bind(':type', $docType);
            $this->db->bind(':bid', $globalBranchId);
            $this->db->bind(':period', $periodKey);
            $this->db->execute();
            return 1;
        }

        $next = (int)$row['last_number'] + 1;
        $this->db->query("
            UPDATE document_sequences SET last_number = :num
            WHERE doc_type = :type AND period_key = :period AND branch_id = :bid
        ");
        $this->db->bind(':num', $next);
        $this->db->bind(':type', $docType);
        $this->db->bind(':period', $periodKey);
        $this->db->bind(':bid', $globalBranchId);
        $this->db->execute();

        return $next;
    }

    // Legacy schema: no branch_id column (run migration 015)
    $this->db->query("
        SELECT last_number FROM document_sequences
        WHERE doc_type = :type AND period_key = :period
        FOR UPDATE
    ");
    $this->db->bind(':type', $docType);
    $this->db->bind(':period', $periodKey);
    $row = $this->db->single();

    if (!$row) {
        $this->db->query("
            INSERT INTO document_sequences (doc_type, period_key, last_number)
            VALUES (:type, :period, 1)
        ");
        $this->db->bind(':type', $docType);
        $this->db->bind(':period', $periodKey);
        $this->db->execute();
        return 1;
    }

    $next = (int)$row['last_number'] + 1;
    $this->db->query("
        UPDATE document_sequences SET last_number = :num
        WHERE doc_type = :type AND period_key = :period
    ");
    $this->db->bind(':num', $next);
    $this->db->bind(':type', $docType);
    $this->db->bind(':period', $periodKey);
    $this->db->execute();

    return $next;
}

/**
 * Whether document_sequences has branch_id (migration 015).
 */
protected function documentSequencesHaveBranchColumn(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $this->db->query("SHOW COLUMNS FROM document_sequences LIKE 'branch_id'");
        $cached = (bool)$this->db->single();
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

/**
 * Insert a customer_ledger row (always sets branch_id — Phase 2).
 *
 * @param array{
 *   customer_id: int,
 *   reference_type: string,
 *   reference_id: int,
 *   debit?: float,
 *   credit?: float,
 *   running_balance: float,
 *   branch_id?: int,
 *   transaction_date?: string,
 *   remarks?: string|null,
 *   created_by?: int,
 *   is_reversed?: int
 * } $entry
 */
public function insertCustomerLedgerEntry(array $entry): void
{
    $branchId = (int)($entry['branch_id'] ?? 0);
    if ($branchId <= 0) {
        $branchId = self::sessionBranchId();
    }

    $this->db->query("
        INSERT INTO customer_ledger
        (transaction_date, customer_id, reference_type, reference_id,
         debit, credit, running_balance, remarks, created_by, is_reversed, branch_id)
        VALUES (:date, :cid, :ref_type, :ref_id, :debit, :credit, :balance,
                :remarks, :uid, :is_rev, :branch_id)
    ");

    $this->db->bind(':date', $entry['transaction_date'] ?? date('Y-m-d'));
    $this->db->bind(':cid', (int)$entry['customer_id']);
    $this->db->bind(':ref_type', (string)$entry['reference_type']);
    $this->db->bind(':ref_id', (int)$entry['reference_id']);
    $this->db->bind(':debit', (float)($entry['debit'] ?? 0));
    $this->db->bind(':credit', (float)($entry['credit'] ?? 0));
    $this->db->bind(':balance', (float)$entry['running_balance']);
    $this->db->bind(':remarks', $entry['remarks'] ?? null);
    $this->db->bind(':uid', (int)($entry['created_by'] ?? ($_SESSION['user_id'] ?? 1)));
    $this->db->bind(':is_rev', (int)($entry['is_reversed'] ?? 0));
    $this->db->bind(':branch_id', $branchId);
    $this->db->execute();
}

/**
 * @param array{
 *   supplier_id: int,
 *   reference_type: string,
 *   reference_id: int,
 *   debit?: float,
 *   credit?: float,
 *   running_balance: float,
 *   branch_id?: int,
 *   transaction_date?: string,
 *   remarks?: string|null,
 *   created_by?: int,
 *   is_reversed?: int
 * } $entry
 */
public function insertSupplierLedgerEntry(array $entry): void
{
    $branchId = (int)($entry['branch_id'] ?? 0);
    if ($branchId <= 0) {
        $branchId = self::sessionBranchId();
    }

    $this->db->query("
        INSERT INTO supplier_ledger
        (transaction_date, supplier_id, reference_type, reference_id,
         debit, credit, running_balance, remarks, created_by, is_reversed, branch_id)
        VALUES (:date, :sid, :ref_type, :ref_id, :debit, :credit, :balance,
                :remarks, :uid, :is_rev, :branch_id)
    ");

    $this->db->bind(':date', $entry['transaction_date'] ?? date('Y-m-d'));
    $this->db->bind(':sid', (int)$entry['supplier_id']);
    $this->db->bind(':ref_type', (string)$entry['reference_type']);
    $this->db->bind(':ref_id', (int)$entry['reference_id']);
    $this->db->bind(':debit', (float)($entry['debit'] ?? 0));
    $this->db->bind(':credit', (float)($entry['credit'] ?? 0));
    $this->db->bind(':balance', (float)$entry['running_balance']);
    $this->db->bind(':remarks', $entry['remarks'] ?? null);
    $this->db->bind(':uid', (int)($entry['created_by'] ?? ($_SESSION['user_id'] ?? 1)));
    $this->db->bind(':is_rev', (int)($entry['is_reversed'] ?? 0));
    $this->db->bind(':branch_id', $branchId);
    $this->db->execute();
}

/**
 * Customers where last running_balance ≠ sum(debit − credit) (data integrity check).
 *
 * @return array<int, array{customer_id: int, shop_name: string, last_balance: float, computed_balance: float, difference: float}>
 */
public function getCustomerLedgerBalanceMismatches(float $tolerance = 0.02, ?int $branchId = null, int $limit = 100): array
{
    return $this->getSubledgerRunningBalanceMismatches(
        'customer',
        $tolerance,
        $branchId,
        $limit
    );
}

/**
 * Suppliers where last running_balance ≠ sum(debit − credit).
 *
 * @return array<int, array{supplier_id: int, supplier_name: string, last_balance: float, computed_balance: float, difference: float}>
 */
public function getSupplierLedgerBalanceMismatches(float $tolerance = 0.02, ?int $branchId = null, int $limit = 100): array
{
    return $this->getSubledgerRunningBalanceMismatches(
        'supplier',
        $tolerance,
        $branchId,
        $limit
    );
}

/**
 * Employees where last running_balance ≠ sum(debit − credit).
 *
 * @return array<int, array{employee_id: int, employee_name: string, last_balance: float, computed_balance: float, difference: float}>
 */
public function getEmployeeLedgerBalanceMismatches(float $tolerance = 0.02, ?int $branchId = null, int $limit = 100): array
{
    return $this->getSubledgerRunningBalanceMismatches(
        'employee',
        $tolerance,
        $branchId,
        $limit
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
private function getSubledgerRunningBalanceMismatches(
    string $entityType,
    float $tolerance,
    ?int $branchId,
    int $limit
): array {
    $tolerance = max(0.0001, $tolerance);
    $limit = max(1, min(100, $limit));

    if ($entityType === 'customer') {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND (cl.branch_id = ' . (int)$branchId . ' OR cl.branch_id IS NULL)';
        }

        $this->db->query("
            SELECT
                lb.customer_id,
                c.shop_name,
                c.customer_name,
                lb.last_balance,
                COALESCE(cb.computed_balance, 0) AS computed_balance,
                ABS(lb.last_balance - COALESCE(cb.computed_balance, 0)) AS difference
            FROM (
                SELECT cl1.customer_id, cl1.running_balance AS last_balance
                FROM customer_ledger cl1
                INNER JOIN (
                    SELECT customer_id, MAX(id) AS max_id
                    FROM customer_ledger
                    WHERE COALESCE(is_reversed, 0) = 0
                    GROUP BY customer_id
                ) latest ON cl1.id = latest.max_id
                WHERE COALESCE(cl1.is_reversed, 0) = 0
            ) lb
            INNER JOIN customers c ON c.id = lb.customer_id
            LEFT JOIN (
                SELECT cl.customer_id, SUM(cl.debit) - SUM(cl.credit) AS computed_balance
                FROM customer_ledger cl
                WHERE COALESCE(cl.is_reversed, 0) = 0 {$branchSql}
                GROUP BY cl.customer_id
            ) cb ON cb.customer_id = lb.customer_id
            WHERE ABS(lb.last_balance - COALESCE(cb.computed_balance, 0)) > :tol
            ORDER BY difference DESC
            LIMIT {$limit}
        ");
    } elseif ($entityType === 'supplier') {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND (sl.branch_id = ' . (int)$branchId . ' OR sl.branch_id IS NULL)';
        }

        $this->db->query("
            SELECT
                lb.supplier_id,
                s.supplier_name,
                lb.last_balance,
                COALESCE(cb.computed_balance, 0) AS computed_balance,
                ABS(lb.last_balance - COALESCE(cb.computed_balance, 0)) AS difference
            FROM (
                SELECT sl1.supplier_id, sl1.running_balance AS last_balance
                FROM supplier_ledger sl1
                INNER JOIN (
                    SELECT supplier_id, MAX(id) AS max_id
                    FROM supplier_ledger
                    WHERE COALESCE(is_reversed, 0) = 0
                    GROUP BY supplier_id
                ) latest ON sl1.id = latest.max_id
                WHERE COALESCE(sl1.is_reversed, 0) = 0
            ) lb
            INNER JOIN suppliers s ON s.id = lb.supplier_id
            LEFT JOIN (
                SELECT sl.supplier_id, SUM(sl.debit) - SUM(sl.credit) AS computed_balance
                FROM supplier_ledger sl
                WHERE COALESCE(sl.is_reversed, 0) = 0 {$branchSql}
                GROUP BY sl.supplier_id
            ) cb ON cb.supplier_id = lb.supplier_id
            WHERE ABS(lb.last_balance - COALESCE(cb.computed_balance, 0)) > :tol
            ORDER BY difference DESC
            LIMIT {$limit}
        ");
    } else {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND e.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT
                lb.employee_id,
                e.name AS employee_name,
                lb.last_balance,
                COALESCE(cb.computed_balance, 0) AS computed_balance,
                ABS(lb.last_balance - COALESCE(cb.computed_balance, 0)) AS difference
            FROM (
                SELECT el1.employee_id, el1.running_balance AS last_balance
                FROM employee_ledger el1
                INNER JOIN (
                    SELECT employee_id, MAX(id) AS max_id
                    FROM employee_ledger
                    WHERE COALESCE(is_reversed, 0) = 0
                    GROUP BY employee_id
                ) latest ON el1.id = latest.max_id
                WHERE COALESCE(el1.is_reversed, 0) = 0
            ) lb
            INNER JOIN employees e ON e.id = lb.employee_id {$branchSql}
            LEFT JOIN (
                SELECT el.employee_id, SUM(el.debit) - SUM(el.credit) AS computed_balance
                FROM employee_ledger el
                WHERE COALESCE(el.is_reversed, 0) = 0
                GROUP BY el.employee_id
            ) cb ON cb.employee_id = lb.employee_id
            WHERE ABS(lb.last_balance - COALESCE(cb.computed_balance, 0)) > :tol
            ORDER BY difference DESC
            LIMIT {$limit}
        ");
    }

    $this->db->bind(':tol', $tolerance);
    $rows = $this->db->resultSet();

    return is_array($rows) ? $rows : [];
}

/** Globally unique payment code (not per branch). */
public function generateCustomerPaymentCode(int $branchId = 0): string
{
    $period = date('Ymd');
    $n = $this->allocateDocumentSequence('customer_payment', $period);
    return 'PAY-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Globally unique supplier payment code. */
public function generateSupplierPaymentCode(int $branchId = 0): string
{
    $period = date('Ymd');
    $n = $this->allocateDocumentSequence('supplier_payment', $period);
    return 'SPAY-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Globally unique employee transaction code. */
public function generateEmployeeTransactionCode(int $branchId = 0): string
{
    $period = date('Ymd');
    $n = $this->allocateDocumentSequence('employee_transaction', $period);
    return 'ET-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Globally unique other income voucher code. */
public function generateOtherIncomeCode(int $branchId = 0): string
{
    $period = date('Ymd');
    $n = $this->allocateDocumentSequence('other_income', $period);
    return 'OI-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Globally unique other expense voucher code. */
public function generateOtherExpenseCode(int $branchId = 0): string
{
    $period = date('Ymd');
    $n = $this->allocateDocumentSequence('other_expense', $period);
    return 'OE-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/**
 * Latest employee_ledger running balance (positive = employee owes company).
 */
public function Get_Employee_Now_Due(int $employee_id): float
{
    if ($employee_id <= 0) {
        return 0.0;
    }
    $this->db->query("
        SELECT COALESCE(running_balance, 0) AS due_balance
        FROM employee_ledger
        WHERE employee_id = :eid
        ORDER BY id DESC
        LIMIT 1
    ");
    $this->db->bind(':eid', $employee_id);
    $row = $this->db->single();

    return (float)($row['due_balance'] ?? 0);
}

/**
 * @param array{
 *   employee_id: int,
 *   reference_type: string,
 *   reference_id: int,
 *   debit?: float,
 *   credit?: float,
 *   running_balance: float,
 *   transaction_date?: string,
 *   remarks?: string|null,
 *   created_by?: int,
 *   is_reversed?: int
 * } $entry
 */
public function insertEmployeeLedgerEntry(array $entry): void
{
    $this->db->query("
        INSERT INTO employee_ledger
        (transaction_date, employee_id, reference_type, reference_id,
         debit, credit, running_balance, remarks, created_by, is_reversed)
        VALUES (:date, :eid, :ref_type, :ref_id, :debit, :credit, :balance,
                :remarks, :uid, :is_rev)
    ");

    $this->db->bind(':date', $entry['transaction_date'] ?? date('Y-m-d'));
    $this->db->bind(':eid', (int)$entry['employee_id']);
    $this->db->bind(':ref_type', (string)$entry['reference_type']);
    $this->db->bind(':ref_id', (int)$entry['reference_id']);
    $this->db->bind(':debit', (float)($entry['debit'] ?? 0));
    $this->db->bind(':credit', (float)($entry['credit'] ?? 0));
    $this->db->bind(':balance', (float)$entry['running_balance']);
    $this->db->bind(':remarks', $entry['remarks'] ?? null);
    $this->db->bind(':uid', (int)($entry['created_by'] ?? ($_SESSION['user_id'] ?? 1)));
    $this->db->bind(':is_rev', (int)($entry['is_reversed'] ?? 0));
    $this->db->execute();
}

/** Globally unique sales invoice code (not per branch). */
public function generateSalesInvoiceCode(int $branchId = 0): string
{
    $period = date('Ymd');
    $n = $this->allocateDocumentSequence('sales_invoice', $period);
    return 'SI-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

public function generateSalesChallanCode(): string
{
    $period = date('Y');
    $n = $this->allocateDocumentSequence('sales_challan', $period, 0);
    return 'CH-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

public function generateSalesReturnCode(): string
{
    $period = date('Ymd');
    $n = $this->allocateDocumentSequence('sales_return', $period, 0);
    return 'SR-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/**  SalesModel */

public function Invoice_Details($invoice_id) {
        $this->db->query("
            SELECT si.*, c.shop_name, c.customer_name, c.mobile,
                   b.branch_name, b.branch_code, b.address AS branch_address, b.phone AS branch_phone,
                   e.name AS salesman_name, c.address,
                   s.name AS sales_person_name
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            JOIN branches b ON si.branch_id = b.id
            JOIN employees e ON si.salesman_id = e.id
            LEFT JOIN employees s ON si.sales_person = s.id
            WHERE si.id = :id
        ");
        $this->db->bind(':id', $invoice_id);
        return $this->db->single();
    }

    public function Invoice_Item_Details($invoice_id) {
        $this->db->query("
            SELECT sii.*, p.*
            FROM sales_invoice_items sii
            JOIN products p ON sii.product_id = p.id
            WHERE sii.sales_invoice_id = :id
        ");
        $this->db->bind(':id', $invoice_id);
        return $this->db->resultSet();
    }

     

/**   */
/**   */
/**   */
/**   */
/**   */
/**   */
/**   */
/**   */
/**   */
/**   */
/**   */

} // END OF FILE 