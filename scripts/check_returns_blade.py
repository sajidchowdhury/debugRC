#!/usr/bin/env python3
"""
BUG-45 regression check — verifies that NO Blade view under
resources/views/admin/purchase-returns/ uses @json([...]) with a
multi-key array literal.

Blade's @json() directive uses explode(',', $expr, 2) internally to
split the value from the optional $options / $depth arguments. Any
array literal with multiple comma-separated entries therefore breaks
the compiled PHP ("Unclosed '[' does not match ')'").

The only safe forms of @json() are:
  @json($scalar_var)
  @json($array_var)              # variable, not literal
  @json($array_var, JSON_PRETTY) # scalar var + option constant

Any `@json([` (array literal opening bracket) is FORBIDDEN.

Run with:  python3 scripts/check_returns_blade.py
"""
from pathlib import Path
import re

ROOT = Path('/home/z/my-project/laravel/resources/views/admin/purchase-returns')

# Files we expect to check
files = sorted(ROOT.rglob('*.blade.php'))

errors = []
checked = 0

for fp in files:
    checked += 1
    src = fp.read_text()
    lines = src.splitlines()

    # Check 1: scan for @json([ — FORBIDDEN pattern
    for i, line in enumerate(lines, 1):
        stripped = line.lstrip()
        # Skip Blade/PHP comments
        if stripped.startswith('//') or stripped.startswith('#') or stripped.startswith('*'):
            continue
        if stripped.startswith('{{--'):
            continue
        # Look for @json followed by ([
        if re.search(r'@json\(\s*\[', line):
            msg = "{0}:{1}: FORBIDDEN @json([ literal — use json_encode() in @php + blade raw echo instead".format(fp.name, i)
            errors.append(msg)

    # Check 2: @php/@endphp, @push/@endpush, @section/@endsection pairing
    php_opens  = sum(1 for l in lines if l.strip() == '@php')
    php_closes = sum(1 for l in lines if l.strip() == '@endphp')
    if php_opens != php_closes:
        errors.append(f"{fp.name}: @php/@endphp mismatch ({php_opens} vs {php_closes})")

    push_opens  = sum(1 for l in lines if l.strip().startswith('@push('))
    push_closes = sum(1 for l in lines if l.strip() == '@endpush')
    if push_opens != push_closes:
        errors.append(f"{fp.name}: @push/@endpush mismatch ({push_opens} vs {push_closes})")

    sec_opens  = sum(1 for l in lines if l.strip().startswith('@section('))
    sec_closes = sum(1 for l in lines if l.strip() == '@endsection')
    if sec_opens != sec_closes:
        errors.append(f"{fp.name}: @section/@endsection mismatch ({sec_opens} vs {sec_closes})")

    # Check 3: rough bracket balance per file ( parens, brackets, braces )
    # — strip Blade comments and PHP comments first to reduce false positives
    cleaned = re.sub(r'\{\{--.*?--\}\}', '', src, flags=re.DOTALL)
    cleaned = re.sub(r'//[^\n]*', '', cleaned)
    cleaned = re.sub(r'#[^\n]*', '', cleaned)
    # crude brace balance (won't be 0 because of JS, but should be CONSISTENT
    # across revisions — we just record it as info, not a hard check)
    paren_balance = cleaned.count('(') - cleaned.count(')')
    brack_balance = cleaned.count('[') - cleaned.count(']')
    brace_balance = cleaned.count('{') - cleaned.count('}')
    if paren_balance != 0:
        # Don't error — JS in <script> tags legitimately uses parens.
        # Just record for reference.
        pass

# Report
print("=" * 64)
print("BUG-45 regression check")
print(f"Scanned: {checked} blade files under {ROOT}")
print("=" * 64)
print()
if errors:
    print("FAIL — issues found:")
    for e in errors:
        print(f"  - {e}")
    raise SystemExit(1)
else:
    print("PASS — no @json([...]) literals remain in any returns blade.")
    print("All @php/@push/@section directive pairs balanced.")
