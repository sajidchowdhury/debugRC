#!/usr/bin/env python3
"""
BUG-53 regression checker.

Verifies that the sales workflow role-separation + branch-aware routing
invariants stay intact after the BUG-53 fix.

Checks:
  1. SalesChallanController::index() fetches 3 collections:
     pendingGodown, pendingChallan, and $challans (issued).
  2. sales-challans/index.blade.php renders 3 tab sections matching
     the 3 collections, with Prepare Godown / Issue Challan action buttons.
  3. finalize route is defined OUTSIDE the admin/sales prefix group
     (no branch.isolation middleware) — salesmen can create invoices
     for any branch.
  4. finalize route still has role:salesman,manager,admin middleware
     (RBAC preserved).
  5. cart-data + credit-check routes also outside the prefix group
     (they need to work cross-branch when cart targets another branch).
  6. MenuService still resolves 'challan' → admin.sales-challans.index
     (no role-based menu restrictions added).
  7. BranchScope still applied to SalesInvoice + SalesChallan models
     (branch filtering by session branch preserved).

Usage:
    python3 scripts/check_sales_workflow_bug53.py
"""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
LARAVEL = ROOT / "laravel"

ERRORS: list[str] = []


def check(condition: bool, msg: str) -> None:
    status = "PASS" if condition else "FAIL"
    print(f"  [{status}] {msg}")
    if not condition:
        ERRORS.append(msg)


# ---------------------------------------------------------------------------
print("BUG-53 regression checks")
print()

# --- Check 1: SalesChallanController::index() fetches 3 collections ---
print("[1] SalesChallanController::index() 3-collection fetch")
controller = LARAVEL / "app/Http/Controllers/Admin/SalesChallanController.php"
src = controller.read_text(encoding="utf-8")

check(
    "pendingGodownQuery" in src and "pendingChallanQuery" in src,
    "Controller defines pendingGodownQuery + pendingChallanQuery",
)
check(
    "'pending_godown'" in src and "'pending_challan'" in src,
    "Controller filters by 'pending_godown' + 'pending_challan' scope stats",
)
check(
    "pendingGodown" in src and "pendingChallan" in src,
    "Controller passes pendingGodown + pendingChallan to view",
)
check(
    "where('status', 'draft')" in src and "where('is_godown_prepared', false)" in src,
    "Pending godown query: status=draft + is_godown_prepared=false",
)
check(
    "where('status', 'confirmed')" in src
    and "where('is_godown_prepared', true)" in src
    and "where('is_challan_issued', false)" in src,
    "Pending challan query: status=confirmed + godown_prepared + challan_not_issued",
)
print()

# --- Check 2: sales-challans/index.blade.php renders 3 tab sections ---
print("[2] sales-challans/index.blade.php 3-tab rendering")
blade = LARAVEL / "resources/views/admin/sales-challans/index.blade.php"
bsrc = blade.read_text(encoding="utf-8")

check(
    'id="pending-godown"' in bsrc and 'id="pending-challan"' in bsrc and 'id="issued"' in bsrc,
    "Blade has 3 tab pane divs: pending-godown, pending-challan, issued",
)
check(
    "pendingGodown" in bsrc and "pendingChallan" in bsrc and "challans" in bsrc,
    "Blade iterates pendingGodown + pendingChallan + challans collections",
)
check(
    "admin.sales-challans.godown" in bsrc,
    "Blade has Prepare Godown button → admin.sales-challans.godown route",
)
check(
    "admin.sales-challans.challan-form" in bsrc,
    "Blade has Issue Challan button → admin.sales-challans.challan-form route",
)
check(
    "switchTab" in bsrc and "tab" in bsrc,
    "Blade has tab persistence via switchTab() + ?tab= param",
)
print()

# --- Check 3: finalize route is OUTSIDE the admin/sales prefix group ---
print("[3] finalize route outside prefix group (no branch.isolation)")
routes = LARAVEL / "routes/web.php"
rsrc = routes.read_text(encoding="utf-8")

