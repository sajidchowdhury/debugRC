# Branch Demand Implementation Plan — v1.10

## Overview

Cross-branch demand system for RC_ERP v2. Implements the full lifecycle of
inter-branch product supply requests: create → send → confirm receipt → settle → reverse.

**Terminology:**
- `from_branch_id` = requester (debtor) — the branch that NEEDS the products
- `to_branch_id` = supplier (creditor) — the branch that SUPPLIES the products

---

## Phase Status

| Phase | Description | Status |
|-------|-------------|--------|
| 1 | Database Schema & Models | ✅ COMPLETED |
| 2 | Core Demand Lifecycle (create, send, delete, reject) | ✅ COMPLETED |
| 3 | Intercompany Accounting (GL posting, ledger, settlement) | ✅ COMPLETED |
| 4 | FIFO Settlement (bank payments + money transfers) | ✅ COMPLETED |
| 5 | Receipt Confirmation (warehouse manager sign-off) | ✅ COMPLETED |
| 6 | Weekly Audit Report | ✅ COMPLETED |
| 7 | Price Range Handling & Repricing Logic | ✅ COMPLETED |
| 8 | Anti-Gaming & Accountability Controls | ✅ COMPLETED |
| 9 | UI, Views & Frontend | ✅ COMPLETED |
| 10 | API Routes, Test Coverage & Shadow Mode | ✅ COMPLETED |

---

## Phase 10 — API Routes, Test Coverage & Shadow Mode

### 10.1 API Routes (REST API for mobile/AI sidecar)

**Files created:**
- `app/Http/Resources/Api/V1/BranchDemand/BranchDemandResource.php`
- `app/Http/Resources/Api/V1/BranchDemand/BranchDemandItemResource.php`
- `app/Http/Controllers/Api/V1/BranchDemand/BranchDemandApiController.php`

**Routes registered in `routes/api.php`:**

| Method | Endpoint | Description | Role |
|--------|----------|-------------|------|
| GET | /api/v1/branch-demands | List demands (paginated, filterable) | any |
| POST | /api/v1/branch-demands | Create demand | admin, manager, warehouse_manager |
| GET | /api/v1/branch-demands/{id} | Show demand detail | any |
| POST | /api/v1/branch-demands/{id}/send | Send goods | admin, manager, warehouse_manager |
| POST | /api/v1/branch-demands/{id}/confirm-receipt | Confirm receipt | admin, manager, warehouse_manager |
| POST | /api/v1/branch-demands/{id}/reverse | Reverse demand | admin, manager |
| POST | /api/v1/branch-demands/{id}/reject | Reject demand | admin, manager, warehouse_manager |
| DELETE | /api/v1/branch-demands/{id} | Delete demand | admin, manager |
| POST | /api/v1/branch-demands/{id}/reprice | Reprice demand | admin, manager |
| GET | /api/v1/branch-demands/outstanding | Outstanding balances | any |
| GET | /api/v1/branch-demands/ledger-history | Ledger history | any |
| GET | /api/v1/branch-demands/settlement-preview | Settlement preview | any |
| GET | /api/v1/branch-demands/{id}/audit | Audit trail | any |
| GET | /api/v1/branch-demands/warehouses/{branchId} | Warehouses for branch | any |
| GET | /api/v1/branch-demands/product-stock/{pid}/{bid} | Product stock | any |

**Rate limits:** Reads 60 req/min, writes 30 req/min (mirrors WarehouseTransfer pattern)

**Branch isolation:** `api.auth` + `set.api.branch` middleware + RLS on `branch_demands`

### 10.2 Shadow Mode (parallel-run comparison with legacy system)

**Files created:**
- `database/migrations/2026_07_29_000019_create_shadow_demand_comparisons_table.php`
- `config/branch_demand_shadow.php`
- `app/Services/BranchDemand/BranchDemandShadowService.php`
- `app/Http/Controllers/Admin/BranchDemandShadowController.php`
- `resources/views/admin/branch-demand-shadow/index.blade.php`
- `resources/views/admin/branch-demand-shadow/comparisons.blade.php`
- `resources/views/admin/branch-demand-shadow/detail.blade.php`
- `resources/views/admin/branch-demand-shadow/cutover.blade.php`

**Shadow mode states:**
- OFF: No comparison. Normal operation.
- PASSIVE: Laravel primary, legacy comparison after each operation.
- ACTIVE: Both systems process simultaneously, legacy is gold reference.

**Cutover readiness:** Zero diffs for 7 consecutive days → ready for cutover.

**Routes (admin/branch-demand-shadow):**
- GET / — Dashboard overview
- GET /comparisons — Comparison results (paginated, filtered)
- GET /comparisons/{id} — Single comparison detail
- GET /cutover — Cutover readiness report
- POST /run-comparison — Trigger batch comparison
- POST /purge — Purge old records

### 10.3 Test Coverage

**Test helper created:**
- `tests/Helpers/InsertsBranchDemandDependencies.php`

**Unit tests created:**
- `tests/Unit/BranchDemand/BranchDemandAuditLoggerTest.php` — 8 tests
- `tests/Unit/BranchDemand/BranchDemandServiceTest.php` — 8 tests
- `tests/Unit/BranchDemand/BranchIntercompanyServiceTest.php` — 4 tests
- `tests/Unit/BranchDemand/BranchDemandRepricingServiceTest.php` — 5 tests
- `tests/Unit/BranchDemand/BranchDemandAuditServiceTest.php` — 8 tests

