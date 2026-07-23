#!/usr/bin/env python3
"""
BUG-52 regression check.

Verifies that the sales → godown → challan workflow role separation
remains intact after the BUG-52 fix. Catches regressions like:

1. Salesman gaining access to godown/challan creation routes (web or API).
2. Warehouse manager losing read access to invoices index/show (their
   only entry point to discover invoices awaiting godown prep).
3. Print Godown / Print Blank Godown buttons being rendered for
   non-warehouse-manager roles on the invoice show page.
4. Missing "Prepare Godown" / "Issue Challan" handoff buttons on the
   invoice show page.
5. Missing scope (today/pending_godown/pending_challan) chips on the
   invoices index page.
"""

import re
import sys
from pathlib import Path

WEB_ROUTES = Path("/home/z/my-project/laravel/routes/web.php")
API_ROUTES = Path("/home/z/my-project/laravel/routes/api.php")
SHOW_BLADE = Path("/home/z/my-project/laravel/resources/views/admin/sales-invoices/show.blade.php")
INDEX_BLADE = Path("/home/z/my-project/laravel/resources/views/admin/sales-invoices/index.blade.php")

failures = []

# ─── Check 1: Warehouse_manager has read access to invoices ────────────
text = WEB_ROUTES.read_text()
# The resource line should include warehouse_manager.
resource_match = re.search(
    r"Route::resource\('admin/sales-invoices'.*?->middleware\('role:([^']+)'\);",
    text, re.DOTALL,
)
if not resource_match or "warehouse_manager" not in resource_match.group(1):
    failures.append(
        "routes/web.php: warehouse_manager missing from sales-invoices resource middleware. "
        "Warehouse manager cannot view invoice list/show — no entry point to find work."
    )

# Datatable + summary should also include warehouse_manager.
for endpoint in ["datatable", "summary"]:
    pattern = rf"Route::get\('{endpoint}',.*?->middleware\('role:([^']+)'\);"
    m = re.search(pattern, text, re.DOTALL)
    if not m or "warehouse_manager" not in m.group(1):
        failures.append(
            f"routes/web.php: warehouse_manager missing from sales-invoices.{endpoint} middleware. "
            f"Without it, the warehouse manager can load the index page but the DataTable AJAX call 403s."
        )

# ─── Check 2: Salesman still excluded from godown/challan creation ─────
for action in ["storeGodown", "issueChallan"]:
    pattern = rf"Route::post\('[^']+',\s*\[SalesChallanController::class,\s*'{action}'\]\).*?->middleware\(\['role:([^']+)'"
    m = re.search(pattern, text)
    if m:
        roles = m.group(1)
        if "salesman" in roles.split(","):
            failures.append(
                f"routes/web.php: salesman must NOT have access to SalesChallanController::{action}. "
                f"Found roles: {roles}"
            )

# ─── Check 3: API godown/issue/cancel have role middleware ─────────────
api_text = API_ROUTES.read_text()
for action in ["godown", "issue"]:
    # The middleware call uses two args: ->middleware('api.auth:roles', 'api.rate:N')
    # We just need to verify 'api.auth:' with the right roles is present on
    # the same Route::post(...) chain.
    pattern = (
        rf"Route::post\('sales/challans/{action}',.*?->middleware\(\s*"
        rf"'api\.auth:([^']+)'"
    )
    m = re.search(pattern, api_text, re.DOTALL)
    if not m:
        failures.append(
            f"routes/api.php: sales/challans/{action} missing role middleware. "
            f"Any authenticated API user (incl. salesman) could trigger it."
        )
    elif "warehouse_manager" not in m.group(1) or "salesman" in m.group(1).split(","):
        failures.append(
            f"routes/api.php: sales/challans/{action} has wrong roles: {m.group(1)}. "
            f"Should be warehouse_manager,dispatcher,manager,admin."
        )

# Check cancel route too
m = re.search(
    r"Route::post\('sales/challans/\{id\}/cancel'.*?->middleware\(\s*'api\.auth:([^']+)'",
    api_text, re.DOTALL,
)
if not m:
    failures.append("routes/api.php: sales/challans/{id}/cancel missing role middleware.")
elif "salesman" in m.group(1).split(","):
    failures.append(f"routes/api.php: sales/challans/cancel should not allow salesman. Got: {m.group(1)}")

# ─── Check 4: Print Godown buttons gated by role ───────────────────────
show_text = SHOW_BLADE.read_text()
# The Print Godown Copy line must be inside an @if block checking for warehouse_manager.
# Look for the role-check pattern immediately preceding the print-godown link.
print_godown_block_match = re.search(
    r"@if\s*\(\s*auth\(\)->user\(\)->hasRole\([^)]*warehouse_manager[^)]*\)\s*\).*?route\('admin\.sales-invoices\.print-godown'",
    show_text, re.DOTALL,
)
if not print_godown_block_match:
    failures.append(
        "show.blade.php: Print Godown Copy button is not gated by a warehouse_manager role check. "
        "Salesmen will see the button and get a 403 on click."
    )

# ─── Check 5: Handoff buttons exist ────────────────────────────────────
if "admin.sales-challans.godown" not in show_text:
    failures.append("show.blade.php: missing 'Prepare Godown Copy' handoff button (route admin.sales-challans.godown).")
if "admin.sales-challans.challan-form" not in show_text:
    failures.append("show.blade.php: missing 'Issue Challan' handoff button (route admin.sales-challans.challan-form).")

# ─── Check 6: Scope chips present on index page ────────────────────────
index_text = INDEX_BLADE.read_text()
for chip in ["today", "pending_godown", "pending_challan"]:
    if f"data-scope=\"{chip}\"" not in index_text:
        failures.append(f"index.blade.php: missing workflow chip with data-scope=\"{chip}\".")
if "$scope" not in index_text or "scope" not in index_text:
    failures.append("index.blade.php: missing $scope variable handling.")

# ─── Check 7: Stale "Phase 8.3" alert removed ──────────────────────────
if "Phase 8.3" in show_text and "available in Phase 8.3" in show_text:
    failures.append(
        "show.blade.php: stale 'available in Phase 8.3' alert still present. "
        "Phase 8.3 is implemented — should be replaced with actionable content."
    )

# ─── Report ────────────────────────────────────────────────────────────
print("================================================================")
print("BUG-52 regression check")
print("  Scope: sales → godown → challan role separation + handoff UI")
print("================================================================")
print()

if failures:
    for f in failures:
        print(f"  ❌ {f}")
    print()
    print(f"FAIL — {len(failures)} problem(s) found.")
    sys.exit(1)
else:
    print("PASS — all 7 invariants intact:")
    print("  1. warehouse_manager has read access to invoices index/show/datatable/summary")
    print("  2. salesman cannot create godown/challan (web routes)")
    print("  3. API godown/issue/cancel routes are role-gated")
    print("  4. Print Godown buttons gated by role on show page")
    print("  5. Prepare Godown + Issue Challan handoff buttons present on show page")
    print("  6. Today / Pending Godown / Pending Challan scope chips present on index page")
    print("  7. Stale 'Phase 8.3' alert removed")
    sys.exit(0)
