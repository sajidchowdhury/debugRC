<?php
// app/models/PurchaseAuditModel.php — full purchase ecosystem audit (masters, transactions, stock, GL, reports)

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';

class PurchaseAuditModel
{
    protected $db;
    protected ?int $branchId;

    public function __construct()
    {
        $this->db = new Database();
        $this->branchId = Helper::sessionBranchId();
    }

    /**
     * Reference checklist + automated DB checks across the purchase ecosystem.
     *
     * @return array{sections: array, summary: array, ran_at: string, branch_id: ?int, negative_stocks: array}
     */
    public function runHealthChecks(): array
    {
        $sections = [
            $this->sectionModuleScope(),
            $this->sectionProducts(),
            $this->sectionSuppliers(),
            $this->sectionWarehouses(),
            $this->sectionStockSsot(),
            $this->sectionPurchaseOrder(),
            $this->sectionGrn(),
            $this->sectionPurchaseReturn(),
            $this->sectionSupplierPayments(),
            $this->sectionGlJournalLinks(),
            $this->sectionLedger(),
            $this->sectionReports(),
        ];

        $pass = $warn = $fail = $info = 0;
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                switch ($item['status']) {
                    case 'pass': $pass++; break;
                    case 'warn': $warn++; break;
                    case 'fail': $fail++; break;
                    default: $info++;
                }
            }
        }

        return [
            'sections'        => $sections,
            'summary'         => [
                'pass'  => $pass,
                'warn'  => $warn,
                'fail'  => $fail,
                'info'  => $info,
                'total' => $pass + $warn + $fail + $info,
            ],
            'ran_at'          => date('Y-m-d H:i:s'),
            'branch_id'       => $this->branchId,
            'negative_stocks' => $this->getNegativeStockRows(),
            'missing_grn_journals'    => $this->getGrnsMissingJournalRows(),
            'missing_return_journals' => $this->getReturnsMissingJournalRows(),
        ];
    }

    private function sectionModuleScope(): array
    {
        return [
            'id'    => 'scope',
            'title' => 'Purchase module scope',
            'icon'  => 'fa-sitemap',
            'items' => [
                $this->item('scope_masters', 'reference', 'Master data', 'Products (SKU), suppliers, warehouses/branches — required before PO/GRN.', 'info', null, true),
                $this->item('scope_transactions', 'reference', 'Transactions', 'PO → GRN (direct or from PO) → purchase return; optional supplier payment/settlement.', 'info', null, true),
                $this->item('scope_stock', 'reference', 'Inventory impact', 'Only GRN (IN), GRN cancel (OUT), good return (OUT), return reverse (IN). PO alone does not move stock.', 'info', null, true),
                $this->item('scope_gl', 'reference', 'Accounting impact', 'GRN/return post to GL (inventory + supplier payable). Payments use supplier_ledger + bank/cash.', 'info', null, true),
                $this->item('scope_reports', 'reference', 'Reporting', 'Purchase history, returns, supplier-wise purchase, payable aging, stock reports — see Reports section.', 'info', null, true),
            ],
        ];
    }

    private function sectionProducts(): array
    {
        $inactiveOnGrn = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            INNER JOIN products p ON p.id = pri.product_id
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(p.is_active, 1) = 0
              {$this->branchFilter('pr.branch_id')}
        ");

        $inactiveOnPo = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_order_items poi
            INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
            INNER JOIN products p ON p.id = poi.product_id
            WHERE po.created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(p.is_active, 1) = 0
              {$this->branchFilter('po.branch_id')}
        ");

        $orphanGrnProduct = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND NOT EXISTS (SELECT 1 FROM products p WHERE p.id = pri.product_id)
              {$this->branchFilter('pr.branch_id')}
        ");

        $purchasedSkus = $this->scalarCount("
            SELECT COUNT(DISTINCT pri.product_id) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('pr.branch_id')}
        ");

        $activeNoGroup = $this->scalarCount("
            SELECT COUNT(*) AS c FROM products p
            WHERE p.is_active = 1 AND (p.group_id IS NULL OR p.group_id = 0)
        ");

        $activeNoPrice = $this->scalarCount("
            SELECT COUNT(*) AS c FROM products p
            WHERE p.is_active = 1
              AND NOT EXISTS (
                SELECT 1 FROM product_price_history ph
                WHERE ph.product_id = p.id AND ph.default_rate > 0
              )
        ");

        return [
            'id'    => 'products',
            'title' => 'Products (purchase SKUs)',
            'icon'  => 'fa-cube',
            'items' => [
                $this->item('prod_master', 'reference', 'Product master is shared', 'Same products table used for sales and purchase. PO/GRN/return lines reference product_id; rates on GRN update moving average via StockTransactionModel.', 'info', null, true),
                $this->item('prod_active', 'reference', 'Prefer active products on new docs', 'Inactive products should not be added to new PO/GRN lines (UI should filter active SKUs).', 'info', null, true),
                $this->item('prod_group', 'auto', 'Active products have a group', 'Every active SKU should have product_groups assigned (default China).', $activeNoGroup === 0 ? 'pass' : 'warn', $activeNoGroup === 0 ? 'OK' : "{$activeNoGroup} SKU(s) missing group"),
                $this->item('prod_price_range', 'auto', 'Active products have price range', 'Active SKUs should have min/max/default in product_price_history.', $activeNoPrice === 0 ? 'pass' : 'warn', $activeNoPrice === 0 ? 'OK' : "{$activeNoPrice} SKU(s) without price"),
                $this->item('prod_purchased_count', 'auto', 'Distinct products purchased (last 12 mo)', 'Count of unique product_id on received GRNs in period.', $purchasedSkus > 0 ? 'pass' : 'warn', $purchasedSkus > 0 ? "{$purchasedSkus} SKU(s)" : 'No received GRN lines in period'),
                $this->item('prod_inactive_grn', 'auto', 'No inactive products on received GRNs', 'Received GRN lines should not reference deactivated products.', $inactiveOnGrn === 0 ? 'pass' : 'warn', $inactiveOnGrn === 0 ? 'OK' : "{$inactiveOnGrn} line(s) with inactive product"),
                $this->item('prod_inactive_po', 'auto', 'No inactive products on PO lines', 'PO lines in period should reference active products.', $inactiveOnPo === 0 ? 'pass' : 'warn', $inactiveOnPo === 0 ? 'OK' : "{$inactiveOnPo} PO line(s) with inactive product"),
                $this->item('prod_orphan_grn', 'auto', 'GRN lines have valid product_id', 'Every purchase_receive_items.product_id must exist in products.', $orphanGrnProduct === 0 ? 'pass' : 'fail', $orphanGrnProduct === 0 ? 'OK' : "{$orphanGrnProduct} line(s) missing product"),
            ],
        ];
    }

    private function sectionSuppliers(): array
    {
        $activeSuppliers = $this->scalarCount("SELECT COUNT(*) AS c FROM suppliers WHERE is_active = 1");

        $grnNoSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'received'
              AND COALESCE(pr.supplier_id, 0) = 0
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('pr.branch_id')}
        ");

        $grnInactiveSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receives pr
            LEFT JOIN suppliers s ON s.id = pr.supplier_id
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND (s.id IS NULL OR COALESCE(s.is_active, 0) = 0)
              {$this->branchFilter('pr.branch_id')}
        ");

        $poInactiveSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND (s.id IS NULL OR COALESCE(s.is_active, 0) = 0)
              {$this->branchFilter('po.branch_id')}
        ");

        $directNoSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'received'
              AND COALESCE(pr.purchase_order_id, 0) = 0
              AND COALESCE(pr.supplier_id, 0) = 0
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('pr.branch_id')}
        ");

        return [
            'id'    => 'suppliers',
            'title' => 'Suppliers',
            'icon'  => 'fa-truck',
            'items' => [
                $this->item('sup_master', 'reference', 'Supplier master (Supplier module)', 'Create/edit suppliers; mobile uniqueness; soft deactivate via is_active. Used on PO, direct GRN, returns, and payments.', 'info', null, true, 'supplier/index'),
                $this->item('sup_active_pool', 'auto', 'Active suppliers available', 'At least one active supplier should exist for purchase operations.', $activeSuppliers > 0 ? 'pass' : 'warn', $activeSuppliers > 0 ? "{$activeSuppliers} active" : 'No active suppliers'),
                $this->item('sup_grn_required', 'auto', 'Received GRNs have supplier_id', 'Every received GRN must link to a supplier (including direct purchase).', $grnNoSupplier === 0 ? 'pass' : 'fail', $grnNoSupplier === 0 ? 'OK' : "{$grnNoSupplier} GRN(s) without supplier"),
                $this->item('sup_direct_purchase', 'auto', 'Direct GRN includes supplier', 'Direct purchase (no PO) still requires supplier_id on the GRN header.', $directNoSupplier === 0 ? 'pass' : 'fail', $directNoSupplier === 0 ? 'OK' : "{$directNoSupplier} direct GRN(s) missing supplier"),
                $this->item('sup_grn_active', 'auto', 'GRNs use active suppliers', 'Received GRNs should not reference missing or inactive suppliers.', $grnInactiveSupplier === 0 ? 'pass' : 'warn', $grnInactiveSupplier === 0 ? 'OK' : "{$grnInactiveSupplier} GRN(s) with inactive/missing supplier"),
                $this->item('sup_po_active', 'auto', 'POs use active suppliers', 'Purchase orders should reference valid active suppliers.', $poInactiveSupplier === 0 ? 'pass' : 'warn', $poInactiveSupplier === 0 ? 'OK' : "{$poInactiveSupplier} PO(s) with inactive/missing supplier"),
                $this->item('sup_audit_log', 'reference', 'Supplier change audit', 'User actions on suppliers are logged in supplier audit (separate from this checklist).', 'info', null, true, 'supplier/audit'),
            ],
        ];
    }

    private function sectionWarehouses(): array
    {
        $invalidWarehouse = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND (
                  COALESCE(pri.warehouse_id, 0) = 0
                  OR NOT EXISTS (SELECT 1 FROM warehouses w WHERE w.id = pri.warehouse_id)
              )
              {$this->branchFilter('pr.branch_id')}
        ");

        $inactiveWarehouse = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            INNER JOIN warehouses w ON w.id = pri.warehouse_id
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(w.is_active, 1) = 0
              {$this->branchFilter('pr.branch_id')}
        ");

        $branchMismatch = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            INNER JOIN warehouses w ON w.id = pri.warehouse_id
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND w.branch_id IS NOT NULL
              AND pr.branch_id IS NOT NULL
              AND w.branch_id != pr.branch_id
              {$this->branchFilter('pr.branch_id')}
        ");

        return [
            'id'    => 'warehouses',
            'title' => 'Warehouses & branches',
            'icon'  => 'fa-warehouse',
            'items' => [
                $this->item('wh_required', 'reference', 'GRN lines require warehouse_id', 'Each receive line posts stock IN to a specific warehouse; moving average is per warehouse_stock row.', 'info', null, true),
                $this->item('wh_branch', 'reference', 'Warehouse belongs to branch', 'Prefer warehouses where warehouse.branch_id matches GRN branch_id.', 'info', null, true),
                $this->item('wh_valid', 'auto', 'GRN lines have valid warehouse', 'warehouse_id must exist on warehouses table.', $invalidWarehouse === 0 ? 'pass' : 'fail', $invalidWarehouse === 0 ? 'OK' : "{$invalidWarehouse} line(s) invalid/missing warehouse"),
                $this->item('wh_active', 'auto', 'GRN uses active warehouses', 'Received lines should not target deactivated warehouses.', $inactiveWarehouse === 0 ? 'pass' : 'warn', $inactiveWarehouse === 0 ? 'OK' : "{$inactiveWarehouse} line(s) on inactive warehouse"),
                $this->item('wh_branch_match', 'auto', 'Warehouse branch matches GRN branch', 'Cross-branch receive into wrong warehouse should be zero.', $branchMismatch === 0 ? 'pass' : 'warn', $branchMismatch === 0 ? 'OK' : "{$branchMismatch} line(s) branch mismatch"),
            ],
        ];
    }

    private function sectionStockSsot(): array
    {
        $neg = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_stock ws
            WHERE ws.qty < -0.0001
            {$this->branchWarehouseFilter('ws.warehouse_id')}
        ");

        $orphanMovements = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions st
            WHERE st.reference_type IN ('purchase_receive','purchase_receive_cancel','purchase_return','purchase_return_reversal')
              AND st.reference_id > 0
              AND NOT EXISTS (
                  SELECT 1 FROM warehouse_stock ws
                  WHERE ws.warehouse_id = st.warehouse_id AND ws.product_id = st.product_id
              )
            {$this->branchWarehouseFilter('st.warehouse_id')}
        ");

        $recentPurchaseMoves = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions st
            WHERE st.reference_type IN ('purchase_receive','purchase_receive_cancel','purchase_return','purchase_return_reversal')
              AND st.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchWarehouseFilter('st.warehouse_id')}
        ");

        return [
            'id'    => 'stock',
            'title' => 'Stock — single source of truth',
            'icon'  => 'fa-boxes',
            'items' => [
                $this->item('stock_ssot', 'reference', 'Read: warehouse_stock (qty + avg_cost)', 'On-hand quantity and moving-average cost live only in warehouse_stock. Purchase return warehouse dropdown uses Helper::Get_Warehouse_Wise_Product_Stock() (minus sales dispatch soft-holds). Same SSOT as sales.', 'info', 'Do not use GRN line qty as on-hand stock.', true),
                $this->item('stock_grn_returnable', 'reference', 'GRN returnable_qty is not on-hand stock', 'returnable_qty = received − returned_to_supplier on that GRN line. Caps supplier return; separate from warehouse_stock.', 'info', 'Return qty ≤ returnable AND ≤ warehouse available (Good).', true),
                $this->item('stock_writer', 'reference', 'Write: StockTransactionModel only', 'PurchaseReceiveModel and PurchaseReturnModel use updateWarehouseStock() + logMovement() in one transaction — never direct UPDATE warehouse_stock in purchase code.', 'info', 'Types: purchase_receive, purchase_receive_cancel, purchase_return, purchase_return_reversal.', true),
                $this->item('stock_moves_logged', 'auto', 'Purchase stock movements logged (last 12 mo)', 'Confirms stock_transactions rows exist for purchase flows.', $recentPurchaseMoves > 0 ? 'pass' : 'warn', $recentPurchaseMoves > 0 ? "{$recentPurchaseMoves} movement(s)" : 'No purchase stock movements in period'),
                $this->item('stock_negative', 'auto', 'No negative warehouse balances', 'warehouse_stock.qty must not go below zero; legacy rows need stock adjustment.', $neg === 0 ? 'pass' : 'fail', $neg === 0 ? 'OK' : "{$neg} row(s) below zero — see table below"),
                $this->item('stock_orphan', 'auto', 'Movements linked to warehouse_stock', 'Every purchase stock_transaction should have a matching warehouse_stock row.', $orphanMovements === 0 ? 'pass' : 'warn', $orphanMovements === 0 ? 'OK' : "{$orphanMovements} movement(s) without warehouse_stock"),
            ],
        ];
    }

    private function sectionPurchaseOrder(): array
    {
        $overReceived = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_order_items poi
            INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
            WHERE COALESCE(poi.received_qty, 0) > poi.qty + 0.0001
              {$this->branchFilter('po.branch_id')}
        ");

        $openPoLines = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_order_items poi
            INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
            WHERE po.status NOT IN ('cancelled', 'closed')
              AND COALESCE(poi.received_qty, 0) < poi.qty - 0.0001
              {$this->branchFilter('po.branch_id')}
        ");

        return [
            'id'    => 'po',
            'title' => 'Purchase order',
            'icon'  => 'fa-file-invoice',
            'items' => [
                $this->item('po_no_stock', 'reference', 'Create / cancel does not move stock', 'PO is planning only until a GRN is posted.', 'info', null, true, 'PurchaseOrder'),
                $this->item('po_no_gl', 'reference', 'No GL on draft PO', 'Supplier payable is recognized on GRN, not on PO.', 'info', null, true),
                $this->item('po_cancel', 'reference', 'Cancel = status only (draft/pending)', 'Hard delete only for draft POs.', 'info', null, true),
                $this->item('po_from_po', 'reference', 'GRN from PO updates received_qty', 'PO-based receive increments purchase_order_items.received_qty per line.', 'info', null, true),
                $this->item('po_direct', 'reference', 'Direct GRN (no PO)', 'purchase_order_id NULL; supplier_id required on GRN header.', 'info', null, true, 'PurchaseReceive/create'),
                $this->item('po_over_received', 'auto', 'received_qty ≤ ordered qty', 'PO line received_qty cannot exceed ordered qty.', $overReceived === 0 ? 'pass' : 'fail', $overReceived === 0 ? 'OK' : "{$overReceived} line(s) over-received"),
                $this->item('po_open_lines', 'auto', 'Open PO lines pending receive', 'Informational count of PO lines not fully received (not a failure).', 'info', $openPoLines === 0 ? 'None open' : "{$openPoLines} line(s) still pending GRN"),
            ],
        ];
    }

    private function sectionGrn(): array
    {
        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'received'
              AND COALESCE(pr.journal_entry_id, 0) = 0
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('pr.branch_id')}
        ");

        $noStock = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'received'
              AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('pr.branch_id')}
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'purchase_receive' AND st.reference_id = pr.id AND st.qty > 0
              )
        ");

        $cancelNoJeRev = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            INNER JOIN journal_entries je ON je.id = pr.journal_entry_id
            WHERE pr.status = 'cancelled'
              AND COALESCE(je.is_reversed, 0) = 0
              {$this->branchFilter('pr.branch_id')}
        ");

        return [
            'id'    => 'grn',
            'title' => 'Goods received (GRN)',
            'icon'  => 'fa-dolly',
            'items' => [
                $this->item('grn_create_stock', 'reference', 'Create → stock IN + log', 'StockTransactionModel IN at receive rate; reference purchase_receive.', 'info', null, true, 'PurchaseReceive'),
                $this->item('grn_create_gl', 'reference', 'Create → Dr Inventory / Cr Supplier Payable', 'JournalPostingService::postPurchaseReceive.', 'info', null, true),
                $this->item('grn_cancel_stock', 'reference', 'Cancel → stock OUT + log', 'Reverses receive qty; blocks if active returns exist.', 'info', null, true),
                $this->item('grn_cancel_gl', 'reference', 'Cancel → reverse linked journal', 'reverseLinkedJournal on GRN journal_entry_id.', 'info', null, true),
                $this->item('grn_missing_journal', 'auto', 'Received GRNs have journal (last 12 mo)', 'Active received GRNs should have journal_entry_id.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} GRN(s) missing journal"),
                $this->item('grn_missing_stock', 'auto', 'Received GRNs have stock IN (last 12 mo)', 'Each received GRN should have positive purchase_receive stock_transactions.', $noStock === 0 ? 'pass' : 'fail', $noStock === 0 ? 'OK' : "{$noStock} GRN(s) without stock IN"),
                $this->item('grn_cancel_journal', 'auto', 'Cancelled GRNs reversed in GL', 'Cancelled GRN with journal should have is_reversed on original entry.', $cancelNoJeRev === 0 ? 'pass' : 'fail', $cancelNoJeRev === 0 ? 'OK' : "{$cancelNoJeRev} cancelled GRN(s) with unreversed journal"),
            ],
        ];
    }

    private function sectionPurchaseReturn(): array
    {
        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_returns prt
            WHERE COALESCE(prt.is_reversed, 0) = 0
              AND prt.return_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(prt.journal_entry_id, 0) = 0
              {$this->branchFilter('prt.branch_id')}
        ");

        $noStockOut = $this->scalarCount("
            SELECT COUNT(DISTINCT prt.id) AS c
            FROM purchase_returns prt
            INNER JOIN purchase_return_items pri ON pri.purchase_return_id = prt.id
            WHERE COALESCE(prt.is_reversed, 0) = 0
              AND LOWER(COALESCE(pri.`condition`, 'good')) = 'good'
              AND pri.return_qty > 0
              {$this->branchFilter('prt.branch_id')}
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'purchase_return'
                    AND st.reference_id = prt.id
                    AND st.qty < -0.0001
              )
        ");

        $overReturned = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            WHERE COALESCE(pri.returned_qty, 0) > pri.qty + 0.0001
              {$this->branchFilter('pr.branch_id')}
        ");

        $reversedNoFlag = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_returns prt
            WHERE EXISTS (
                SELECT 1 FROM stock_transactions st
                WHERE st.reference_type = 'purchase_return_reversal' AND st.reference_id = prt.id
            )
              AND COALESCE(prt.is_reversed, 0) = 0
              {$this->branchFilter('prt.branch_id')}
        ");

        $reversedNoJe = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_returns prt
            INNER JOIN journal_entries je ON je.id = prt.journal_entry_id
            WHERE COALESCE(prt.is_reversed, 0) = 1
              AND COALESCE(je.is_reversed, 0) = 0
              {$this->branchFilter('prt.branch_id')}
        ");

        return [
            'id'    => 'return',
            'title' => 'Purchase return',
            'icon'  => 'fa-undo-alt',
            'items' => [
                $this->item('prt_create_stock', 'reference', 'Create (Good) → stock OUT + log', 'Moving avg at return; updates purchase_receive_items.returned_qty.', 'info', null, true, 'PurchaseReturn'),
                $this->item('prt_damage', 'reference', 'Damage lines → no stock OUT', 'Damaged return qty does not reduce warehouse_stock (supplier claim only).', 'info', null, true),
                $this->item('prt_create_gl', 'reference', 'Create → Dr Supplier Payable / Cr Inventory', 'JournalPostingService::postPurchaseReturn.', 'info', null, true),
                $this->item('prt_reverse_stock', 'reference', 'Reverse → restore from stock_transactions', 'Reads purchase_return OUT rows; restores qty at logged rate.', 'info', null, true),
                $this->item('prt_reverse_gl', 'reference', 'Reverse → reverseLinkedJournal', 'Restores returned_qty on GRN lines.', 'info', null, true),
                $this->item('prt_missing_journal', 'auto', 'Active returns have journal (last 12 mo)', 'Non-reversed returns should have journal_entry_id.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} return(s) missing journal"),
                $this->item('prt_missing_stock', 'auto', 'Good returns have stock OUT', 'Active Good returns should have negative purchase_return movements.', $noStockOut === 0 ? 'pass' : 'fail', $noStockOut === 0 ? 'OK' : "{$noStockOut} return(s) missing stock OUT"),
                $this->item('prt_over_returned', 'auto', 'returned_qty ≤ received qty', 'purchase_receive_items.returned_qty cannot exceed qty.', $overReturned === 0 ? 'pass' : 'fail', $overReturned === 0 ? 'OK' : "{$overReturned} line(s) over-returned"),
                $this->item('prt_reversal_flag', 'auto', 'Reversal movements match is_reversed flag', 'purchase_return_reversal stock requires is_reversed=1 on header.', $reversedNoFlag === 0 ? 'pass' : 'warn', $reversedNoFlag === 0 ? 'OK' : "{$reversedNoFlag} return(s) with reversal stock but not flagged"),
                $this->item('prt_reversal_journal', 'auto', 'Reversed returns reversed in GL', 'Reversed return should have is_reversed on linked journal.', $reversedNoJe === 0 ? 'pass' : 'warn', $reversedNoJe === 0 ? 'OK' : "{$reversedNoJe} reversed return(s) with unreversed journal"),
            ],
        ];
    }

    private function sectionSupplierPayments(): array
    {
        $paymentsNoLedger = $this->scalarCount("
            SELECT COUNT(*) AS c FROM supplier_payments sp
            WHERE COALESCE(sp.is_reversed, 0) = 0
              AND sp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('sp.branch_id')}
              AND NOT EXISTS (
                  SELECT 1 FROM supplier_ledger sl
                  WHERE sl.reference_id = sp.id
                    AND sl.reference_type IN ('payment', 'advance', 'receive')
              )
        ");

        $recentPayments = $this->scalarCount("
            SELECT COUNT(*) AS c FROM supplier_payments sp
            WHERE sp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('sp.branch_id')}
        ");

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM supplier_payments sp
            WHERE COALESCE(sp.is_reversed, 0) = 0
              AND sp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('sp.branch_id')}
              AND COALESCE(sp.journal_entry_id, 0) = 0
        ");

        if ($noJournal > 0) {
            $this->repairMissingSupplierPaymentJournals($this->branchId);
            // Recompute after repair
            $noJournal = $this->scalarCount("
                SELECT COUNT(*) AS c FROM supplier_payments sp
                WHERE COALESCE(sp.is_reversed, 0) = 0
                  AND sp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  {$this->branchFilter('sp.branch_id')}
                  AND COALESCE(sp.journal_entry_id, 0) = 0
            ");
        }

        $payRevUnreversed = $this->countReversedSupplierPaymentsWithUnreversedJournal();

        return [
            'id'    => 'payments',
            'title' => 'Supplier payments & due',
            'icon'  => 'fa-hand-holding-usd',
            'items' => [
                $this->item('pay_dual', 'reference', 'Two payable views', 'GRN/return post supplier payable to GL (journal_entries). Supplier payments also write supplier_ledger running balance — reconcile both for month-end.', 'info', null, true),
                $this->item('pay_module', 'reference', 'SupplierTransaction module', 'Record payment/advance; optional branch demand settlement; reverse with reason. Now posts to GL journal too.', 'info', null, true, 'SupplierTransaction'),
                $this->item('pay_ledger_row', 'auto', 'Payments have supplier_ledger row (last 12 mo)', 'Each active supplier_payment should create a supplier_ledger entry.', $paymentsNoLedger === 0 ? 'pass' : 'warn', $paymentsNoLedger === 0 ? 'OK' : "{$paymentsNoLedger} payment(s) without ledger row"),
                $this->item('pay_journal', 'auto', 'Supplier payments have GL journal (last 12 mo)', 'postSupplierTransactionJournal (payment/advance/receive) — auto-repair on checklist load.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} payment(s) still missing journal"),
                $this->item('pay_rev_journal', 'auto', 'Reversed payments reversed in GL', 'Reversed supplier_payment should reverse linked journal.', $payRevUnreversed === 0 ? 'pass' : 'warn', $payRevUnreversed === 0 ? 'OK' : "{$payRevUnreversed} reversed payment(s) with unreversed journal"),
                $this->item('pay_activity', 'auto', 'Supplier payments in period', 'Informational: payments recorded in last 12 months.', $recentPayments > 0 ? 'pass' : 'info', $recentPayments > 0 ? "{$recentPayments} payment(s)" : 'No supplier payments in period (OK if paying later)'),
            ],
        ];
    }

    private function sectionGlJournalLinks(): array
    {
        return [
            'id'    => 'gl_links',
            'title' => 'GL journal link columns',
            'icon'  => 'fa-link',
            'items' => [
                $this->item('gl_col_grn', 'reference', 'purchase_receives.journal_entry_id', 'GRN: Dr Inventory / Cr Supplier Payable. View on PurchaseReceive/details/{id}.', 'info', null, true, 'PurchaseReceive'),
                $this->item('gl_col_return', 'reference', 'purchase_returns.journal_entry_id', 'Return: Dr Supplier Payable / Cr Inventory. View on PurchaseReturn/details/{id}.', 'info', null, true, 'PurchaseReturn'),
                $this->item('gl_col_payment', 'reference', 'supplier_payments.journal_entry_id', 'Supplier payment/advance via SupplierTransaction module.', 'info', null, true, 'SupplierTransaction'),
            ],
        ];
    }

    private function countReversedSupplierPaymentsWithUnreversedJournal(): int
    {
        return $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM supplier_payments sp
            INNER JOIN journal_entries je ON je.id = sp.journal_entry_id
            WHERE sp.is_reversed = 1
              AND COALESCE(sp.journal_entry_id, 0) > 0
              AND COALESCE(je.is_reversed, 0) = 0
              {$this->branchFilter('sp.branch_id')}
        ");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getGrnsMissingJournalRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT pr.id, pr.receive_code, pr.receive_date, pr.total_amount
                FROM purchase_receives pr
                WHERE pr.status = 'received'
                  AND COALESCE(pr.journal_entry_id, 0) = 0
                  AND pr.receive_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  {$this->branchFilter('pr.branch_id')}
                ORDER BY pr.receive_date DESC
                LIMIT " . (int)$limit
            );
            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('PurchaseAuditModel::getGrnsMissingJournalRows: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getReturnsMissingJournalRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT prt.id, prt.return_code, prt.return_date, prt.total_amount, pr.receive_code
                FROM purchase_returns prt
                INNER JOIN purchase_receives pr ON pr.id = prt.purchase_receive_id
                WHERE COALESCE(prt.is_reversed, 0) = 0
                  AND COALESCE(prt.journal_entry_id, 0) = 0
                  AND prt.return_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  {$this->branchFilter('prt.branch_id')}
                ORDER BY prt.return_date DESC
                LIMIT " . (int)$limit
            );
            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('PurchaseAuditModel::getReturnsMissingJournalRows: ' . $e->getMessage());
            return [];
        }
    }

    private function sectionLedger(): array
    {
        $invLedgers = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'inventory' AND is_active = 1");
        $apLedgers = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'supplier_payable' AND is_active = 1");

        return [
            'id'    => 'ledger',
            'title' => 'Ledger & accounts (GL)',
            'icon'  => 'fa-book',
            'items' => [
                $this->item('gl_ap', 'reference', 'Supplier payable (nature: supplier_payable)', 'Credited on GRN; debited on purchase return. Entity type supplier on journal lines.', 'info', null, true),
                $this->item('gl_inv', 'reference', 'Inventory (nature: inventory)', 'Debited on GRN; credited on purchase return (Good qty).', 'info', null, true),
                $this->item('gl_inv_exists', 'auto', 'Active inventory ledger configured', 'JournalPostingService requires at least one active inventory ledger.', $invLedgers > 0 ? 'pass' : 'fail', $invLedgers > 0 ? "{$invLedgers} ledger(s)" : 'Missing — GRN posting will fail'),
                $this->item('gl_ap_exists', 'auto', 'Active supplier payable ledger configured', 'Required for GRN and purchase return journals.', $apLedgers > 0 ? 'pass' : 'fail', $apLedgers > 0 ? "{$apLedgers} ledger(s)" : 'Missing — GRN posting will fail'),
                $this->item('gl_trial', 'reference', 'Reconcile with Trial Balance', 'Use Report/TrialBalance and PayableAging after period close.', 'info', null, true, 'Report/TrialBalance'),
            ],
        ];
    }

    private function sectionReports(): array
    {
        $items = [];

        $implemented = [
            ['id' => 'rpt_supplier_wise', 'title' => 'Supplier-wise purchase', 'route' => 'Report/SupplierWisePurchase', 'view' => 'SupplierWisePurchase', 'desc' => 'Spend aggregated per supplier.'],
            ['id' => 'rpt_payable_aging', 'title' => 'Payable aging (suppliers)', 'route' => 'Report/PayableAging', 'view' => 'PayableAging', 'desc' => 'Outstanding supplier balances from supplier_ledger.'],
            ['id' => 'rpt_product_move', 'title' => 'Product movement', 'route' => 'Report/ProductMovement', 'view' => 'ProductMovement', 'desc' => 'Includes purchase_receive / return movement types.'],
            // Supplier due summary page removed; due now primarily via supplier_ledger + reports
        ];

        foreach ($implemented as $r) {
            $exists = $r['view'] === null || $this->reportViewExists($r['view']);
            $items[] = $this->item(
                $r['id'],
                'auto',
                $r['title'] . ' — available',
                $r['desc'],
                $exists ? 'pass' : 'warn',
                $exists ? 'Screen exists in app' : 'Route/view missing — verify menu',
                false,
                $r['route']
            );
        }

        $planned = [
            ['id' => 'rpt_open_po', 'title' => 'Open PO / pending receive', 'desc' => 'PO lines not fully GRN’d — qty, supplier, expected date. (Planned)'],
            ['id' => 'rpt_grn_register', 'title' => 'GRN register (detailed)', 'desc' => 'Line-level receive register with warehouse, rate, tax columns. (Planned — extend Purchase History)'],
            ['id' => 'rpt_supplier_statement', 'title' => 'Supplier statement (GL + ledger)', 'desc' => 'Opening, GRN, returns, payments, closing per supplier. (Planned)'],
            ['id' => 'rpt_product_purchase', 'title' => 'Product-wise purchase analysis', 'desc' => 'Qty and value purchased per SKU, trend by period. (Planned)'],
            ['id' => 'rpt_po_variance', 'title' => 'PO vs actual (rate/qty variance)', 'desc' => 'Compare PO rate/qty to GRN actuals. (Planned)'],
            ['id' => 'rpt_damage', 'title' => 'Damaged / rejected purchase returns', 'desc' => 'Damage-condition returns without stock impact. (Planned)'],
        ];

        foreach ($planned as $p) {
            $items[] = $this->item($p['id'], 'reference', $p['title'], $p['desc'], 'info', 'Implement in Reports module later', true);
        }

        $items[] = $this->item(
            'rpt_stock_analysis',
            'auto',
            'Product stock analysis report',
            'Useful for reorder; includes stock position used by purchase planning.',
            $this->reportViewExists('ProductStockAnalysis') ? 'pass' : 'warn',
            $this->reportViewExists('ProductStockAnalysis') ? 'Available' : 'View file missing',
            false,
            'Report/ProductStockAnalysis'
        );

        return [
            'id'    => 'reports',
            'title' => 'Purchase-related reports',
            'icon'  => 'fa-chart-bar',
            'items' => $items,
        ];
    }

    private function item(
        string $id,
        string $type,
        string $title,
        string $expected,
        string $status,
        ?string $detail,
        bool $reference = false,
        ?string $route = null
    ): array {
        return [
            'id'        => $id,
            'type'      => $type,
            'title'     => $title,
            'expected'  => $expected,
            'status'    => $status,
            'detail'    => $detail ?? '',
            'reference' => $reference,
            'url'       => $route ? (defined('BASE_URL') ? BASE_URL : '') . $route : null,
        ];
    }

    private function reportViewExists(string $viewName): bool
    {
        return is_file(dirname(__DIR__) . '/views/report/' . $viewName . '.php');
    }

    public function getNegativeStockRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT ws.warehouse_id, ws.product_id, w.warehouse_name, p.product_name,
                       ws.qty AS ws_qty, ws.avg_cost,
                       (
                           SELECT COALESCE(SUM(st.qty), 0)
                           FROM stock_transactions st
                           WHERE st.warehouse_id = ws.warehouse_id AND st.product_id = ws.product_id
                       ) AS txn_qty_sum
                FROM warehouse_stock ws
                LEFT JOIN warehouses w ON w.id = ws.warehouse_id
                LEFT JOIN products p ON p.id = ws.product_id
                WHERE ws.qty < -0.0001
                {$this->branchWarehouseFilter('ws.warehouse_id')}
                ORDER BY ws.qty ASC
                LIMIT " . (int)$limit
            );
            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('PurchaseAuditModel::getNegativeStockRows: ' . $e->getMessage());
            return [];
        }
    }

    private function scalarCount(string $sql): int
    {
        try {
            $this->db->query($sql);
            $row = $this->db->single();
            return (int)($row['c'] ?? 0);
        } catch (Exception $e) {
            error_log('PurchaseAuditModel: ' . $e->getMessage());
            return -1;
        }
    }

    private function branchFilter(string $column): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND {$column} = " . (int)$this->branchId;
    }

    private function branchWarehouseFilter(string $warehouseColumn): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND EXISTS (
            SELECT 1 FROM warehouses w
            WHERE w.id = {$warehouseColumn} AND w.branch_id = " . (int)$this->branchId . '
        )';
    }

    /**
     * Auto-repair missing GL journals for supplier payments (modeled after customer payments repair).
     */
    private function repairMissingSupplierPaymentJournals(?int $branch_id = null): array
    {
        $result = ['posted' => 0, 'errors' => []];
        try {
            $branchSql = $branch_id ? ' AND sp.branch_id = :bid' : '';
            $bind = $branch_id ? [':bid' => $branch_id] : [];

            $this->db->query("
                SELECT sp.id, sp.payment_code, sp.payment_date, sp.amount, sp.payment_mode,
                       sp.bank_id, sp.supplier_id, sp.branch_id, sp.transaction_type
                FROM supplier_payments sp
                WHERE COALESCE(sp.is_reversed, 0) = 0
                  AND COALESCE(sp.journal_entry_id, 0) = 0
                  AND sp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  AND sp.amount > 0
                  {$branchSql}
                ORDER BY sp.id ASC
                LIMIT 50
            ");
            foreach ($bind as $k => $v) $this->db->bind($k, $v);
            $payments = $this->db->resultSet() ?: [];

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();

            foreach ($payments as $sp) {
                $paymentId = (int)$sp['id'];
                $amount = round((float)($sp['amount'] ?? 0), 2);
                if ($amount <= 0) continue;

                try {
                    $payload = [
                        'payment_code'  => $sp['payment_code'],
                        'payment_date'  => $sp['payment_date'],
                        'supplier_id'   => $sp['supplier_id'],
                        'amount'        => $amount,
                        'payment_mode'  => $sp['payment_mode'] ?? 'cash',
                        'bank_id'       => $sp['bank_id'] ? (int)$sp['bank_id'] : null,
                        'branch_id'     => $sp['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
                    ];
                    $jr = $journalService->postSupplierTransactionJournal($paymentId, $payload, $sp['transaction_type'] ?? 'payment');
                    if (!empty($jr['journal_entry_id'])) {
                        $this->db->query('UPDATE supplier_payments SET journal_entry_id = :jid WHERE id = :id');
                        $this->db->bind(':jid', (int)$jr['journal_entry_id']);
                        $this->db->bind(':id', $paymentId);
                        $this->db->execute();
                        $result['posted']++;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = ($sp['payment_code'] ?? $paymentId) . ': ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();
        }
        return $result;
    }
}