**Feature tests created:**
- `tests/Feature/BranchDemand/BranchDemandApiTest.php` — 14 tests

**Test coverage areas:**
- Audit logger: log() writes, no-op on zero demand_id, actor resolution, IP/user-agent, rollback, trail queries, critical actions
- Service: create demand, same-branch rejection, delete draft, reject, confirm receipt, reverse blocked until receipt, audit trail
- Intercompany: outstanding balances, ledger history, settlement preview, FIFO settlement
- Repricing: repricing history, rejection for non-received/reversed, new total below settled, sale price range check
- Audit service: checklist health checks, per-demand audit, reconciliation, anti-gaming flags, stale outstanding
- API: list/show/create/delete/reject/reverse/reprice/outstanding/warehouses/audit + RBAC (salesman blocked, warehouse_manager allowed)

---

## File Inventory (Phase 1-10)

### Models
- `app/Models/BranchDemand.php`
- `app/Models/BranchDemandItem.php`
- `app/Models/BranchDemandRepricing.php`
- `app/Models/BranchDemandMoneyTransferSettlement.php`
- `app/Models/BranchDemandCustomerPaymentSettlement.php`

### Services
- `app/Services/BranchDemand/BranchDemandService.php`
- `app/Services/BranchDemand/BranchIntercompanyService.php`
- `app/Services/BranchDemand/BranchDemandRepricingService.php`
- `app/Services/BranchDemand/BranchDemandWeeklyReportService.php`
- `app/Services/BranchDemand/BranchDemandAuditLogger.php`
- `app/Services/BranchDemand/BranchDemandAuditService.php`
- `app/Services/BranchDemand/BranchDemandShadowService.php`

### Controllers
- `app/Http/Controllers/Admin/BranchDemandController.php`
- `app/Http/Controllers/Admin/BranchDemandReportController.php`
- `app/Http/Controllers/Admin/BranchDemandShadowController.php`
- `app/Http/Controllers/Api/V1/BranchDemand/BranchDemandApiController.php`

### API Resources
- `app/Http/Resources/Api/V1/BranchDemand/BranchDemandResource.php`
- `app/Http/Resources/Api/V1/BranchDemand/BranchDemandItemResource.php`

### Request Classes
- `app/Http/Requests/BranchDemand/StoreBranchDemandRequest.php`
- `app/Http/Requests/BranchDemand/SendBranchDemandRequest.php`
- `app/Http/Requests/BranchDemand/ReverseBranchDemandRequest.php`
- `app/Http/Requests/BranchDemand/RejectBranchDemandRequest.php`
- `app/Http/Requests/BranchDemand/ConfirmReceiptRequest.php`
- `app/Http/Requests/BranchDemand/RepriceBranchDemandRequest.php`

### Migrations
- `database/migrations/2026_07_29_000010_align_branch_demands_table.php`
- `database/migrations/2026_07_29_000011_align_branch_demand_items_table.php`
- `database/migrations/2026_07_29_000012_add_demand_reference_types_to_stock_transactions.php`
- `database/migrations/2026_07_29_000013_create_branch_ledger_table.php`
- `database/migrations/2026_07_29_000014_create_branch_demand_money_transfer_settlements_table.php`
- `database/migrations/2026_07_29_000015_create_branch_demand_customer_payment_settlements_table.php`
- `database/migrations/2026_07_29_000016_create_branch_demand_repricing_table.php`
- `database/migrations/2026_07_29_000017_create_branch_demand_audit_log_table.php`
- `database/migrations/2026_07_29_000018_add_branch_demand_sidebar_menu.php`
- `database/migrations/2026_07_29_000019_create_shadow_demand_comparisons_table.php`

### Views
- `resources/views/admin/branch-demands/index.blade.php`
- `resources/views/admin/branch-demands/create.blade.php`
- `resources/views/admin/branch-demands/show.blade.php`
- `resources/views/admin/branch-demands/pending.blade.php`
- `resources/views/admin/branch-demands/pending-receipt.blade.php`
- `resources/views/admin/branch-demands/weekly-report.blade.php`
- `resources/views/admin/branch-demands/price-range-comparison.blade.php`
- `resources/views/admin/branch-demands/checklist.blade.php`
- `resources/views/admin/branch-demands/audit.blade.php`
- `resources/views/admin/branch-demands/reconcile.blade.php`
- `resources/views/admin/branch-demand-shadow/index.blade.php`
- `resources/views/admin/branch-demand-shadow/comparisons.blade.php`
- `resources/views/admin/branch-demand-shadow/detail.blade.php`
- `resources/views/admin/branch-demand-shadow/cutover.blade.php`

### Config
- `config/branch_demand_shadow.php`

### Tests
- `tests/Helpers/InsertsBranchDemandDependencies.php`
- `tests/Unit/BranchDemand/BranchDemandAuditLoggerTest.php`
- `tests/Unit/BranchDemand/BranchDemandServiceTest.php`
- `tests/Unit/BranchDemand/BranchIntercompanyServiceTest.php`
- `tests/Unit/BranchDemand/BranchDemandRepricingServiceTest.php`
- `tests/Unit/BranchDemand/BranchDemandAuditServiceTest.php`
- `tests/Feature/BranchDemand/BranchDemandApiTest.php`
