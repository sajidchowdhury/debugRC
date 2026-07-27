<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Phase 18 — Interactive API documentation page.
 *
 * Serves a self-contained HTML page at GET /api/docs that lists every
 * /api/v1/* endpoint (14 routes in total) with:
 *   - HTTP method + URL
 *   - Description
 *   - Required role (admin / any authenticated)
 *   - Request body JSON schema
 *   - Response JSON schema with example
 *   - Error responses (401, 403, 404, 422)
 *
 * The page also includes a "Try it" panel with a Bearer token input so
 * developers can call any endpoint directly from the browser using
 * vanilla JavaScript (fetch API). No external dependencies — all CSS
 * + JS is inlined.
 *
 * The route is registered in routes/api.php WITHOUT the api.auth
 * middleware so the docs page itself is publicly accessible. All API
 * calls triggered from the Try-It panel still require a Bearer token
 * (the developer pastes one into the input field).
 */
class ApiDocController extends Controller
{
    /**
     * Render the interactive API documentation page.
     */
    public function index(): Response
    {
        $endpoints = $this->endpoints();

        $html = $this->renderHtml($endpoints);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * The complete list of /api/v1/* endpoints exposed by this app.
     *
     * Each entry has:
     *   - method       HTTP verb (GET/POST/PUT/DELETE)
     *   - path         URL path (relative to /api/v1)
     *   - description  Human-readable description
     *   - role         Required role (null = any authenticated user)
     *   - params       Query parameters (array of name => description)
     *   - body         Request body JSON schema (null for GET/DELETE)
     *   - response     Response JSON schema (string)
     *   - example      Example response JSON (string)
     *   - errors       Array of error codes + descriptions
     *
     * @return array<int, array<string,mixed>>
     */
    private function endpoints(): array
    {
        return [
            // ---------- Branches ----------
            [
                'method'      => 'GET',
                'path'        => '/branches',
                'description' => 'List branches with pagination + optional search. Excludes soft-deleted branches.',
                'role'        => null,
                'params'      => [
                    'q'        => 'Search term (matches branch_code or branch_name, case-insensitive ILIKE). Optional.',
                    'page'     => 'Page number (default 1).',
                    'per_page' => 'Page size (default 25, max 100).',
                ],
                'body'        => null,
                'response'    => <<<'JSON'
{
  "data": [
    { "id": 1, "branch_code": "HO", "branch_name": "Head Office", "address": "...", "phone": "...", "email": "...", "is_active": true, "created_at": "...", "updated_at": "..." }
  ],
  "meta": { "current_page": 1, "last_page": 5, "per_page": 25, "total": 120, "from": 1, "to": 25 }
}
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/branches/{id}',
                'description' => 'Show a single branch by ID (includes soft-deleted branches).',
                'role'        => null,
                'params'      => ['id' => 'Branch ID (integer, required, must be > 0).'],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": { "id": 1, "branch_code": "HO", "branch_name": "Head Office", "address": "...", "phone": "...", "email": "...", "is_active": true, "created_at": "...", "updated_at": "...", "deleted_at": null } }
JSON,
                'example'     => null,
                'errors'      => [
                    '401' => 'Missing or invalid Bearer token.',
                    '404' => 'Branch with the given ID was not found.',
                ],
            ],
            [
                'method'      => 'POST',
                'path'        => '/branches',
                'description' => 'Create a new branch. The branch_code is uppercased + trimmed before insert.',
                'role'        => 'admin',
                'params'      => [],
                'body'        => <<<'JSON'
{
  "branch_code": "REQ | string | max 20 | regex ^[A-Za-z0-9\\-_.]+$ | unique",
  "branch_name": "REQ | string | max 100",
  "address":     "nullable | string",
  "phone":       "nullable | string | max 20",
  "email":       "nullable | email | max 100",
  "is_active":   "nullable | boolean (default true)"
}
JSON,
                'response'    => <<<'JSON'
{ "data": { "id": 10, "branch_code": "NEW-001", "branch_name": "New Branch", ... }, "message": "Branch created." }
JSON,
                'example'     => null,
                'errors'      => [
                    '401' => 'Missing or invalid Bearer token.',
                    '403' => 'Authenticated user is not an admin.',
                    '422' => 'Validation error or duplicate branch_code.',
                ],
            ],
            [
                'method'      => 'PUT',
                'path'        => '/branches/{id}',
                'description' => 'Update an existing branch. Only the supplied fields are updated.',
                'role'        => 'admin',
                'params'      => ['id' => 'Branch ID (integer, required).'],
                'body'        => <<<'JSON'
{
  "branch_code": "sometimes | string | max 20 | unique:branches,branch_code,{id}",
  "branch_name": "sometimes | string | max 100",
  "address":     "nullable | string",
  "phone":       "nullable | string | max 20",
  "email":       "nullable | email | max 100",
  "is_active":   "nullable | boolean"
}
JSON,
                'response'    => <<<'JSON'
{ "data": { "id": 1, "branch_code": "HO", "branch_name": "Updated Name", ... }, "message": "Branch updated." }
JSON,
                'example'     => null,
                'errors'      => [
                    '401' => 'Missing or invalid Bearer token.',
                    '403' => 'Authenticated user is not an admin.',
                    '404' => 'Branch with the given ID was not found.',
                    '422' => 'Validation error or duplicate branch_code.',
                ],
            ],
            [
                'method'      => 'DELETE',
                'path'        => '/branches/{id}',
                'description' => 'Deactivate (soft-delete) a branch. Blocked if the branch has active warehouses, employees, or open sales invoices.',
                'role'        => 'admin',
                'params'      => ['id' => 'Branch ID (integer, required).'],
                'body'        => null,
                'response'    => <<<'JSON'
{ "message": "Branch deactivated." }
JSON,
                'example'     => null,
                'errors'      => [
                    '400' => 'Cannot deactivate — outstanding dependencies (warehouses / employees / open invoices). Response includes a `blockers` array.',
                    '401' => 'Missing or invalid Bearer token.',
                    '403' => 'Authenticated user is not an admin.',
                    '404' => 'Branch with the given ID was not found.',
                ],
            ],

            // ---------- Dashboard ----------
            [
                'method'      => 'GET',
                'path'        => '/dashboard',
                'description' => 'Summary stats for the mobile home screen: active master-data counts + today\'s sales + today\'s collection.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{
  "data": {
    "counts": {
      "active_branches":   4,
      "active_warehouses":  8,
      "active_products":    1200,
      "active_customers":   340,
      "active_suppliers":   45,
      "active_employees":   120
    },
    "today": {
      "date":          "2025-01-19",
      "invoice_count": 12,
      "total_sales":   45000.50,
      "collection":    32000.00
    }
  }
}
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/dashboard/sales-trend',
                'description' => 'Last 7 days of sales totals, gap-filled with zeros for days with no sales. Useful for chart libraries.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{
  "data": [
    { "date": "2025-01-13", "invoice_count": 8,  "total_sales": 21000.00 },
    { "date": "2025-01-14", "invoice_count": 0,  "total_sales": 0.00 }
  ],
  "meta": { "range_days": 7, "start": "2025-01-13", "end": "2025-01-19" }
}
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/dashboard/top-products',
                'description' => 'Top 10 products by revenue over the last 30 days. Aggregates sales_invoice_items joined with non-reversed invoices.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{
  "data": [
    { "product_id": 5, "product_code": "P-001", "product_name": "Widget A", "qty_sold": 120.0, "revenue": 36000.00 }
  ],
  "meta": { "range_days": 30, "since": "2024-12-20" }
}
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],

