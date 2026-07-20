---
Task ID: 1
Agent: Main
Task: Implement LISTEN/NOTIFY for real-time updates (Task 31)

Work Log:
- Explored full codebase structure: Laravel 12 + PostgreSQL 16 + Redis 7 + PHP 8.4
- Identified existing notification system: AJAX polling via notification.js, NotificationService with database/broadcast channels
- Found no LISTEN/NOTIFY, no WebSocket/SSE, no broadcasting infrastructure existed
- Created PostgreSQL migration with 7 trigger functions + rcerp_notify() helper
- Created ListenNotifyService for PG ↔ Redis bridging
- Created SseController with Redis List polling (PHP-FPM compatible)
- Created ListenNotifyWorker Artisan command (long-running PG LISTEN process)
- Updated NotificationService to also emit pg_notify on dispatch
- Rewrote notification.js with SSE support + polling fallback
- Added SSE location block to Nginx config (buffering off, timeout 300s)
- Added rcerp_queue_worker and rcerp_listen_notify containers to docker-compose.yml
- Updated sales-module-documentation.md with comprehensive Section 9 (10 subsections)
- Marked Task 31 as ✅ DONE in Phase 1E table
- Added LISTEN/NOTIFY row to Feature Matrix
- Updated document version to 1.2

Stage Summary:
- 8 new/modified files created for LISTEN/NOTIFY implementation
- PostgreSQL triggers: 7 channels covering sales_invoices, sales_challans, sales_returns, customer_payments, stock_transactions, journal_entries, system_policies
- Architecture: DB Trigger → pg_notify → ListenNotifyWorker → Redis Lists → SseController → Browser EventSource
- Graceful degradation: SSE → polling fallback if LISTEN/NOTIFY unavailable
- Documentation fully updated in sales-module-documentation.md

---
Task ID: 2
Agent: Main
Task: Implement CTE-based complex queries (today's summary, AR aging) — Task 32

Work Log:
- Read existing ReportService (695 lines, 10 report methods, no CTEs)
- Read existing DashboardController (391 lines, 6+ separate SQL queries for KPIs)
- Read existing ReportController (553 lines, 18 report endpoints)
- Identified optimization opportunities: 6+ queries → 1 CTE, PHP running balance → SQL window function
- Created migration with 4 PostgreSQL CTE functions:
  - rcerp_today_summary: 11 CTEs (replaces 6+ DashboardController queries)
  - rcerp_ar_aging_cte: 5 CTEs (proper sub-ledger aging + GL reconciliation)
  - rcerp_general_ledger_cte: 4 CTEs (SQL window function running balance)
  - rcerp_gross_margin_cte: 6 CTEs (per-item COGS via stock_transactions)
- Created CteReportService (PHP wrapper calling PG functions, JSON decode)
- Updated ReportController with 4 new CTE methods + CteReportService injection
- Added 4 new routes to web.php
- Created 4 Blade views (today_summary_cte, ar_aging_cte, general_ledger_cte, gross_margin_cte)
- Updated sales-module-documentation.md: Task 32 ✅, Section 10 added (10 subsections), Feature Matrix updated, version 1.3

Stage Summary:
- 6 new/modified files for CTE implementation
- 4 PostgreSQL CTE functions (total 27 CTEs across all functions)
- Key improvements: 12x fewer roundtrips for dashboard, SQL running balance, accurate per-item COGS, sub-ledger accurate AR aging
- CTE reports coexist with original reports (new -cte routes, not replacements)
---
Task ID: 33
Agent: Main Agent
Task: Add EXCLUDE constraint for invoice_payment_allocations

Work Log:
- Explored existing schema (05_purchase.sql), model, service, and docs
- Identified 4 gaps: missing CHECK, missing EXCLUDE, missing FK on payment_id, no over-allocation guard
- Created migration 2025_01_21_000003 with 4-layer approach:
  1. CHECK (allocated_amount > 0)
  2. EXCLUDE USING gist (invoice_id WITH =, payment_id WITH =)
  3. FK payment_id → customer_payments(id) ON DELETE CASCADE
  4. CONSTRAINT TRIGGER trg_ipa_no_overallocation (SUM guard)
- Updated 05_purchase.sql to reflect constraints in CREATE TABLE
- Updated InvoicePaymentAllocation model docblock
- Updated sales-module-documentation.md: v1.4, Task 33 ✅, §7.12 revised, §4.1 updated, Section 11 added

Stage Summary:
- 4 database-level constraints now protect invoice_payment_allocations
- Original numrange approach replaced with composable 4-layer design (original was incorrect for partial payments)
- btree_gist extension required and included in migration
- Documentation version bumped to 1.4
