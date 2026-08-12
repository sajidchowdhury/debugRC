#!/usr/bin/env python3
"""
Phase 7 Content Validator — static checks across all 215 help content files.

Checks per file:
  1. Opens with <?php (after optional BOM/whitespace).
  2. Contains `return [` and ends with `];` (whitespace-tolerant).
  3. Brace/paren/bracket balance (ignoring // and # and /* */ and strings).
  4. Required top-level keys present: key, module, title_bn, title_en, icon,
     summary, for_roles, what_you_can_do, impacts, cautions, related, updated_at.
  5. 'key' field == the menu_key derived from path (module/slug).
  6. 'module' field == the module dir.
  7. 'updated_at' == '2026-08-07'.
  8. No U+FFFD replacement characters (Bengali corruption).
  9. 'diagram' value (if present) exists in diagrams.php keys.
 10. Every 'related' key exists in the modules.php menu list (union).
 11. Summary is a single sentence (≤2 Bangla danda ।) — soft check, warn only.

Cross-checks:
  A. Every menu_key in modules.php has a content file (coverage = 215/215).
  B. Every content file's 'key' is in modules.php (no orphan files).
  C. Diagram key distribution matches plan: master-data.ledgers=chart-of-accounts-tree,
     inventory.stock-take=stock-take-cycle, inventory.warehouse-transfers=warehouse-transfer-flow,
     purchasing.purchase-orders=procure-to-pay, sales.invoices=sales-invoice-flow (pre-existing),
     sales.commission-rules=commission-calc, accounting.manual-journals=journal-posting,
     accounting.period-close=period-close, finance.consolidation=consolidation-flow,
     system.notifications=notification-fan-out. (Reports: none.)
"""

import os
import re
import sys
from pathlib import Path

BASE = Path("/home/z/my-project/download/debugRC/laravel/resources/help")
MENUS_DIR = BASE / "menus"

# ---------------------------------------------------------------------------
# Helpers: parse a PHP file that returns an array (regex-based, no PHP runtime)
# ---------------------------------------------------------------------------

def strip_php_comments_and_strings(code: str) -> str:
    """Remove // # /* */ comments and string literals for brace balancing."""
    out = []
    i = 0
    n = len(code)
    state = "code"  # code | line_comment | block_comment | sq | dq | heredoc
    while i < n:
        ch = code[i]
        nx = code[i+1] if i+1 < n else ""
        if state == "code":
            if ch == "/" and nx == "/":
                state = "line_comment"; i += 2; continue
            if ch == "#":
                state = "line_comment"; i += 1; continue
            if ch == "/" and nx == "*":
                state = "block_comment"; i += 2; continue
            if ch == "'":
                state = "sq"; out.append(ch); i += 1; continue
            if ch == '"':
                state = "dq"; out.append(ch); i += 1; continue
            out.append(ch); i += 1; continue
        if state == "line_comment":
            if ch == "\n":
                state = "code"; out.append(ch)
            i += 1; continue
        if state == "block_comment":
            if ch == "*" and nx == "/":
                state = "code"; i += 2; continue
            i += 1; continue
        if state == "sq":
            out.append(ch)
            if ch == "\\":
                if i+1 < n:
                    out.append(code[i+1]); i += 2; continue
            if ch == "'":
                state = "code"
            i += 1; continue
        if state == "dq":
            out.append(ch)
            if ch == "\\":
                if i+1 < n:
                    out.append(code[i+1]); i += 2; continue
            if ch == '"':
                state = "code"
            i += 1; continue
    return "".join(out)


def brace_balance_ok(code: str) -> tuple[bool, str]:
    stripped = strip_php_comments_and_strings(code)
    stack = []
    pairs = {"(": ")", "{": "}", "[": "]"}
    closers = set(pairs.values())
    for ch in stripped:
        if ch in pairs:
            stack.append(ch)
        elif ch in closers:
            if not stack:
                return False, f"unmatched closer {ch}"
            top = stack.pop()
            if pairs[top] != ch:
                return False, f"mismatch {top} vs {ch}"
    if stack:
        return False, f"unclosed {''.join(stack)}"
    return True, ""