            // ---------- Lookups ----------
            [
                'method'      => 'GET',
                'path'        => '/lookups/branches',
                'description' => 'Active branches only — slim id + code + name. Used by mobile pickers.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": [ { "id": 1, "branch_code": "HO", "branch_name": "Head Office" } ] }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/lookups/warehouses',
                'description' => 'Active warehouses, optionally filtered by branch_id.',
                'role'        => null,
                'params'      => ['branch_id' => 'Optional integer. Filters warehouses assigned to this branch.'],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": [ { "id": 1, "warehouse_code": "WH-01", "warehouse_name": "Main Godown", "branch_id": 1 } ] }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/lookups/products',
                'description' => 'Active products only — id + code + name + unit + sales_rate.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": [ { "id": 1, "product_code": "P-001", "product_name": "Widget A", "unit": "Pcs", "sales_rate": 300.00 } ] }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/lookups/customers',
                'description' => 'Active customers — id + code + name + mobile.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": [ { "id": 1, "customer_code": "CUS-2025-000001", "customer_name": "Acme Corp", "mobile": "01712345678" } ] }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/lookups/suppliers',
                'description' => 'Active suppliers — id + code + name + mobile.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": [ { "id": 1, "supplier_code": "SUP-000001", "supplier_name": "Acme Supplier", "mobile": "01812345678" } ] }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/lookups/ledgers',
                'description' => 'Active ledgers (chart of accounts) — id + code + name + account_type + ledger_nature.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": [ { "id": 1, "ledger_code": "1000", "ledger_name": "Cash in Hand", "account_type": "Asset", "ledger_nature": "cash_bank" } ] }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],

            // ---------- Stock Take (Phase 11) ----------
            [
                'method'      => 'GET',
                'path'        => '/stock-take/sessions',
                'description' => 'List stock-take sessions (paginated + filtered). RLS-scoped to the authenticated user\'s branch (admins see all).',
                'role'        => null,
                'params'      => [
                    'status'     => 'Filter by status: draft, counting, submitted, approved, posted, cancelled, reversed.',
                    'branch_id'  => 'Filter by branch (admin only — non-admins are locked to their own).',
                    'from_date'  => 'Session date ≥ (Y-m-d).',
                    'to_date'    => 'Session date ≤ (Y-m-d).',
                    'search'     => 'Search session_code or notes (ILIKE).',
                    'per_page'   => 'Page size (default 25, max 100).',
                ],
                'body'        => null,
                'response'    => <<<'JSON'
{
  "data": [
    { "id": 1, "session_code": "ST-2025-001", "session_date": "2025-08-15", "status": "counting", "branch": { "id": 1, "name": "Head Office", "code": "HO" }, "progress": { "total_wh": 2, "completed_wh": 1, "pct": 50 }, "freeze_outbound": false, "journal_entry_id": null }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 }
}
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Missing or invalid Bearer token.'],
            ],
            [
                'method'      => 'POST',
                'path'        => '/stock-take/sessions',
                'description' => 'Create a stock-take session (draft). The service validates warehouse existence + freeze-outbound overlap.',
                'role'        => null,
                'params'      => [],
                'body'        => <<<'JSON'
{
  "branch_id": 1,
  "session_date": "2025-08-15",
  "warehouse_ids": [1, 2],
  "freeze_outbound": false,
  "count_scope": "full",
  "notes": "Monthly cycle count"
}
JSON,
                'response'    => <<<'JSON'
{ "message": "Session ST-2025-001 created.", "data": { "id": 1, "session_code": "ST-2025-001", "status": "draft", "progress": { "total_wh": 2, "completed_wh": 0, "pct": 0 } } }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '422' => 'Validation error or service guard failed (e.g. overlapping frozen session).'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/stock-take/sessions/{id}',
                'description' => 'Show session detail with warehouses + items + approval + reversal context.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{ "data": { "id": 1, "session_code": "ST-2025-001", "status": "posted", "warehouses": [ { "id": 1, "status": "completed" } ], "progress": { "total_wh": 1, "completed_wh": 1, "pct": 100 }, "journal_entry_id": 42, "is_reversed": false, "re_open_count": 0 } }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '404' => 'Session not found (or RLS-blocked — wrong branch).'],
            ],
            [
                'method'      => 'PUT',
                'path'        => '/stock-take/sessions/{id}/counts/{warehouseId}',
                'description' => 'Save physical counts for a warehouse. Body is a map of product_id → physical_qty. Optional reasons map.',
                'role'        => null,
                'params'      => [],
                'body'        => <<<'JSON'
{
  "counts": { "10": 48, "11": 0, "12": 33.5 },
  "reasons": { "10": "Damaged stock found during count" }
}
JSON,
                'response'    => <<<'JSON'
{ "message": "3 product count(s) saved.", "updated": 3 }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '422' => 'Session not in counting state, or product not in the warehouse\'s item set.'],
            ],
            [
                'method'      => 'POST',
                'path'        => '/stock-take/sessions/{id}/post',
                'description' => 'Post the session — apply stock variances + post the GL journal entry (Dr/Cr Inventory vs Shrinkage/Surplus + Phase 9 revaluation). Admin/manager only.',
                'role'        => 'admin, manager',
                'params'      => [],
                'body'        => <<<'JSON'
{ "post_reason": "Approved by manager after audit review." }
JSON,
                'response'    => <<<'JSON'
{ "message": "Session ST-2025-001 posted. Variances applied + GL entry created.", "data": { "id": 1, "status": "posted", "journal_entry_id": 42 } }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '403' => 'Forbidden (requires admin/manager).', '422' => 'Session not in a postable state, or approval required but not approved.'],
            ],
            [
                'method'      => 'POST',
                'path'        => '/stock-take/sessions/{id}/reverse',
                'description' => 'Reverse a POSTED session (Phase 10) — full stock + GL reversal. Sets status=reversed. Re-openable up to max_reopens. Admin/manager only. Reason required.',
                'role'        => 'admin, manager',
                'params'      => [],
                'body'        => <<<'JSON'
{ "reason": "Counter found a miscounted warehouse; reversal needed before re-count." }
JSON,
                'response'    => <<<'JSON'
{ "message": "Session ST-2025-001 reversed. Stock movements + GL entry undone.", "data": { "id": 1, "status": "reversed", "is_reversed": true, "reversal_of_entry_id": 42, "re_open_count": 0 } }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '403' => 'Forbidden.', '422' => 'Session not posted, or already reversed.'],
            ],
            [
                'method'      => 'POST',
                'path'        => '/stock-take/sessions/{id}/re-open',
                'description' => 'Re-open a REVERSED session (Phase 10) — reversed → counting. Reversal stays as audit history; items reset for re-count. Capped by max_reopens. Admin/manager only. Reason required.',
                'role'        => 'admin, manager',
                'params'      => [],
                'body'        => <<<'JSON'
{ "reason": "Re-counting warehouse 2 — original count had a data-entry error." }
JSON,
                'response'    => <<<'JSON'
{ "message": "Session ST-2025-001 re-opened. Reversal preserved as audit history; correct counts and re-post.", "data": { "id": 1, "status": "counting", "re_open_count": 1 }, "reopens_remaining": 0 }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '403' => 'Forbidden.', '422' => 'Session not reversed, or re-open cap reached.'],
            ],
            [
                'method'      => 'GET',
                'path'        => '/stock-take/sessions/{id}/variance',
                'description' => 'Variance report — items with non-zero difference (physical_qty ≠ system_qty), sorted by |difference| desc. Includes gain/loss/net value summary.',
                'role'        => null,
                'params'      => [],
                'body'        => null,
                'response'    => <<<'JSON'
{
  "data": [
    { "id": 10, "product_id": 5, "product": { "name": "Widget A", "code": "W-001" }, "system_qty": 50, "physical_qty": 48, "difference": -2, "variance_type": "loss", "value_diff": -240, "post_rate": 120, "journal_line_id": 101 }
  ],
  "meta": { "session_id": 1, "session_code": "ST-2025-001", "status": "posted", "variance_lines": 1, "total_gain": 0, "total_loss": 240, "net_value": -240 }
}
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '404' => 'Session not found.'],
            ],
            [
                'method'      => 'POST',
                'path'        => '/stock-take/sessions/{id}/import/{warehouseId}',
                'description' => 'Import counts via CSV (multipart upload). Columns: product_code, physical_qty [, reason]. BOM stripped. Max 2MB.',
                'role'        => null,
                'params'      => [],
                'body'        => 'multipart form-data: csv_file=<file>',
                'response'    => <<<'JSON'
{ "message": "CSV import: 48 updated, 2 skipped.", "updated": 48, "skipped": 2, "errors": [{ "line": 5, "code": "not_found", "error": "Product code XYZ not found" }] }
JSON,
                'example'     => null,
                'errors'      => ['401' => 'Unauthenticated.', '422' => 'CSV empty, missing columns, or session not in counting state.'],
            ],
        ];
    }

    /**
     * Render the complete self-contained HTML page.
     *
     * @param  array<int, array<string,mixed>>  $endpoints
     */
    private function renderHtml(array $endpoints): string
    {
        $endpointCards = '';
        $tocItems = '';
        foreach ($endpoints as $i => $ep) {
            $endpointCards .= $this->renderEndpointCard($ep, $i);
            $anchor = 'ep-' . $i;
            $method = strtoupper((string) $ep['method']);
            $path   = htmlspecialchars((string) $ep['path']);
            $tocItems .= '<li><a href="#' . $anchor . '"><strong>' . $method . '</strong> ' . $path . '</a></li>';
        }

        $count = count($endpoints);
        $baseUrl = rtrim((string) config('app.url', ''), '/');
        $docsVersion = 'Phase 18 (v1)';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RC_ERP API Documentation</title>
<style>
:root {
    --bg: #f8fafc;
    --fg: #0f172a;
    --muted: #64748b;
    --border: #e2e8f0;
    --accent: #0891b2;
    --accent-dark: #0e7490;
    --card: #ffffff;
    --code-bg: #1e293b;
    --code-fg: #e2e8f0;
    --get: #0891b2;
    --post: #16a34a;
    --put: #d97706;
    --delete: #dc2626;
    --error-bg: #fef2f2;
    --error-fg: #991b1b;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: var(--bg); color: var(--fg); }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    line-height: 1.55;
    font-size: 15px;
}
.container { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }
header.hero {
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    color: white; padding: 2rem 1.5rem; border-radius: 0 0 1rem 1rem;
    box-shadow: 0 4px 12px rgba(15,23,42,.08);
}
header.hero h1 { margin: 0 0 .25rem; font-size: 1.75rem; font-weight: 700; }
header.hero p  { margin: 0; opacity: .9; font-size: .95rem; }
header.hero .meta {
    display: inline-block; margin-top: .75rem;
    background: rgba(255,255,255,.15); padding: .25rem .65rem; border-radius: 1rem;
    font-size: .8rem;
}

.toc { background: var(--card); border: 1px solid var(--border); border-radius: .65rem; padding: 1rem 1.25rem; margin: 1.5rem 0; }
.toc h2 { margin: 0 0 .75rem; font-size: 1.05rem; color: var(--muted); }
.toc ul { list-style: none; padding: 0; margin: 0; columns: 2; }
.toc li { margin: .15rem 0; font-size: .9rem; }
.toc a { color: var(--accent-dark); text-decoration: none; font-family: 'SF Mono', Monaco, Consolas, monospace; }
.toc a:hover { text-decoration: underline; }

.try-it {
    background: var(--card); border: 1px solid var(--border); border-radius: .65rem;
    padding: 1.25rem; margin: 1.5rem 0; box-shadow: 0 2px 4px rgba(15,23,42,.04);
}
.try-it h2 { margin: 0 0 .5rem; font-size: 1.1rem; }
.try-it label { display: block; font-size: .85rem; color: var(--muted); margin-bottom: .35rem; }
.try-it input[type=password] {
    width: 100%; padding: .55rem .75rem; border: 1px solid var(--border); border-radius: .4rem;
    font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: .9rem;
}
.try-it input[type=password]:focus { outline: 2px solid var(--accent); border-color: var(--accent); }
.try-it .hint { font-size: .8rem; color: var(--muted); margin-top: .5rem; }

.endpoint {
    background: var(--card); border: 1px solid var(--border); border-radius: .65rem;
    margin: 1rem 0; overflow: hidden; box-shadow: 0 2px 4px rgba(15,23,42,.04);
}
.endpoint-header { padding: .85rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: .65rem; flex-wrap: wrap; }
.method-badge {
    display: inline-block; padding: .25rem .6rem; border-radius: .35rem; color: white;
    font-weight: 700; font-size: .75rem; letter-spacing: .04em;
    font-family: 'SF Mono', Monaco, Consolas, monospace; min-width: 60px; text-align: center;
}
.method-GET    { background: var(--get); }
.method-POST   { background: var(--post); }
.method-PUT    { background: var(--put); }
.method-DELETE { background: var(--delete); }
.endpoint-path { font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: .9rem; font-weight: 600; word-break: break-all; }
.endpoint-role { margin-left: auto; font-size: .75rem; padding: .2rem .55rem; border-radius: 1rem; background: #fef3c7; color: #92400e; }
.endpoint-role.any { background: #ecfdf5; color: #166534; }
.endpoint-body { padding: 1rem 1.25rem; }
.endpoint-body p { margin: 0 0 .65rem; }
.endpoint-body h3 { margin: 1rem 0 .35rem; font-size: .85rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.endpoint-body ul { padding-left: 1.25rem; margin: .25rem 0 .75rem; }
.endpoint-body ul li { font-size: .88rem; margin-bottom: .15rem; }
.endpoint-body ul li code { background: #f1f5f9; padding: .1rem .35rem; border-radius: .25rem; font-size: .82rem; }
pre {
    background: var(--code-bg); color: var(--code-fg); padding: .85rem 1rem; border-radius: .4rem;
    overflow-x: auto; font-size: .82rem; line-height: 1.5;
    font-family: 'SF Mono', Monaco, Consolas, 'Courier New', monospace;
}
.error-list { background: var(--error-bg); border: 1px solid #fecaca; border-radius: .4rem; padding: .65rem .85rem; }
.error-list dl { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: .25rem .85rem; }
.error-list dt { color: var(--error-fg); font-weight: 700; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: .82rem; }
.error-list dd { margin: 0; font-size: .85rem; }

.try-button {
    margin-top: .75rem; padding: .45rem .85rem; background: var(--accent); color: white;
    border: none; border-radius: .35rem; cursor: pointer; font-size: .85rem; font-weight: 600;
    transition: background .15s;
}
.try-button:hover { background: var(--accent-dark); }
.try-button:disabled { background: #94a3b8; cursor: not-allowed; }
.try-result { margin-top: .85rem; }
.try-result pre { max-height: 320px; }

footer { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--border); color: var(--muted); font-size: .85rem; text-align: center; }

@media (max-width: 640px) {
    .toc ul { columns: 1; }
    .endpoint-header { flex-direction: column; align-items: flex-start; }
    .endpoint-role { margin-left: 0; }
}
</style>
</head>
<body>
<header class="hero">
    <div class="container">
        <h1>RC_ERP REST API</h1>
        <p>Bearer-token authenticated JSON API for mobile apps and AI sidecars. Base URL: <code>{$baseUrl}/api/v1</code></p>
        <span class="meta">{$docsVersion}</span>
    </div>
</header>

<div class="container">
    <section class="try-it">
        <h2>Try it</h2>
        <label for="bearerToken">Bearer token</label>
        <input type="password" id="bearerToken" placeholder="Paste your 60-character API token here…" autocomplete="off">
        <div class="hint">
            Generate a token with <code>php artisan api:token &lt;username&gt;</code> (see the
            <a href="#auth" style="color: var(--accent-dark);">authentication</a> section).
            The token is sent as <code>Authorization: Bearer &lt;token&gt;</code> on every request.
        </div>
    </section>

    <a id="auth"></a>
    <section class="endpoint">
        <div class="endpoint-header">
            <span class="method-badge method-GET">AUTH</span>
            <span class="endpoint-path">Authentication</span>
        </div>
        <div class="endpoint-body">
            <p>All <code>/api/v1/*</code> endpoints require a Bearer token in the <code>Authorization</code> header.</p>
            <h3>How to get a token</h3>
            <p>Run the Artisan command on the server:</p>
            <pre>$ php artisan api:token jane.doe --role=admin

  User:      jane.doe
  Role:      admin
  API Token: 1|abc123def456ghi789jkl012mno345pqr678stu901vwx234yzab567cde890fgh123

  ⚠️  This token is shown only ONCE. Store it securely.</pre>
            <p>The token is stored SHA-256-hashed in <code>users.api_token</code> — a DB leak does not expose live tokens.</p>
            <h3>Using the token</h3>
            <pre>curl -H "Authorization: Bearer &lt;your-token&gt;" \\
     -H "Accept: application/json" \\
     {$baseUrl}/api/v1/branches</pre>
            <h3>Errors</h3>
            <div class="error-list">
                <dl>
                    <dt>401</dt><dd>Missing or invalid <code>Authorization</code> header, or token does not match any active user.</dd>
                    <dt>403</dt><dd>Token is valid but the user lacks the required role (e.g. a salesman token on an admin-only route).</dd>
                </dl>
            </div>
        </div>
    </section>

    <a id="pagination"></a>
    <section class="endpoint">
        <div class="endpoint-header">
            <span class="method-badge method-GET">META</span>
            <span class="endpoint-path">Pagination format</span>
        </div>
        <div class="endpoint-body">
            <p>List endpoints (<code>GET /branches</code>) return Laravel paginator JSON:</p>
            <pre>{
  "data": [ /* array of records on the current page */ ],
  "meta": {
    "current_page": 1,
    "last_page":    5,
    "per_page":     25,
    "total":        120,
    "from":         1,
    "to":           25
  }
}</pre>
            <p>Use <code>?page=N</code> to navigate and <code>?per_page=K</code> (max 100) to change page size.</p>
        </div>
    </section>

    <section class="endpoint">
        <div class="endpoint-header">
            <span class="method-badge method-GET">META</span>
            <span class="endpoint-path">Rate limiting</span>
        </div>
        <div class="endpoint-body">
            <p>The API is currently rate-limited by the standard Laravel throttle middleware (60 requests/minute per IP by default, configurable).</p>
            <p>When rate-limited, the response is HTTP 429 with a <code>Retry-After</code> header.</p>
        </div>
    </section>

    <h2 id="endpoints" style="margin-top: 2rem;">Endpoints ({$count})</h2>
    <div class="toc">
        <h2>On this page</h2>
        <ul>
            {$tocItems}
        </ul>
    </div>

    {$endpointCards}

    <footer>
        <p>RC_ERP Laravel 12 · Phase 18 API documentation · auto-generated from <code>app/Http/Controllers/Api/ApiDocController.php</code></p>
    </footer>
</div>

<script>
(function () {
    // Wire up each "Try it" button on the endpoint cards.
    document.querySelectorAll('.try-button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tokenInput = document.getElementById('bearerToken');
            var token = (tokenInput.value || '').trim();
            var method = btn.getAttribute('data-method');
            var path   = btn.getAttribute('data-path');
            var resultId = btn.getAttribute('data-result');
            var resultEl = document.getElementById(resultId);
            var baseUrl  = '{$baseUrl}/api/v1';
            var url = baseUrl + path;

            if (!token) {
                resultEl.innerHTML = '<pre style="background:#fef3c7;color:#92400e;">⚠️  Enter a Bearer token above first.</pre>';
                return;
            }

            // Replace {id} placeholders with a sample value so the call goes through.
            if (url.indexOf('{id}') !== -1) {
                url = url.replace('{id}', '1');
            }

            btn.disabled = true;
            btn.textContent = 'Sending…';
            resultEl.innerHTML = '<pre>Sending ' + method + ' ' + url + '…</pre>';

            fetch(url, {
                method: method,
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
            }).then(function (resp) {
                return resp.text().then(function (body) {
                    var pretty;
                    try {
                        pretty = JSON.stringify(JSON.parse(body), null, 2);
                    } catch (e) {
                        pretty = body;
                    }
                    resultEl.innerHTML =
                        '<pre style="background:#1e293b;color:#e2e8f0;">HTTP ' + resp.status + ' ' + resp.statusText + '\\n\\n' +
                        escapeHtml(pretty) + '</pre>';
                });
            }).catch(function (err) {
                resultEl.innerHTML = '<pre style="background:#fef2f2;color:#991b1b;">Network error: ' + escapeHtml(String(err)) + '</pre>';
            }).finally(function () {
                btn.disabled = false;
                btn.textContent = 'Try it';
            });
        });
    });

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
})();
</script>
</body>
</html>
HTML;
    }

    /**
     * Render a single endpoint card.
     *
     * @param  array<string,mixed>  $ep
     */
    private function renderEndpointCard(array $ep, int $index): string
    {
        $method = strtoupper((string) $ep['method']);
        $path   = (string) $ep['path'];
        $anchor = 'ep-' . $index;

        $roleBadge = $ep['role']
            ? '<span class="endpoint-role">role: ' . htmlspecialchars((string) $ep['role']) . '</span>'
            : '<span class="endpoint-role any">any authenticated</span>';

        $paramsSection = '';
        if (!empty($ep['params'])) {
            $items = '';
            foreach ($ep['params'] as $name => $desc) {
                $items .= '<li><code>' . htmlspecialchars((string) $name) . '</code> — ' . htmlspecialchars((string) $desc) . '</li>';
            }
            $paramsSection = "<h3>Query parameters</h3><ul>{$items}</ul>";
        }

        $bodySection = '';
        if (!empty($ep['body'])) {
            $bodySection = '<h3>Request body</h3><pre>' . htmlspecialchars((string) $ep['body']) . '</pre>';
        }

        $responseSection = '';
        if (!empty($ep['response'])) {
            $responseSection = '<h3>Response</h3><pre>' . htmlspecialchars((string) $ep['response']) . '</pre>';
        }

        $errorsSection = '';
        if (!empty($ep['errors'])) {
            $errorItems = '';
            foreach ($ep['errors'] as $code => $desc) {
                $errorItems .= '<dt>' . htmlspecialchars((string) $code) . '</dt><dd>' . htmlspecialchars((string) $desc) . '</dd>';
            }
            $errorsSection = "<h3>Errors</h3><div class=\"error-list\"><dl>{$errorItems}</dl></div>";
        }

        $methodClass = 'method-' . $method;
        $desc = htmlspecialchars((string) $ep['description']);

        $tryButton = '<button class="try-button" data-method="' . $method . '" data-path="' . htmlspecialchars($path) . '" data-result="result-' . $index . '">Try it</button><div class="try-result" id="result-' . $index . '"></div>';

        return <<<HTML
<a id="{$anchor}"></a>
<section class="endpoint">
    <div class="endpoint-header">
        <span class="method-badge {$methodClass}">{$method}</span>
        <span class="endpoint-path">{$path}</span>
        {$roleBadge}
    </div>
    <div class="endpoint-body">
        <p>{$desc}</p>
        {$paramsSection}
        {$bodySection}
        {$responseSection}
        {$errorsSection}
        {$tryButton}
    </div>
</section>
HTML;
    }
}
