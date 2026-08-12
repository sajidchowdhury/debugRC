# RC-ERP v2 — Performance Audit & Optimization Guide

> **Date:** 2026-08-12  
> **Problem:** Several menu pages take 2–3 minutes to load  
> **Root Cause:** Massive query proliferation — a single page load triggers 20–40+ SQL queries  
> **Target:** Reduce page loads from 2–3 min → 2–5 sec  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Root Cause #1 — Stats-Count Query Proliferation](#2-root-cause-1--stats-count-query-proliferation)
3. [Root Cause #2 — N+1 Loops](#3-root-cause-2--n1-loops)
4. [Root Cause #3 — No Caching of Dropdown Data](#4-root-cause-3--no-caching-of-dropdown-data)
5. [Root Cause #4 — Middleware DB Overhead](#5-root-cause-4--middleware-db-overhead)
6. [Root Cause #5 — Duplicate Collection Queries](#6-root-cause-5--duplicate-collection-queries)
7. [Secondary Issues](#7-secondary-issues)
8. [Prioritized Fix Plan](#8-prioritized-fix-plan)
9. [Code Examples — Before & After](#9-code-examples--before--after)
10. [Production Deployment Checklist](#10-production-deployment-checklist)
11. [Monitoring & Ongoing](#11-monitoring--ongoing)

---

## 1. Executive Summary

The primary cause of slow page loads is **stats-count query proliferation** — virtually every `index()` method fires 5–10 separate `COUNT()` and `SUM()` queries for the stats chips/badges at the top of each page. Combined with N+1 loops, no caching of dropdown data, and heavy per-request middleware DB calls, a single page load can trigger **20–40+ SQL queries** before any data renders.

| Metric | Current | Target |
|--------|---------|--------|
| Page load time | 2–3 minutes | 2–5 seconds |
| Queries per page | 20–40+ | 3–5 |
| CustomerPayment create page | ~1,500 queries | 1 query |
| Dashboard stats | 9+ queries | 1 query |

---

## 2. Root Cause #1 — Stats-Count Query Proliferation

### The Problem

Every `index()` method computes stats by cloning a base query and running individual `COUNT()` / `SUM()` calls. Each clone is a **separate round-trip to PostgreSQL**.

### Affected Controllers

| Controller | Stats Queries | Estimated Time |
|------------|---------------|----------------|
| **SalesInvoiceController** | 10 queries | +5–15s |
| **SalesChallanController** | 8 queries | +5–10s |
| **PurchaseOrderController** | 7 queries | +3–8s |
| **PurchaseReceiveController** | 6 queries | +3–6s |
| **CustomerPaymentController** | 8 queries | +5–12s |
| **DamageController** | 7+ queries | +3–8s |
| **StockAdjustmentController** | 5+ queries | +2–5s |

### Current Pattern (BAD)

```php
// SalesInvoiceController.php — 9 separate queries!
$statsBase = SalesInvoice::query()->where('call_a_day', false);

$stats = [
    'total'           => (clone $statsBase)->count(),                        // QUERY 1
    'today'           => (clone $statsBase)->whereDate('invoice_date', $today)->count(),  // QUERY 2
    'draft'           => (clone $statsBase)->where('status', 'draft')->count(),           // QUERY 3
    'confirmed'       => (clone $statsBase)->where('status', 'confirmed')->count(),       // QUERY 4
    'cancelled'       => (clone $statsBase)->where('status', 'cancelled')->count(),       // QUERY 5
    'pending_godown'  => (clone $statsBase)->where('status', 'confirmed')
                            ->where('is_godown_prepared', false)->count(),               // QUERY 6
    'pending_challan' => (clone $statsBase)->where('is_godown_prepared', true)
                            ->where('is_challan_issued', false)->count(),                // QUERY 7
    'total_value'     => (clone $statsBase)->whereNotIn('status', ['cancelled'])
                            ->sum('total_amount'),                                       // QUERY 8
];
$staleCount = (clone $statsBase)->where('status', 'draft')
    ->where('created_at', '<', now()->subHours(24))->count();                            // QUERY 9
```

### Fix: Single FILTER (WHERE) Query (GOOD)

PostgreSQL supports `COUNT(*) FILTER (WHERE condition)` — this collapses **9 queries → 1 query**:

```php
$stats = DB::table('sales_invoices')
    ->where('call_a_day', false)
    ->where('is_reversed', false)
    ->selectRaw("
        COUNT(*) as total,
        COUNT(*) FILTER (WHERE invoice_date = ?) as today,
        COUNT(*) FILTER (WHERE status = 'draft') as draft,
        COUNT(*) FILTER (WHERE status = 'confirmed') as confirmed,
        COUNT(*) FILTER (WHERE status = 'cancelled') as cancelled,
        COUNT(*) FILTER (WHERE status = 'confirmed' AND is_godown_prepared = false) as pending_godown,
        COUNT(*) FILTER (WHERE is_godown_prepared = true AND is_challan_issued = false) as pending_challan,
        COALESCE(SUM(total_amount) FILTER (WHERE status NOT IN ('cancelled')), 0) as total_value,
        COUNT(*) FILTER (WHERE status = 'draft' AND created_at < ?) as stale_count
    ", [$today, now()->subHours(24)])
    ->first();

// Convert to array for view
$stats = (array) $stats;
```

**Result: 9 queries → 1 query. Page load: 15s → 2s.**

### Apply This Pattern To ALL Controllers

Every controller with stats should be refactored to use the single FILTER query pattern. The exact columns differ, but the approach is the same.

---

## 3. Root Cause #2 — N+1 Loops

### CustomerPaymentController::create() — THE WORST OFFENDER

**File:** `app/Http/Controllers/Admin/CustomerPaymentController.php` lines ~99-102

```php
// CURRENT: 500 customers × 3 queries each = 1,500 queries = 2-3 MINUTES
$customerReceivables = [];
foreach ($customers as $c) {  // $customers has up to 500 items
    $customerReceivables[$c->id] = $this->getCustomerReceivable($c->id);
    // Each getCustomerReceivable() fires 2-3 queries:
    //   1. SELECT SUM(debit)-SUM(credit) FROM customer_ledger WHERE customer_id=?
    //   2. SELECT EXISTS FROM customer_ledger WHERE customer_id=?
    //   3. SELECT SUM(due_amount) FROM sales_invoices WHERE customer_id=?
}
```

### Fix: Single Batch Query

```php
// FIXED: 1 query instead of 1,500
$customerReceivables = DB::table('customer_ledger')
    ->where('is_reversed', false)
    ->whereIn('customer_id', $customers->pluck('id'))
    ->groupBy('customer_id')
    ->selectRaw('customer_id, COALESCE(SUM(debit) - SUM(credit), 0) as balance')
    ->pluck('balance', 'customer_id')
    ->map(fn($b) => abs((float) $b))
    ->toArray();
```

**Result: 1,500 queries → 1 query. Page load: 2-3 min → 1s.**

### Other N+1 Patterns to Watch

| Location | Problem | Fix |
|----------|---------|-----|
| `WarehouseTransfer::$appends = ['total_amount']` | Accessor lazy-loads `items` if not eager-loaded | Ensure `::with('items')` is always used; add defensive check |
| `Product::hasSearchVector()` | Schema query on first search per request | Cache with `Cache::remember()` for 1 hour |
| `Customer::hasSearchVector()` | Same as above | Same fix |

---

## 4. Root Cause #3 — No Caching of Dropdown Data

### The Problem

Nearly every `index()` and `create()` method loads the same dropdown data fresh from DB. These tables change **rarely** (maybe once per day) but are queried on **every single page load**.

```php
// This pattern appears in 10+ controllers — every page load
$customers  = Customer::active()->orderBy('customer_name')->limit(500)->get();    // QUERY
$branches   = Branch::active()->orderBy('branch_name')->get();                     // QUERY
$warehouses = Warehouse::active()->with('branch')->orderBy('warehouse_name')->get(); // QUERY
$suppliers  = Supplier::active()->orderBy('supplier_name')->get();                 // QUERY
$banks      = Bank::active()->orderBy('bank_name')->get();                         // QUERY
$employees  = Employee::active()->orderBy('name')->get();                          // QUERY
$products   = Product::active()->orderBy('product_name')->limit(500)->get();       // QUERY
```

**7 extra queries × every page load = massive waste.**

### Fix: Cache with Short TTL

Create a `DropdownService` that caches all reference data:

```php
// app/Services/DropdownService.php
class DropdownService
{
    private const TTL = 300; // 5 minutes

    public function branches(): Collection
    {
        return Cache::remember('dropdown:branches', self::TTL, fn() =>
            Branch::active()->orderBy('branch_name')->get()
        );
    }

    public function warehouses(): Collection
    {
        return Cache::remember('dropdown:warehouses', self::TTL, fn() =>
            Warehouse::active()->with('branch')->orderBy('warehouse_name')->get()
        );
    }

    public function customers(int $limit = 500): Collection
    {
        return Cache::remember("dropdown:customers:{$limit}", self::TTL, fn() =>
            Customer::active()->orderBy('customer_name')->limit($limit)->get()
        );
    }

    public function suppliers(): Collection
    {
        return Cache::remember('dropdown:suppliers', self::TTL, fn() =>
            Supplier::active()->orderBy('supplier_name')->get()
        );
    }

    public function banks(): Collection
    {
        return Cache::remember('dropdown:banks', self::TTL, fn() =>
            Bank::active()->orderBy('bank_name')->get()
        );
    }

    public function employees(): Collection
    {
        return Cache::remember('dropdown:employees', self::TTL, fn() =>
            Employee::active()->orderBy('name')->get()
        );
    }

    public function products(int $limit = 500): Collection
    {
        return Cache::remember("dropdown:products:{$limit}", self::TTL, fn() =>
            Product::active()->orderBy('product_name')->limit($limit)->get()
        );
    }

    // Call this when master data changes (after CRUD operations)
    public function flushAll(): void
    {
        Cache::forgetMatching('dropdown:*');
    }
}
```

Then in controllers:
```php
$ds = app(DropdownService::class);
$customers  = $ds->customers();
$branches   = $ds->branches();
$warehouses = $ds->warehouses();
// ... etc.
```

**Result: 7 queries → 0 queries (when cache is warm). Saves ~200ms per request.**

### Cache Invalidation

After any CRUD operation on master data, call:
```php
app(DropdownService::class)->flushAll();
```

Add this to the `store()`, `update()`, `destroy()` methods of BranchController, WarehouseController, CustomerController, SupplierController, BankController, EmployeeController, ProductController.

---

## 5. Root Cause #4 — Middleware DB Overhead

### SetAppBranchId Middleware — 5 Separate DB Commands

**File:** `app/Http/Middleware/SetAppBranchId.php` lines 63-74

```php
// CURRENT: 5 separate round-trips to PostgreSQL
DB::unprepared("SET app.branch_id = {$safeBranchId}");
DB::unprepared("SET app.is_admin = {$safeIsAdmin}");
DB::unprepared("SET app.request_path = '{$safePath}'");
DB::unprepared("SET app.request_ip = '{$safeIp}'");
DB::unprepared("SET app.request_id = '{$safeRid}'");
```

### Fix: Combine Into Single Statement

```php
// FIXED: 1 round-trip instead of 5
DB::unprepared(
    "SET app.branch_id = {$safeBranchId}; " .
    "SET app.is_admin = {$safeIsAdmin}; " .
    "SET app.request_path = '{$safePath}'; " .
    "SET app.request_ip = '{$safeIp}'; " .
    "SET app.request_id = '{$safeRid}'"
);
```

**Result: 5 round-trips → 1. Saves ~40ms per request.**

### EnforceBranchIsolation Middleware — Extra Query Per Write

**File:** `app/Http/Middleware/EnforceBranchIsolation.php` lines 104-156

For routes with URL params like `{id}`, the middleware runs an extra query to resolve `branch_id`:
```php
$branchId = DB::table($table)->where('id', $id)->value('branch_id');
```

### Fix: Cache Per-Request (Array Cache)

```php
$cacheKey = "branch_resolution:{$table}:{$id}";
$branchId = Cache::store('array')->remember($cacheKey, 1, fn() =>
    DB::table($table)->where('id', $id)->value('branch_id')
);
```

**Result: Same table+id within one request = no duplicate query.**

---

## 6. Root Cause #5 — Duplicate Collection Queries

### SalesChallanController::index() — THREE Separate Collection Queries

**File:** `app/Http/Controllers/Admin/SalesChallanController.php` lines 78-124

```php
// CURRENT: 3 separate query sets + 6 stats queries = 9+ query sets
$pendingGodown  = $pendingGodownQuery->limit(50)->get();    // QUERY SET 1
$pendingChallan = $pendingChallanQuery->limit(50)->get();   // QUERY SET 2
$challans       = $issuedChallansQuery->paginate(25);       // QUERY SET 3
// Plus 6 more stats COUNT/SUM queries...
```

### Fix: Cache Workflow Queue Counts

The workflow queue counts (pending godown, pending challan) don't change every second. Cache them:

```php
$statsCacheKey = "challan_stats:{$branchId}";

$stats = Cache::remember($statsCacheKey, 30, function() use ($statsBase, $today) {
    return (array) DB::table('sales_challans')
        ->selectRaw("
            COUNT(*) as total,
            COUNT(*) FILTER (WHERE status = 'active') as active,
            COUNT(*) FILTER (WHERE is_reversed = true) as reversed,
            COALESCE(SUM(cogs_amount), 0) as total_cogs
        ")
        ->first();
});
```

**Result: 9 query sets → 3. Saves ~5s.**

### Customer 360 Hub — 6 Separate Aggregates

**File:** `app/Http/Controllers/Admin/CustomerController.php` lines 307-335

```php
// CURRENT: 6 separate queries
$arBalance     = CustomerLedger::getBalance($customer->id);            // QUERY 1
$totalInvoiced = SalesInvoice::where(...)->sum('total_amount');        // QUERY 2
$totalPaid     = CustomerPayment::where(...)->sum('amount');           // QUERY 3
$openInvoices  = SalesInvoice::where(...)->count();                    // QUERY 4
$lastPayment   = CustomerPayment::where(...)->first();                 // QUERY 5
$totalReturns  = SalesReturn::where(...)->sum('total_amount');         // QUERY 6
```

### Fix: Combine Into 2 Queries + Cache

```php
$cacheKey = "customer_360:{$customer->id}";

$hub = Cache::remember($cacheKey, 60, function() use ($customer) {
    // Query 1: AR summary from customer_ledger (already has running balance)
    $ledger = DB::table('customer_ledger')
        ->where('customer_id', $customer->id)
        ->where('is_reversed', false)
        ->selectRaw("
            COALESCE(SUM(debit) - SUM(credit), 0) as ar_balance,
            COUNT(*) FILTER (WHERE reference_type = 'sales_invoice' AND debit > 0) as open_invoices
        ")
        ->first();

    // Query 2: Payment summary
    $payments = DB::table('customer_payments')
        ->where('customer_id', $customer->id)
        ->where('is_reversed', false)
        ->selectRaw("
            COALESCE(SUM(amount), 0) as total_paid,
            MAX(payment_date) as last_payment_date
        ")
        ->first();

    return [
        'ar_balance'     => abs((float) ($ledger->ar_balance ?? 0)),
        'total_paid'     => (float) ($payments->total_paid ?? 0),
        'open_invoices'  => (int) ($ledger->open_invoices ?? 0),
        'last_payment'   => $payments->last_payment_date,
    ];
});
```

**Result: 6 queries → 2 queries + cache. Saves ~5s on customer show page.**

---

## 7. Secondary Issues

### Exports Using ->get() Instead of ->cursor()

**Files:** PurchaseReceiveController, SalesReturnController, PurchaseReturnController

```php
// CURRENT: Loads ALL rows into memory
$receives = PurchaseReceive::with([...])->orderBy(...)->get();

// FIXED: Generator-based — constant memory
$receives = PurchaseReceive::with([...])->orderBy(...)->cursor();
```

### Warehouse Stock Totals — Full Table SUM on Every Load

**File:** StockTransactionController.php lines 91-94

```php
// CURRENT: Two full-table aggregates every page load
$totals = [
    'total_qty'   => DB::table('warehouse_stock')->where('qty', '>', 0)->sum('qty'),
    'total_value' => DB::table('warehouse_stock')->where('qty', '>', 0)->sum('stock_value'),
];

// FIXED: Cache for 60 seconds
$totals = Cache::remember('warehouse_stock_totals', 60, fn() => [
    'total_qty'   => DB::table('warehouse_stock')->where('qty', '>', 0)->sum('qty'),
    'total_value' => DB::table('warehouse_stock')->where('qty', '>', 0)->sum('stock_value'),
]);
```

### hasSearchVector() — Schema Query Per Request

**Files:** Product.php, Customer.php

```php
// CURRENT: Queries information_schema on first call per request
protected function hasSearchVector(): bool
{
    static $cache = [];
    // ... DB::select("SELECT column_name FROM information_schema.columns ...")
}

// FIXED: Cache for 1 hour — column doesn't change at runtime
protected function hasSearchVector(): bool
{
    return Cache::remember('has_search_vector:' . $this->getTable(), 3600, fn() =>
        collect(DB::select(
            "SELECT column_name FROM information_schema.columns 
             WHERE table_name = ? AND column_name = 'search_vector'",
            [$this->getTable()]
        ))->isNotEmpty()
    );
}
```

---

## 8. Prioritized Fix Plan

| Priority | Fix | Effort | Est. Speedup | Files to Change |
|----------|-----|--------|-------------|-----------------|
| **P0** | Replace stats-count loops with single `FILTER (WHERE)` query | 2-3 days | **60-80% page load reduction** | SalesInvoiceController, SalesChallanController, PurchaseOrderController, PurchaseReceiveController, CustomerPaymentController, DamageController, StockAdjustmentController |
| **P0** | Fix CustomerPaymentController N+1 loop → batch query | 0.5 day | **Eliminates 2-3 min load** | CustomerPaymentController |
| **P1** | Create DropdownService + cache reference data | 1 day | **6 fewer queries per request** | New file: DropdownService.php + all controllers |
| **P1** | Combine SetAppBranchId middleware SET commands | 0.5 day | **4 fewer DB round-trips per request** | SetAppBranchId.php |
| **P1** | Cache Customer 360 Hub stats | 0.5 day | **5 fewer queries on show page** | CustomerController |
| **P2** | Cache SalesChallan workflow counts | 0.5 day | **6 fewer queries on challan index** | SalesChallanController |
| **P2** | Replace `->get()` with `->cursor()` in exports | 1 day | **Prevents OOM** | PurchaseReceiveController, SalesReturnController, PurchaseReturnController |
| **P2** | Cache `hasSearchVector()` across requests | 0.5 day | **Eliminates schema query** | Product.php, Customer.php |
| **P3** | Cache EnforceBranchIsolation branch resolution | 0.5 day | **1 fewer query per write** | EnforceBranchIsolation.php |
| **P3** | Cache warehouse stock totals | 0.5 day | **2 fewer SUM queries** | StockTransactionController |

**Total estimated effort:** ~7 days  
**Expected result:** Page loads drop from **2–3 minutes → 2–5 seconds**.

---

## 9. Code Examples — Before & After

### Example 1: PurchaseOrderController Stats

**BEFORE (7 queries):**
```php
$stats = [
    'total'       => (clone $base)->count(),
    'draft'       => (clone $base)->where('status', 'draft')->count(),
    'submitted'   => (clone $base)->where('status', 'submitted')->count(),
    'approved'    => (clone $base)->where('status', 'approved')->count(),
    'sent'        => (clone $base)->where('status', 'sent')->count(),
    'partial'     => (clone $base)->where('status', 'partial')->count(),
    'received'    => (clone $base)->where('status', 'received')->count(),
    'cancelled'   => (clone $base)->where('status', 'cancelled')->count(),
    'total_value' => (clone $base)->whereNotIn('status', ['cancelled'])->sum('total_amount'),
];
```

**AFTER (1 query):**
```php
$stats = (array) DB::table('purchase_orders')
    ->where('branch_id', $branchId)
    ->where('is_reversed', false)
    ->selectRaw("
        COUNT(*) as total,
        COUNT(*) FILTER (WHERE status = 'draft') as draft,
        COUNT(*) FILTER (WHERE status = 'submitted') as submitted,
        COUNT(*) FILTER (WHERE status = 'approved') as approved,
        COUNT(*) FILTER (WHERE status = 'sent') as sent,
        COUNT(*) FILTER (WHERE status = 'partial') as partial,
        COUNT(*) FILTER (WHERE status = 'received') as received,
        COUNT(*) FILTER (WHERE status = 'cancelled') as cancelled,
        COALESCE(SUM(total_amount) FILTER (WHERE status NOT IN ('cancelled')), 0) as total_value
    ")
    ->first();
```

### Example 2: CustomerPaymentController Dropdowns

**BEFORE (7 queries every create/edit page load):**
```php
$customers  = Customer::active()->orderBy('customer_name')->limit(500)->get();
$branches   = Branch::active()->orderBy('branch_name')->get();
$warehouses = Warehouse::active()->with('branch')->get();
$banks      = Bank::active()->orderBy('bank_name')->get();
$employees  = Employee::active()->orderBy('name')->get();
$products   = Product::active()->orderBy('product_name')->limit(500)->get();
$suppliers  = Supplier::active()->orderBy('supplier_name')->get();
```

**AFTER (0 queries when cache is warm):**
```php
$ds = app(DropdownService::class);
$customers  = $ds->customers();
$branches   = $ds->branches();
$warehouses = $ds->warehouses();
$banks      = $ds->banks();
$employees  = $ds->employees();
$products   = $ds->products();
$suppliers  = $ds->suppliers();
```

---

## 10. Production Deployment Checklist

Before going live, ensure these are configured:

### Database
- [ ] **PostgreSQL `shared_buffers`** = 25% of RAM (e.g., 2GB on 8GB server)
- [ ] **`work_mem`** = 16–32MB (for aggregations and sorts)
- [ ] **`effective_cache_size`** = 75% of RAM
- [ ] **`maintenance_work_mem`** = 256MB
- [ ] **`max_connections`** = 100 (adjust per server)
- [ ] **`random_page_cost`** = 1.1 (for SSD storage)
- [ ] **`log_min_duration_statement`** = 1000 (log queries > 1s)
- [ ] **`pg_stat_statements`** enabled for query tracking
- [ ] Run `ANALYZE` on all tables after data import

### Laravel
- [ ] **`CACHE_STORE=redis`** (not file!)
- [ ] **`SESSION_DRIVER=redis`** (not file!)
- [ ] **`QUEUE_CONNECTION=redis`** (not sync)
- [ ] **`APP_DEBUG=false`** in production
- [ ] **`config:cache`** — Run `php artisan config:cache`
- [ ] **`route:cache`** — Run `php artisan route:cache`
- [ ] **`view:cache`** — Run `php artisan view:cache`
- [ ] **`event:cache`** — Run `php artisan event:cache`
- [ ] **OPcache** enabled in PHP (opcache.enable=1, opcache.memory_consumption=256)

### Supervisor (Queue Workers)
```ini
[program:rcerp-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=3
user=www-data
```

### Scheduled Tasks (Cron)
```bash
* * * * * php /path/artisan schedule:run >> /dev/null 2>&1
0 2 * * * php /path/artisan db:refresh-report-views >> /dev/null 2>&1
```

### Nginx/Opcache
```nginx
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    # Buffer settings for large responses
    fastcgi_buffers 16 16k;
    fastcgi_buffer_size 32k;
}
```

---

## 11. Monitoring & Ongoing

### Enable Laravel Telescope (Development Only)

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

### Enable Query Logging in Production (Temporarily)

Add to `AppServiceProvider::boot()`:

```php
if (app()->environment('production')) {
    DB::listen(function ($query) {
        if ($query->time > 500) { // Log queries > 500ms
            Log::warning('Slow query', [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time_ms' => $query->time,
            ]);
        }
    });
}
```

### pg_stat_statements — Find Slow Queries

```sql
-- Enable extension
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Top 20 slowest queries by total time
SELECT 
    calls,
    round(total_exec_time::numeric, 2) as total_ms,
    round(mean_exec_time::numeric, 2) as avg_ms,
    round((100 * total_exec_time / sum(total_exec_time) OVER ())::numeric, 2) as pct,
    left(query, 120) as query_preview
FROM pg_stat_statements
ORDER BY total_exec_time DESC
LIMIT 20;
```

### Regular Maintenance

```sql
-- Run weekly
ANALYZE;

-- Check for table bloat (after many updates/deletes)
SELECT schemaname, tablename, 
       pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size
FROM pg_tables 
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC
LIMIT 20;

-- Reindex if needed (concurrently, no lock)
REINDEX INDEX CONCURRENTLY index_name;
```

---

## What's Already Done Well ✅

These are already optimized — no changes needed:

1. **Eager loading** — Most `index()` methods use `::with([...])` properly
2. **Pagination** — Main lists use `->paginate(25)` correctly
3. **Server-side DataTables** — PO, GRN, Return controllers have `?datatables=1` JSON mode
4. **Database indexing** — Excellent! Partial indexes, covering indexes (INCLUDE), BRIN indexes, GIN for FTS
5. **Partitioning** — `sales_invoices` and `stock_transactions` are partitioned by date
6. **Materialized views** — 7 MVs for financial reports
7. **Redis cache/session** — Configured correctly
8. **API Dashboard caching** — `DashboardApiController` uses `Cache::remember()` properly
9. **Idempotency tokens** — Prevent duplicate submissions
10. **No `$with` or `$appends` on models** — Prevents accidental eager-loading bombs