def extract_field(content: str, field: str):
    """Extract a scalar string field: 'field' => 'value'  or  'field' => "value"."""
    # match 'field'  =>  'value'   allowing whitespace
    m = re.search(r"'" + re.escape(field) + r"'\s*=>\s*'([^']*)'", content)
    if m:
        return m.group(1)
    m = re.search(r"'" + re.escape(field) + r"'\s*=>\s*\"([^\"]*)\"", content)
    if m:
        return m.group(1)
    return None


def extract_field_raw(content: str, field: str):
    """Extract raw RHS after 'field'  =>  (the whole token up to comma or newline)."""
    m = re.search(r"'" + re.escape(field) + r"'\s*=>\s*(.+)", content)
    if not m:
        return None
    return m.group(1).rstrip().rstrip(",")


# ---------------------------------------------------------------------------
# Load modules.php menu_keys (union) + diagrams.php keys
# ---------------------------------------------------------------------------

def load_modules_menu_keys() -> set:
    path = BASE / "modules.php"
    txt = path.read_text(encoding="utf-8")
    # menu keys look like 'module.something' inside the 'menus' => [...] arrays
    keys = set(re.findall(r"'([a-z0-9-]+\.[a-z0-9-]+)'", txt))
    return keys


def load_diagram_keys() -> set:
    path = BASE / "diagrams.php"
    txt = path.read_text(encoding="utf-8")
    # diagram keys are 'key' => <<<'MERMAID'
    keys = set(re.findall(r"'([a-z0-9-]+)'\s*=>\s*<<<'", txt))
    return keys


# ---------------------------------------------------------------------------
# Main validation
# ---------------------------------------------------------------------------

REQUIRED_KEYS = [
    "key", "module", "title_bn", "title_en", "icon", "summary",
    "for_roles", "what_you_can_do", "impacts", "cautions", "related", "updated_at",
]

EXPECTED_DIAGRAMS = {
    "master-data.ledgers": "chart-of-accounts-tree",
    "inventory.stock-take": "stock-take-cycle",
    "inventory.warehouse-transfers": "warehouse-transfer-flow",
    "purchasing.purchase-orders": "procure-to-pay",
    "sales.invoices": "sales-invoice-flow",
    "sales.commission-rules": "commission-calc",
    "accounting.manual-journals": "journal-posting",
    "accounting.period-close": "period-close",
    "finance.consolidation": "consolidation-flow",
    "system.notifications": "notification-fan-out",
}