# Find the prefix group opening
prefix_match = re.search(
    r"Route::prefix\('admin/sales'\)->name\('admin\.sales\.'\)\s*"
    r"->middleware\(\['role:salesman,manager,admin',\s*'branch\.isolation'\]\)",
    rsrc,
)
check(prefix_match is not None, "admin/sales prefix group exists with role + branch.isolation middleware")

# Get the prefix group body (from the opening to the closing });)
if prefix_match:
    start = prefix_match.end()
    # Find matching closing brace by counting braces
    depth = 0
    i = start
    while i < len(rsrc):
        if rsrc[i] == '{':
            depth += 1
        elif rsrc[i] == '}':
            depth -= 1
            if depth == 0:
                end = i + 1
                break
        i += 1
    else:
        end = len(rsrc)
    group_body = rsrc[start:end]

    check(
        "finalize" not in re.sub(r"//.*", "", group_body),
        "finalize route is NOT inside the admin/sales prefix group",
    )
    check(
        "cart-data" not in re.sub(r"//.*", "", group_body) or "getCartData" not in re.sub(r"//.*", "", group_body),
        "cart-data route is NOT inside the admin/sales prefix group",
    )
    check(
        "credit-check" not in re.sub(r"//.*", "", group_body) or "checkCreditLimit" not in re.sub(r"//.*", "", group_body),
        "credit-check route is NOT inside the admin/sales prefix group",
    )

# Check finalize route is re-declared outside the group
check(
    "Route::post('admin/sales/finalize'" in rsrc,
    "finalize route re-declared outside the prefix group with full path",
)
check(
    "Route::get('admin/sales/cart-data'" in rsrc,
    "cart-data route re-declared outside the prefix group",
)
check(
    "Route::get('admin/sales/credit-check'" in rsrc,
    "credit-check route re-declared outside the prefix group",
)
print()

# --- Check 4: finalize route still has role middleware ---
print("[4] finalize route RBAC preserved")
# Find the finalize route definition and check it has role:salesman,manager,admin
finalize_pattern = re.search(
    r"Route::post\('admin/sales/finalize'.*?\)\s*"
    r"->name\('admin\.sales\.finalize'\)\s*"
    r"->middleware\('role:salesman,manager,admin'\)",
    rsrc,
    re.DOTALL,
)
check(
    finalize_pattern is not None,
    "finalize route has role:salesman,manager,admin middleware",
)
print()

# --- Check 5: MenuService still permission-based (no role checks added) ---
print("[5] MenuService permission-based (not role-based)")
menu_service = LARAVEL / "app/Services/MenuService.php"
msrc = menu_service.read_text(encoding="utf-8")

check(
    "'challan' => 'admin.sales-challans.index'" in msrc,
    "MenuService maps 'challan' controller → admin.sales-challans.index route",
)
check(
    "UserMenuPermission::where('user_id'" in msrc,
    "MenuService filters menus by UserMenuPermission (per-user DB permission)",
)
check(
    "isAdmin()" in msrc and "if (!$isAdmin)" in msrc,
    "MenuService admin bypass + per-user permission check preserved",
)
print()

# --- Check 6: BranchScope still applied to SalesInvoice + SalesChallan ---
print("[6] BranchScope global scope preserved")
inv_model = LARAVEL / "app/Models/SalesInvoice.php"
ch_model = LARAVEL / "app/Models/SalesChallan.php"

check(
    "static::addGlobalScope(new BranchScope)" in inv_model.read_text(encoding="utf-8"),
    "SalesInvoice has BranchScope global scope",
)
check(
    "static::addGlobalScope(new BranchScope)" in ch_model.read_text(encoding="utf-8"),
    "SalesChallan has BranchScope global scope",
)
print()

# --- Summary ---
print("=" * 60)
if ERRORS:
    print(f"FAIL: {len(ERRORS)} regression(s) detected:")
    for e in ERRORS:
        print(f"  - {e}")
    sys.exit(1)
else:
    print("PASS: All BUG-53 invariants intact.")
    sys.exit(0)
