<?php

namespace App\Services\Purchase;

use Illuminate\Support\Facades\DB;

/**
 * Purchase Audit Service — Phase 6.
 *
 * Port of legacy app/models/PurchaseAuditModel.php.
 *
 * Produces a 12-section health-check report covering the entire purchase
 * ecosystem: master data, transactions, stock SSOT, GL journal links,
 * ledger accounts, and reporting. Each item has a status of:
 *   - 'pass'  (green check)  — invariant verified
 *   - 'warn'  (yellow)       — soft issue, may need review
 *   - 'fail'  (red)          — hard invariant violated, action required
 *   - 'info'  (blue)         — reference / informational note
 *
 * The report also includes 3 detail tables for follow-up:
 *   - negative_stocks          — warehouse_stock rows below zero
 *   - missing_grn_journals     — received GRNs without journal_entry_id
 *   - missing_return_journals  — non-reversed returns without journal_entry_id
 *
 * Branch scoping: admin can pass ?branch_id=0 to run across all branches;
 * non-admins always run against their session branch only (the controller
 * enforces this; here we just accept the resolved branchId).
 */
class PurchaseAuditService
{
    protected ?int $branchId;

    public function __construct(?int $branchId = null)
    {
        $this->branchId = $branchId;
    }

    /**
     * Build the full report.
     *
     * @return array{
     *   sections: array<int, array{id:string,title:string,icon:string,items:array<int, array>}>
     *   summary: array{pass:int,warn:int,fail:int,info:int,total:int}
     *   ran_at: string,
     *   branch_id: ?int,
     *   negative_stocks: array,
     *   missing_grn_journals: array,
     *   missing_return_journals: array
     * }
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
                    default:     $info++; break;
                }
            }
        }

        return [
            'sections'                => $sections,
            'summary'                 => [
                'pass'  => $pass,
                'warn'  => $warn,
                'fail'  => $fail,
                'info'  => $info,
                'total' => $pass + $warn + $fail + $info,
            ],
            'ran_at'                  => now()->format('Y-m-d H:i:s'),
            'branch_id'               => $this->branchId,
            'negative_stocks'         => $this->getNegativeStockRows(),
            'missing_grn_journals'    => $this->getGrnsMissingJournalRows(),
            'missing_return_journals' => $this->getReturnsMissingJournalRows(),
        ];
    }

    // =====================================================================
    // 1. Purchase module scope (informational)
    // =====================================================================

    private function sectionModuleScope(): array
    {
        return [
            'id'    => 'scope',
            'title' => 'Purchase module scope',
            'icon'  => 'fa-sitemap',
            'items' => [
                $this->item('scope_masters', 'Master data', 'Products (SKU), suppliers, warehouses/branches — required before PO/GRN.', 'info', null, 'admin/products'),
                $this->item('scope_transactions', 'Transactions', 'PO → GRN (direct or from PO) → purchase return; optional supplier payment/settlement.', 'info', null, 'admin/purchase-orders'),
                $this->item('scope_stock', 'Inventory impact', 'Only GRN (IN), GRN cancel (OUT), good return (OUT), return reverse (IN). PO alone does not move stock.', 'info', null, 'admin/stock-transactions'),
                $this->item('scope_gl', 'Accounting impact', 'GRN/return post to GL (inventory + supplier payable). Payments use supplier_ledger + bank/cash.', 'info', null, 'admin/journal-entries'),
                $this->item('scope_reports', 'Reporting', 'Purchase history, returns, supplier-wise purchase, payable aging, stock reports.', 'info', null, 'admin/reports'),
            ],
        ];
    }

    // =====================================================================
    // 2. Products
    // =====================================================================

    private function sectionProducts(): array
    {
        $inactiveOnGrn = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            INNER JOIN products p ON p.id = pri.product_id
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              AND COALESCE(p.is_active, true) = false
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $inactiveOnPo = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_order_items poi
            INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
            INNER JOIN products p ON p.id = poi.product_id
            WHERE po.created_at >= (CURRENT_DATE - INTERVAL '365 days')
              AND COALESCE(p.is_active, true) = false
              " . $this->branchFilter('po.branch_id') . "
        ");

        $orphanGrnProduct = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              AND NOT EXISTS (SELECT 1 FROM products p WHERE p.id = pri.product_id)
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $purchasedSkus = $this->scalarCount("
            SELECT COUNT(DISTINCT pri.product_id) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $activeNoGroup = $this->scalarCount("
            SELECT COUNT(*) AS c FROM products p
            WHERE p.is_active = true AND (p.group_id IS NULL OR p.group_id = 0)
        ");

        return [
            'id'    => 'products',
            'title' => 'Products (purchase SKUs)',
            'icon'  => 'fa-cube',
            'items' => [
                $this->item('prod_master', 'Product master is shared', 'Same products table used for sales and purchase. PO/GRN/return lines reference product_id; rates on GRN update moving average via StockService.', 'info', null, 'admin/products'),
                $this->item('prod_active', 'Prefer active products on new docs', 'Inactive products should not be added to new PO/GRN lines (UI should filter active SKUs).', 'info', null, 'admin/products'),
                $this->item('prod_group', 'Active products have a group', 'Every active SKU should have product_groups assigned.', $activeNoGroup === 0 ? 'pass' : 'warn', $activeNoGroup === 0 ? 'OK' : "{$activeNoGroup} SKU(s) missing group", 'admin/product-groups'),
                $this->item('prod_purchased_count', 'Distinct products purchased (last 12 mo)', 'Count of unique product_id on confirmed GRNs in period.', $purchasedSkus > 0 ? 'pass' : 'warn', $purchasedSkus > 0 ? "{$purchasedSkus} SKU(s)" : 'No confirmed GRN lines in period', 'admin/purchase-receives'),
                $this->item('prod_inactive_grn', 'No inactive products on confirmed GRNs', 'Confirmed GRN lines should not reference deactivated products.', $inactiveOnGrn === 0 ? 'pass' : 'warn', $inactiveOnGrn === 0 ? 'OK' : "{$inactiveOnGrn} line(s) with inactive product"),
                $this->item('prod_inactive_po', 'No inactive products on PO lines', 'PO lines in period should reference active products.', $inactiveOnPo === 0 ? 'pass' : 'warn', $inactiveOnPo === 0 ? 'OK' : "{$inactiveOnPo} PO line(s) with inactive product"),
                $this->item('prod_orphan_grn', 'GRN lines have valid product_id', 'Every purchase_receive_items.product_id must exist in products.', $orphanGrnProduct === 0 ? 'pass' : 'fail', $orphanGrnProduct === 0 ? 'OK' : "{$orphanGrnProduct} line(s) missing product"),
            ],
        ];
    }

    // =====================================================================
    // 3. Suppliers
    // =====================================================================

    private function sectionSuppliers(): array
    {
        $activeSuppliers = $this->scalarCount("SELECT COUNT(*) AS c FROM suppliers WHERE is_active = true");

        $grnNoSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'confirmed'
              AND COALESCE(pr.supplier_id, 0) = 0
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $grnInactiveSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receives pr
            LEFT JOIN suppliers s ON s.id = pr.supplier_id
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              AND (s.id IS NULL OR COALESCE(s.is_active, true) = false)
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $poInactiveSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.created_at >= (CURRENT_DATE - INTERVAL '365 days')
              AND (s.id IS NULL OR COALESCE(s.is_active, true) = false)
              " . $this->branchFilter('po.branch_id') . "
        ");

        $directNoSupplier = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'confirmed'
              AND COALESCE(pr.purchase_order_id, 0) = 0
              AND COALESCE(pr.supplier_id, 0) = 0
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('pr.branch_id') . "
        ");

        return [
            'id'    => 'suppliers',
            'title' => 'Suppliers',
            'icon'  => 'fa-truck',
            'items' => [
                $this->item('sup_master', 'Supplier master (Supplier module)', 'Create/edit suppliers; mobile uniqueness; soft deactivate via is_active. Used on PO, direct GRN, returns, and payments.', 'info', null, 'admin/suppliers'),
                $this->item('sup_active_pool', 'Active suppliers available', 'At least one active supplier should exist for purchase operations.', $activeSuppliers > 0 ? 'pass' : 'warn', $activeSuppliers > 0 ? "{$activeSuppliers} active" : 'No active suppliers', 'admin/suppliers'),
                $this->item('sup_grn_required', 'Confirmed GRNs have supplier_id', 'Every confirmed GRN must link to a supplier (including direct purchase).', $grnNoSupplier === 0 ? 'pass' : 'fail', $grnNoSupplier === 0 ? 'OK' : "{$grnNoSupplier} GRN(s) without supplier"),
                $this->item('sup_direct_purchase', 'Direct GRN includes supplier', 'Direct purchase (no PO) still requires supplier_id on the GRN header.', $directNoSupplier === 0 ? 'pass' : 'fail', $directNoSupplier === 0 ? 'OK' : "{$directNoSupplier} direct GRN(s) missing supplier"),
                $this->item('sup_grn_active', 'GRNs use active suppliers', 'Confirmed GRNs should not reference missing or inactive suppliers.', $grnInactiveSupplier === 0 ? 'pass' : 'warn', $grnInactiveSupplier === 0 ? 'OK' : "{$grnInactiveSupplier} GRN(s) with inactive/missing supplier"),
                $this->item('sup_po_active', 'POs use active suppliers', 'Purchase orders should reference valid active suppliers.', $poInactiveSupplier === 0 ? 'pass' : 'warn', $poInactiveSupplier === 0 ? 'OK' : "{$poInactiveSupplier} PO(s) with inactive/missing supplier"),
            ],
        ];
    }

    // =====================================================================
    // 4. Warehouses & branches
    // =====================================================================

    private function sectionWarehouses(): array
    {
        $invalidWarehouse = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              AND (
                  COALESCE(pri.warehouse_id, 0) = 0
                  OR NOT EXISTS (SELECT 1 FROM warehouses w WHERE w.id = pri.warehouse_id)
              )
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $inactiveWarehouse = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            INNER JOIN warehouses w ON w.id = pri.warehouse_id
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              AND COALESCE(w.is_active, true) = false
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $branchMismatch = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM purchase_receive_items pri
            INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
            INNER JOIN warehouses w ON w.id = pri.warehouse_id
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              AND w.branch_id IS NOT NULL
              AND pr.branch_id IS NOT NULL
              AND w.branch_id != pr.branch_id
              " . $this->branchFilter('pr.branch_id') . "
        ");

        return [
            'id'    => 'warehouses',
            'title' => 'Warehouses & branches',
            'icon'  => 'fa-warehouse',
            'items' => [
                $this->item('wh_required', 'GRN lines require warehouse_id', 'Each receive line posts stock IN to a specific warehouse; moving average is per warehouse_stock row.', 'info', null, 'admin/warehouses'),
                $this->item('wh_branch', 'Warehouse belongs to branch', 'Prefer warehouses where warehouse.branch_id matches GRN branch_id.', 'info', null, 'admin/warehouses'),
                $this->item('wh_valid', 'GRN lines have valid warehouse', 'warehouse_id must exist on warehouses table.', $invalidWarehouse === 0 ? 'pass' : 'fail', $invalidWarehouse === 0 ? 'OK' : "{$invalidWarehouse} line(s) invalid/missing warehouse"),
                $this->item('wh_active', 'GRN uses active warehouses', 'Confirmed lines should not target deactivated warehouses.', $inactiveWarehouse === 0 ? 'pass' : 'warn', $inactiveWarehouse === 0 ? 'OK' : "{$inactiveWarehouse} line(s) on inactive warehouse"),
                $this->item('wh_branch_match', 'Warehouse branch matches GRN branch', 'Cross-branch receive into wrong warehouse should be zero.', $branchMismatch === 0 ? 'pass' : 'warn', $branchMismatch === 0 ? 'OK' : "{$branchMismatch} line(s) branch mismatch"),
            ],
        ];
    }

    // =====================================================================
    // 5. Stock SSOT
    // =====================================================================

    private function sectionStockSsot(): array
    {
        $neg = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_stock ws
            WHERE ws.qty < -0.0001
            " . $this->branchWarehouseFilter('ws.warehouse_id') . "
        ");

        $orphanMovements = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions st
            WHERE st.reference_type IN ('purchase_receive','purchase_return')
              AND st.reference_id > 0
              AND NOT EXISTS (
                  SELECT 1 FROM warehouse_stock ws
                  WHERE ws.warehouse_id = st.warehouse_id AND ws.product_id = st.product_id
              )
            " . $this->branchWarehouseFilter('st.warehouse_id') . "
        ");

        $recentPurchaseMoves = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions st
            WHERE st.reference_type IN ('purchase_receive','purchase_return')
              AND st.transaction_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchWarehouseFilter('st.warehouse_id') . "
        ");

        return [
            'id'    => 'stock',
            'title' => 'Stock — single source of truth',
            'icon'  => 'fa-boxes',
            'items' => [
                $this->item('stock_ssot', 'Read: warehouse_stock (qty + avg_cost)', 'On-hand quantity and moving-average cost live only in warehouse_stock. Same SSOT as sales.', 'info', 'Do not use GRN line qty as on-hand stock.'),
                $this->item('stock_grn_returnable', 'GRN return_qty is not on-hand stock', 'return_qty = received − returned_to_supplier on that GRN line. Caps supplier return; separate from warehouse_stock.', 'info', 'Return qty ≤ returnable AND ≤ warehouse available (Good).'),
                $this->item('stock_writer', 'Write: StockService only', 'PurchaseReceiveService and PurchaseReturnService use StockService::applyTransaction() — never direct UPDATE warehouse_stock.', 'info', 'Reference types: purchase_receive, purchase_return.'),
                $this->item('stock_moves_logged', 'Purchase stock movements logged (last 12 mo)', 'Confirms stock_transactions rows exist for purchase flows.', $recentPurchaseMoves > 0 ? 'pass' : 'warn', $recentPurchaseMoves > 0 ? "{$recentPurchaseMoves} movement(s)" : 'No purchase stock movements in period'),
                $this->item('stock_negative', 'No negative warehouse balances', 'warehouse_stock.qty must not go below zero.', $neg === 0 ? 'pass' : 'fail', $neg === 0 ? 'OK' : "{$neg} row(s) below zero — see table below"),
                $this->item('stock_orphan', 'Movements linked to warehouse_stock', 'Every purchase stock_transaction should have a matching warehouse_stock row.', $orphanMovements === 0 ? 'pass' : 'warn', $orphanMovements === 0 ? 'OK' : "{$orphanMovements} movement(s) without warehouse_stock"),
            ],
        ];
    }

    // =====================================================================
    // 6. Purchase Order
    // =====================================================================

    private function sectionPurchaseOrder(): array
    {
        $overReceived = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_order_items poi
            INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
            WHERE COALESCE(poi.received_qty, 0) > poi.qty + 0.0001
            " . $this->branchFilter('po.branch_id') . "
        ");

        $openPoLines = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_order_items poi
            INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
            WHERE po.status NOT IN ('cancelled', 'received')
              AND COALESCE(poi.received_qty, 0) < poi.qty - 0.0001
            " . $this->branchFilter('po.branch_id') . "
        ");

        return [
            'id'    => 'po',
            'title' => 'Purchase order',
            'icon'  => 'fa-file-invoice',
            'items' => [
                $this->item('po_no_stock', 'Create / cancel does not move stock', 'PO is planning only until a GRN is posted.', 'info', null, 'admin/purchase-orders'),
                $this->item('po_no_gl', 'No GL on draft PO', 'Supplier payable is recognized on GRN, not on PO.', 'info'),
                $this->item('po_cancel', 'Cancel = status only', 'Cancel sets status=cancelled; no hard delete. Audit trail preserved.', 'info'),
                $this->item('po_from_po', 'GRN from PO updates received_qty', 'PO-based receive increments purchase_order_items.received_qty per line.', 'info'),
                $this->item('po_direct', 'Direct GRN (no PO)', 'purchase_order_id NULL; supplier_id required on GRN header.', 'info', null, 'admin/purchase-receives/create'),
                $this->item('po_over_received', 'received_qty ≤ ordered qty', 'PO line received_qty cannot exceed ordered qty.', $overReceived === 0 ? 'pass' : 'fail', $overReceived === 0 ? 'OK' : "{$overReceived} line(s) over-received"),
                $this->item('po_open_lines', 'Open PO lines pending receive', 'Informational count of PO lines not fully received.', 'info', $openPoLines === 0 ? 'None open' : "{$openPoLines} line(s) still pending GRN"),
            ],
        ];
    }

    // =====================================================================
    // 7. GRN
    // =====================================================================

    private function sectionGrn(): array
    {
        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'confirmed'
              AND COALESCE(pr.journal_entry_id, 0) = 0
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $noStock = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            WHERE pr.status = 'confirmed'
              AND pr.receive_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('pr.branch_id') . "
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'purchase_receive' AND st.reference_id = pr.id AND st.qty > 0
              )
        ");

        $cancelNoJeRev = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_receives pr
            INNER JOIN journal_entries je ON je.id = pr.journal_entry_id
            WHERE pr.status = 'cancelled'
              AND COALESCE(je.is_reversed, false) = false
              " . $this->branchFilter('pr.branch_id') . "
        ");

        return [
            'id'    => 'grn',
            'title' => 'Goods received (GRN)',
            'icon'  => 'fa-dolly',
            'items' => [
                $this->item('grn_create_stock', 'Create → stock IN + log', 'StockService IN at receive rate; reference purchase_receive.', 'info', null, 'admin/purchase-receives'),
                $this->item('grn_create_gl', 'Create → Dr Inventory / Cr Supplier Payable', 'JournalPostingService posts GL on confirm.', 'info'),
                $this->item('grn_cancel_stock', 'Cancel → stock OUT + log', 'Reverses receive qty; blocks if active returns exist.', 'info'),
                $this->item('grn_cancel_gl', 'Cancel → reverse linked journal', 'JournalReversalService reverses on GRN journal_entry_id.', 'info'),
                $this->item('grn_missing_journal', 'Confirmed GRNs have journal (last 12 mo)', 'Active confirmed GRNs should have journal_entry_id.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} GRN(s) missing journal"),
                $this->item('grn_missing_stock', 'Confirmed GRNs have stock IN (last 12 mo)', 'Each confirmed GRN should have positive purchase_receive stock_transactions.', $noStock === 0 ? 'pass' : 'fail', $noStock === 0 ? 'OK' : "{$noStock} GRN(s) without stock IN"),
                $this->item('grn_cancel_journal', 'Cancelled GRNs reversed in GL', 'Cancelled GRN with journal should have is_reversed on original entry.', $cancelNoJeRev === 0 ? 'pass' : 'fail', $cancelNoJeRev === 0 ? 'OK' : "{$cancelNoJeRev} cancelled GRN(s) with unreversed journal"),
            ],
        ];
    }

    // =====================================================================
    // 8. Purchase Return
    // =====================================================================

    private function sectionPurchaseReturn(): array
    {
        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_returns prt
            WHERE COALESCE(prt.is_reversed, false) = false
              AND prt.return_date >= (CURRENT_DATE - INTERVAL '365 days')
              AND COALESCE(prt.journal_entry_id, 0) = 0
              " . $this->branchFilter('prt.branch_id') . "
        ");

        // Phase 5 invariant: Damage lines must NOT have stock movements.
        $damageWithStock = $this->scalarCount("
            SELECT COUNT(DISTINCT prt.id) AS c
            FROM purchase_returns prt
            INNER JOIN purchase_return_items pri ON pri.purchase_return_id = prt.id
            WHERE LOWER(COALESCE(pri.condition, 'good')) = 'damage'
              " . $this->branchFilter('prt.branch_id') . "
              AND EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'purchase_return'
                    AND st.reference_id = prt.id
                    AND st.product_id = pri.product_id
              )
        ");

        $noStockOut = $this->scalarCount("
            SELECT COUNT(DISTINCT prt.id) AS c
            FROM purchase_returns prt
            INNER JOIN purchase_return_items pri ON pri.purchase_return_id = prt.id
            WHERE COALESCE(prt.is_reversed, false) = false
              AND LOWER(COALESCE(pri.condition, 'good')) = 'good'
              AND pri.qty > 0
              " . $this->branchFilter('prt.branch_id') . "
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
            WHERE COALESCE(pri.return_qty, 0) > pri.qty + 0.0001
              " . $this->branchFilter('pr.branch_id') . "
        ");

        $reversedNoFlag = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_returns prt
            WHERE EXISTS (
                SELECT 1 FROM stock_transactions st
                WHERE st.reference_type = 'purchase_return' AND st.reference_id = prt.id AND st.is_reversed = true
            )
              AND COALESCE(prt.is_reversed, false) = false
              " . $this->branchFilter('prt.branch_id') . "
        ");

        $reversedNoJe = $this->scalarCount("
            SELECT COUNT(*) AS c FROM purchase_returns prt
            INNER JOIN journal_entries je ON je.id = prt.journal_entry_id
            WHERE COALESCE(prt.is_reversed, false) = true
              AND COALESCE(je.is_reversed, false) = false
              " . $this->branchFilter('prt.branch_id') . "
        ");

        return [
            'id'    => 'return',
            'title' => 'Purchase return',
            'icon'  => 'fa-undo-alt',
            'items' => [
                $this->item('prt_create_stock', 'Create (Good) → stock OUT + log', 'Moving avg at return; updates purchase_receive_items.return_qty.', 'info', null, 'admin/purchase-returns'),
                $this->item('prt_damage', 'Damage lines → no stock OUT', 'Damaged return qty does not reduce warehouse_stock (supplier claim only).', $damageWithStock === 0 ? 'pass' : 'fail', $damageWithStock === 0 ? 'OK' : "{$damageWithStock} return(s) with Damage lines but stock movements exist"),
                $this->item('prt_create_gl', 'Create → Dr Supplier Payable / Cr Inventory', 'JournalPostingService posts GL on confirm.', 'info'),
                $this->item('prt_reverse_stock', 'Reverse → restore from stock_transactions', 'Reads purchase_return OUT rows; restores qty at logged rate.', 'info'),
                $this->item('prt_reverse_gl', 'Reverse → reverseLinkedJournal', 'Restores returned_qty on GRN lines.', 'info'),
                $this->item('prt_missing_journal', 'Active returns have journal (last 12 mo)', 'Non-reversed returns should have journal_entry_id.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} return(s) missing journal"),
                $this->item('prt_missing_stock', 'Good returns have stock OUT', 'Active Good returns should have negative purchase_return movements.', $noStockOut === 0 ? 'pass' : 'fail', $noStockOut === 0 ? 'OK' : "{$noStockOut} return(s) missing stock OUT"),
                $this->item('prt_over_returned', 'return_qty ≤ received qty', 'purchase_receive_items.return_qty cannot exceed qty.', $overReturned === 0 ? 'pass' : 'fail', $overReturned === 0 ? 'OK' : "{$overReturned} line(s) over-returned"),
                $this->item('prt_reversal_flag', 'Reversal movements match is_reversed flag', 'Stock reversal requires is_reversed=true on the return header.', $reversedNoFlag === 0 ? 'pass' : 'warn', $reversedNoFlag === 0 ? 'OK' : "{$reversedNoFlag} return(s) with reversal stock but not flagged"),
                $this->item('prt_reversal_journal', 'Reversed returns reversed in GL', 'Reversed return should have is_reversed on linked journal.', $reversedNoJe === 0 ? 'pass' : 'warn', $reversedNoJe === 0 ? 'OK' : "{$reversedNoJe} reversed return(s) with unreversed journal"),
                $this->item('prt_slip', 'Printable Return slip available', 'Phase 6: GET admin/purchase-returns/{id}/slip — opens print-ready slip in a new tab.', 'info', null, 'admin/purchase-returns'),
            ],
        ];
    }

    // =====================================================================
    // 9. Supplier payments & due
    // =====================================================================

    private function sectionSupplierPayments(): array
    {
        $paymentsNoLedger = $this->scalarCount("
            SELECT COUNT(*) AS c FROM supplier_payments sp
            WHERE COALESCE(sp.is_reversed, false) = false
              AND sp.payment_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('sp.branch_id') . "
              AND NOT EXISTS (
                  SELECT 1 FROM supplier_ledger sl
                  WHERE sl.reference_id = sp.id
                    AND sl.reference_type IN ('payment', 'advance', 'receive')
              )
        ");

        $recentPayments = $this->scalarCount("
            SELECT COUNT(*) AS c FROM supplier_payments sp
            WHERE sp.payment_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('sp.branch_id') . "
        ");

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM supplier_payments sp
            WHERE COALESCE(sp.is_reversed, false) = false
              AND sp.payment_date >= (CURRENT_DATE - INTERVAL '365 days')
              " . $this->branchFilter('sp.branch_id') . "
              AND COALESCE(sp.journal_entry_id, 0) = 0
        ");

        $payRevUnreversed = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM supplier_payments sp
            INNER JOIN journal_entries je ON je.id = sp.journal_entry_id
            WHERE sp.is_reversed = true
              AND COALESCE(sp.journal_entry_id, 0) > 0
              AND COALESCE(je.is_reversed, false) = false
              " . $this->branchFilter('sp.branch_id') . "
        ");

        return [
            'id'    => 'payments',
            'title' => 'Supplier payments & due',
            'icon'  => 'fa-hand-holding-usd',
            'items' => [
                $this->item('pay_dual', 'Two payable views', 'GRN/return post supplier payable to GL (journal_entries). Supplier payments also write supplier_ledger running balance — reconcile both for month-end.', 'info'),
                $this->item('pay_module', 'Supplier Transaction module', 'Record payment/advance; optional branch demand settlement; reverse with reason. Posts to GL journal too.', 'info', null, 'admin/supplier-payments'),
                $this->item('pay_ledger_row', 'Payments have supplier_ledger row (last 12 mo)', 'Each active supplier_payment should create a supplier_ledger entry.', $paymentsNoLedger === 0 ? 'pass' : 'warn', $paymentsNoLedger === 0 ? 'OK' : "{$paymentsNoLedger} payment(s) without ledger row"),
                $this->item('pay_journal', 'Supplier payments have GL journal (last 12 mo)', 'Each active supplier payment should have a linked journal_entry_id.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} payment(s) missing journal"),
                $this->item('pay_rev_journal', 'Reversed payments reversed in GL', 'Reversed supplier_payment should reverse linked journal.', $payRevUnreversed === 0 ? 'pass' : 'warn', $payRevUnreversed === 0 ? 'OK' : "{$payRevUnreversed} reversed payment(s) with unreversed journal"),
                $this->item('pay_activity', 'Supplier payments in period', 'Informational: payments recorded in last 12 months.', $recentPayments > 0 ? 'pass' : 'info', $recentPayments > 0 ? "{$recentPayments} payment(s)" : 'No supplier payments in period (OK if paying later)'),
            ],
        ];
    }

    // =====================================================================
    // 10. GL journal link columns (informational)
    // =====================================================================

    private function sectionGlJournalLinks(): array
    {
        return [
            'id'    => 'gl_links',
            'title' => 'GL journal link columns',
            'icon'  => 'fa-link',
            'items' => [
                $this->item('gl_col_grn', 'purchase_receives.journal_entry_id', 'GRN: Dr Inventory / Cr Supplier Payable.', 'info', null, 'admin/purchase-receives'),
                $this->item('gl_col_return', 'purchase_returns.journal_entry_id', 'Return: Dr Supplier Payable / Cr Inventory.', 'info', null, 'admin/purchase-returns'),
                $this->item('gl_col_payment', 'supplier_payments.journal_entry_id', 'Supplier payment/advance via Supplier Transaction module.', 'info', null, 'admin/supplier-payments'),
            ],
        ];
    }

    // =====================================================================
    // 11. Ledger & accounts (GL)
    // =====================================================================

    private function sectionLedger(): array
    {
        $invLedgers = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'inventory' AND is_active = true");
        $apLedgers = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'ap' AND is_active = true");

        return [
            'id'    => 'ledger',
            'title' => 'Ledger & accounts (GL)',
            'icon'  => 'fa-book',
            'items' => [
                $this->item('gl_ap', 'Supplier payable (nature: ap)', 'Credited on GRN; debited on purchase return. Entity type supplier on journal lines.', 'info'),
                $this->item('gl_inv', 'Inventory (nature: inventory)', 'Debited on GRN; credited on purchase return (Good qty).', 'info'),
                $this->item('gl_inv_exists', 'Active inventory ledger configured', 'JournalPostingService requires at least one active inventory ledger.', $invLedgers > 0 ? 'pass' : 'fail', $invLedgers > 0 ? "{$invLedgers} ledger(s)" : 'Missing — GRN posting will fail', 'admin/ledgers'),
                $this->item('gl_ap_exists', 'Active supplier payable ledger configured', 'Required for GRN and purchase return journals.', $apLedgers > 0 ? 'pass' : 'fail', $apLedgers > 0 ? "{$apLedgers} ledger(s)" : 'Missing — GRN posting will fail', 'admin/ledgers'),
                $this->item('gl_trial', 'Reconcile with Trial Balance', 'Use Trial Balance and Payable Aging after period close.', 'info', null, 'admin/reports'),
            ],
        ];
    }

    // =====================================================================
    // 12. Reporting (informational)
    // =====================================================================

    private function sectionReports(): array
    {
        return [
            'id'    => 'reports',
            'title' => 'Purchase-related reports',
            'icon'  => 'fa-chart-bar',
            'items' => [
                $this->item('rpt_supplier_wise', 'Supplier-wise purchase', 'Spend aggregated per supplier.', 'info', null, 'admin/reports'),
                $this->item('rpt_payable_aging', 'Payable aging (suppliers)', 'Outstanding supplier balances from supplier_ledger.', 'info', null, 'admin/reports'),
                $this->item('rpt_product_move', 'Product movement', 'Includes purchase_receive / return movement types.', 'info', null, 'admin/reports'),
                $this->item('rpt_open_po', 'Open PO / pending receive', 'PO lines not fully GRN’d — qty, supplier, expected date. (Planned)', 'info'),
                $this->item('rpt_grn_register', 'GRN register (detailed)', 'Line-level receive register with warehouse, rate, tax columns. (Planned)', 'info'),
                $this->item('rpt_supplier_statement', 'Supplier statement (GL + ledger)', 'Opening, GRN, returns, payments, closing per supplier. (Planned)', 'info'),
                $this->item('rpt_product_purchase', 'Product-wise purchase analysis', 'Qty and value purchased per SKU, trend by period. (Planned)', 'info'),
                $this->item('rpt_po_variance', 'PO vs actual (rate/qty variance)', 'Compare PO rate/qty to GRN actuals. (Planned)', 'info'),
                $this->item('rpt_damage', 'Damaged / rejected purchase returns', 'Damage-condition returns without stock impact.', 'info', null, 'admin/purchase-returns'),
            ],
        ];
    }

    // =====================================================================
    // Detail tables
    // =====================================================================

    public function getNegativeStockRows(int $limit = 15): array
    {
        try {
            return DB::table('warehouse_stock as ws')
                ->leftJoin('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
                ->leftJoin('products as p', 'p.id', '=', 'ws.product_id')
                ->where('ws.qty', '<', -0.0001)
                ->when($this->branchId, function ($q, $bid) {
                    $q->whereExists(function ($sub) use ($bid) {
                        $sub->select(DB::raw(1))
                            ->from('warehouses as w2')
                            ->whereColumn('w2.id', 'ws.warehouse_id')
                            ->where('w2.branch_id', $bid);
                    });
                })
                ->select(
                    'ws.warehouse_id', 'ws.product_id',
                    'w.warehouse_name', 'p.product_name',
                    'ws.qty', 'ws.avg_cost'
                )
                ->orderBy('ws.qty', 'asc')
                ->limit($limit)
                ->get()
                ->map(fn($r) => (array) $r)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getGrnsMissingJournalRows(int $limit = 15): array
    {
        try {
            return DB::table('purchase_receives as pr')
                ->where('pr.status', 'confirmed')
                ->whereRaw('COALESCE(pr.journal_entry_id, 0) = 0')
                ->where('pr.receive_date', '>=', now()->subDays(365)->toDateString())
                ->when($this->branchId, fn($q, $bid) => $q->where('pr.branch_id', $bid))
                ->select('pr.id', 'pr.receive_code', 'pr.receive_date', 'pr.total_amount')
                ->orderBy('pr.receive_date', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn($r) => (array) $r)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getReturnsMissingJournalRows(int $limit = 15): array
    {
        try {
            return DB::table('purchase_returns as prt')
                ->join('purchase_receives as pr', 'pr.id', '=', 'prt.purchase_receive_id')
                ->whereRaw('COALESCE(prt.is_reversed, false) = false')
                ->whereRaw('COALESCE(prt.journal_entry_id, 0) = 0')
                ->where('prt.return_date', '>=', now()->subDays(365)->toDateString())
                ->when($this->branchId, fn($q, $bid) => $q->where('prt.branch_id', $bid))
                ->select('prt.id', 'prt.return_code', 'prt.return_date', 'prt.total_amount', 'pr.receive_code')
                ->orderBy('prt.return_date', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn($r) => (array) $r)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function scalarCount(string $sql): int
    {
        try {
            // PURCHASING-3 (G-040): use parameter binding instead of string
            // concatenation. branchFilter() and branchWarehouseFilter() emit
            // '?' placeholders; we count them and supply $this->branchId for
            // each. When branchId is null, the filter methods return '' (no
            // '?'), so bindings is empty and DB::selectOne gets an empty array.
            $placeholderCount = substr_count($sql, '?');
            $bindings = $placeholderCount > 0
                ? array_fill(0, $placeholderCount, $this->branchId)
                : [];
            $row = DB::selectOne($sql, $bindings);
            return $row ? (int) ($row->c ?? 0) : 0;
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * PURCHASING-3 (G-040): returns a SQL fragment with a '?' placeholder
     * instead of concatenating (int) $this->branchId directly into the string.
     *
     * The (int) cast previously prevented SQL injection, but the pattern
     * violated the project's coding standards (use prepared statements) and
     * tripped static analyzers. The placeholder is bound by scalarCount(),
     * which counts '?' characters in the full SQL and passes $this->branchId
     * for each.
     *
     * Returns '' when branchId is null (admin cross-branch view) — no
     * placeholder, no binding.
     */
    private function branchFilter(string $column): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND {$column} = ?";
    }

    /**
     * PURCHASING-3 (G-040): same refactor as branchFilter — uses '?'
     * placeholder for the branch_id value in the EXISTS subquery.
     */
    private function branchWarehouseFilter(string $warehouseColumn): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND EXISTS (
            SELECT 1 FROM warehouses w
            WHERE w.id = {$warehouseColumn} AND w.branch_id = ?
        )";
    }

    /**
     * Build a single audit item row.
     */
    private function item(
        string $id,
        string $title,
        string $expected,
        string $status,
        ?string $detail = null,
        ?string $route = null
    ): array {
        return [
            'id'       => $id,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
            'url'      => $route ? url($route) : null,
        ];
    }
}