def validate_file(path: Path, all_menu_keys: set, diagram_keys: set):
    errors = []
    warnings = []
    rel = path.relative_to(BASE)
    module = path.parent.name
    slug = path.stem  # filename without .php
    expected_key = f"{module}.{slug}"

    raw = path.read_text(encoding="utf-8")

    # 1. opens with <?php
    head = raw.lstrip()
    if not head.startswith("<?php"):
        errors.append("file does not start with <?php")

    # 2. has return [ and ends with ];
    if "return [" not in raw:
        errors.append("missing 'return ['")
    body = raw.rstrip()
    if not body.rstrip().endswith("];"):
        # tolerate trailing comment
        last_bracket = body.rfind("];")
        if last_bracket == -1:
            errors.append("missing closing '];'")

    # 3. brace balance
    ok, msg = brace_balance_ok(raw)
    if not ok:
        errors.append(f"brace balance: {msg}")

    # 4. required keys
    for k in REQUIRED_KEYS:
        if f"'{k}'" not in raw:
            errors.append(f"missing required key '{k}'")

    # 5. key field == expected_key
    key_val = extract_field(raw, "key")
    if key_val is None:
        errors.append("could not extract 'key' field value")
    elif key_val != expected_key:
        errors.append(f"key field '{key_val}' != expected '{expected_key}'")

    # 6. module field == dir
    mod_val = extract_field(raw, "module")
    if mod_val is not None and mod_val != module:
        errors.append(f"module field '{mod_val}' != dir '{module}'")

    # 7. updated_at
    upd = extract_field(raw, "updated_at")
    if upd is not None and upd != "2026-08-07":
        errors.append(f"updated_at='{upd}' != '2026-08-07'")

    # 8. U+FFFD
    if "\ufffd" in raw:
        cnt = raw.count("\ufffd")
        errors.append(f"{cnt} U+FFFD replacement char(s) (Bengali corruption)")

    # 9. diagram value in diagrams.php
    dia = extract_field(raw, "diagram")
    if dia is not None:
        if dia not in diagram_keys:
            errors.append(f"diagram '{dia}' not in diagrams.php")
        # check expected placement
        exp = EXPECTED_DIAGRAMS.get(expected_key)
        if exp is not None:
            if dia != exp:
                errors.append(f"diagram '{dia}' on {expected_key} but expected '{exp}'")
        else:
            # this key should NOT have a diagram
            errors.append(f"diagram '{dia}' present on {expected_key} but no diagram expected here")

    # 10. related keys exist
    # extract related array contents
    rel_m = re.search(r"'related'\s*=>\s*\[(.*?)\]", raw, re.DOTALL)
    if rel_m:
        rel_body = rel_m.group(1)
        rel_keys = re.findall(r"'([a-z0-9-]+\.[a-z0-9-]+)'", rel_body)
        for rk in rel_keys:
            if rk not in all_menu_keys:
                errors.append(f"related key '{rk}' not in modules.php menu list")

    # 11. summary single sentence (soft)
    summary = extract_field(raw, "summary")
    if summary is not None:
        danda_count = summary.count("।")
        if danda_count > 1:
            warnings.append(f"summary has {danda_count} danda (।) — expected ≤1 sentence")

    return errors, warnings, key_val


def main():
    all_menu_keys = load_modules_menu_keys()
    diagram_keys = load_diagram_keys()
    print(f"Loaded {len(all_menu_keys)} menu_keys from modules.php")
    print(f"Loaded {len(diagram_keys)} diagram keys: {sorted(diagram_keys)}")
    print()

    files = sorted(MENUS_DIR.rglob("*.php"))
    print(f"Found {len(files)} content files under {MENUS_DIR}")
    print("=" * 70)

    total_errors = 0
    total_warnings = 0
    file_keys = set()
    error_files = []
    warn_files = []

    for f in files:
        errs, warns, kv = validate_file(f, all_menu_keys, diagram_keys)
        file_keys.add(kv) if kv else None
        if errs:
            total_errors += len(errs)
            error_files.append((f, errs))
        if warns:
            total_warnings += len(warns)
            warn_files.append((f, warns))

    # Cross-check A: every menu_key has a file
    missing_files = all_menu_keys - file_keys
    # Cross-check B: every file key is in modules
    orphan_files = file_keys - all_menu_keys

    print(f"Files with ERRORS: {len(error_files)}")
    for f, errs in error_files:
        print(f"  ❌ {f.relative_to(BASE)}")
        for e in errs:
            print(f"       - {e}")
    print()
    print(f"Files with WARNINGS: {len(warn_files)}")
    for f, ws in warn_files[:10]:
        print(f"  ⚠️  {f.relative_to(BASE)}")
        for w in ws:
            print(f"       - {w}")
    if len(warn_files) > 10:
        print(f"  ... and {len(warn_files)-10} more warned files")
    print()
    print(f"Cross-check A — menu_keys WITHOUT a content file: {len(missing_files)}")
    for k in sorted(missing_files):
        print(f"  • {k}")
    print()
    print(f"Cross-check B — content files whose key is NOT in modules.php: {len(orphan_files)}")
    for k in sorted(orphan_files):
        print(f"  • {k}")
    print()
    print("=" * 70)
    print(f"TOTAL ERRORS:   {total_errors}")
    print(f"TOTAL WARNINGS: {total_warnings}")
    print(f"COVERAGE:       {len(file_keys & all_menu_keys)}/{len(all_menu_keys)} menu_keys have content")
    return 0 if total_errors == 0 and len(missing_files) == 0 and len(orphan_files) == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
