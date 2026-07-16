<?php
// app/models/SalesModel.php — Phase 6 thin facade (catalog + trait delegation)

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/../services/Sales/traits/SalesServiceSupportTrait.php';
require_once __DIR__ . '/../services/Sales/traits/SalesCartOperationsTrait.php';
require_once __DIR__ . '/../services/Sales/traits/SalesInvoiceOperationsTrait.php';
require_once __DIR__ . '/../services/Sales/traits/SalesPaymentOperationsTrait.php';
require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';

class SalesModel extends Helper
{
    use SalesServiceSupportTrait;
    use SalesCartOperationsTrait;
    use SalesInvoiceOperationsTrait;
    use SalesPaymentOperationsTrait;

    protected ?StockAvailabilityService $availabilityService = null;

    public function __construct(?Database $db = null)
    {
        parent::__construct($db);
    }

    protected function availability(): StockAvailabilityService
    {
        if ($this->availabilityService === null) {
            $this->availabilityService = new StockAvailabilityService($this->db);
        }

        return $this->availabilityService;
    }

    // ================= BASIC SEARCH & DATA =================

    public function searchCustomers($term)
    {
        return $this->Search_Customers($term);
    }

    public function searchProductsWithStock($term, $branch_id)
    {
        return $this->Search_Product_With_Stock($term, $branch_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProductByExactCode(string $code, int $branch_id)
    {
        return $this->Search_Product_By_Exact_Code($code, $branch_id);
    }

    public function getWarehouseStockForProduct($product_id, $branch_id)
    {
        return $this->Get_Warehouse_Wise_Product_Stock($product_id, $branch_id);
    }

    public function getProductAvailableAtBranch(int $product_id, int $branch_id = 0): array
    {
        $branch_id = $branch_id > 0 ? $branch_id : self::sessionBranchId();
        $branch = $this->Get_Branch_By_Id($branch_id);
        $summary = $this->availability()->getBranchStockSummary((int)$product_id, (int)$branch_id);

        return [
            'branch_id'      => $branch_id,
            'branch_name'    => $branch['branch_name'] ?? '',
            'available_qty'  => $summary['available_qty'],
            'physical_qty'   => $summary['physical_qty'],
            'pipeline_qty'   => $summary['pipeline_qty'],
        ];
    }

    public function getBranch($branch_id)
    {
        return $this->Get_Branch_By_Id($branch_id);
    }

    public function getAllBranches()
    {
        return $this->Get_All_Active_Branches();
    }

    public function getSalesEmployees()
    {
        return $this->All_Active_Employees();
    }

    public function getCustomerDetails($customer_id)
    {
        if (!$customer_id) {
            return [];
        }

        $cust = $this->Get_Customer_By_Id($customer_id);
        $due = $this->Get_Customer_Due($customer_id);
        $cust['recent_due'] = $due['due'] ?? 0;
        $cust['due_left']   = ($cust['credit_limit'] ?? 0) - $cust['recent_due'];

        return $cust;
    }

    public function getAvailableStock($product_id, $branch_id = null)
    {
        if (!$branch_id) {
            $branch_id = self::sessionBranchId();
        }

        return $this->availability()->getBranchAvailableQty((int)$product_id, (int)$branch_id);
    }

    public function resolveBranchIdForRead(?int $requestedBranchId = null): int
    {
        if ($requestedBranchId > 0 && $this->isActiveBranch($requestedBranchId)) {
            return $requestedBranchId;
        }

        return self::sessionBranchId();
    }
}