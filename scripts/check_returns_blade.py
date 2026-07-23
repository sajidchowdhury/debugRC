#!/usr/bin/env python3
"""
BUG-45 verification: walk the purchase-returns index blade and verify
that the @php / @json / {{ }} / @section blocks all balance.

We don't try to be a full Blade parser — we just confirm:
  1. @php ... @endphp pairs match
  2. @push ... @endpush pairs match
  3. @section ... @endsection pairs match
  4. Every @json( has a matching close ) on the SAME logical block
  5. The 'prefill' value is now a bare $var (no inline trim() call)
"""
from pathlib import Path
import re

src = Path('/home/z/my-project/laravel/resources/views/admin/purchase-returns/index.blade.php').read_text()
lines = src.splitlines()

errors = []

# 1. @php/@endphp pairing
php_opens  = [i+1 for i,l in enumerate(lines) if l.strip() == '@php']
php_closes = [i+1 for i,l in enumerate(lines) if l.strip() == '@endphp']
if len(php_opens) != len(php_closes):
    errors.append(f"@php/@endphp mismatch: {len(php_opens)} opens vs {len(php_closes)} closes")

# 2. @push/@endpush
push_opens  = [i+1 for i,l in enumerate(lines) if l.strip().startswith('@push(')]
push_closes = [i+1 for i,l in enumerate(lines) if l.strip() == '@endpush']
if len(push_opens) != len(push_closes):
    errors.append(f"@push/@endpush mismatch: {len(push_opens)} vs {len(push_closes)}")

# 3. @section/@endsection
sec_opens  = [i+1 for i,l in enumerate(lines) if l.strip().startswith('@section(')]
sec_closes = [i+1 for i,l in enumerate(lines) if l.strip() == '@endsection']
if len(sec_opens) != len(sec_closes):
    errors.append(f"@section/@endsection mismatch: {len(sec_opens)} vs {len(sec_closes)}")

# 4. Every @json( must be followed somewhere by a `]);` or `);` that closes it.
#    We just verify the @json line itself doesn't contain unbalanced parens
#    when read together with its trailing `]);` line.
json_starts = []
for i, l in enumerate(lines):
    stripped = l.lstrip()
    # Skip PHP comments
    if stripped.startswith('//') or stripped.startswith('#') or stripped.startswith('*'):
        continue
    # Skip {{-- --}} Blade comments
    if stripped.startswith('{{--'):
        continue
    if '@json(' in l:
        json_starts.append(i)
for start in json_starts:
    # find the first line at/after start that ends with ']);' (closing the @json array)
    close_line = None
    for j in range(start, min(start+30, len(lines))):
        if lines[j].rstrip().endswith(']);'):
            close_line = j
            break
    if close_line is None:
        errors.append(f"@json at line {start+1} has no closing ']);' within 30 lines")

# 5. Critical: 'prefill' inside @json must NOT contain a trim() call
in_json_block = False
json_block_start = None
for i, l in enumerate(lines, 1):
    stripped = l.lstrip()
    is_comment = stripped.startswith('//') or stripped.startswith('#') or stripped.startswith('*') or stripped.startswith('{{--')
    if '@json(' in l and not is_comment:
        in_json_block = True
        json_block_start = i
    elif in_json_block and l.rstrip().endswith(']);'):
        # check the whole block (start..i) for a 'trim(' call inside
        block = '\n'.join(lines[json_block_start-1:i])
        if 'trim(' in block:
            errors.append(
                f"line {json_block_start}-{i}: @json block still contains trim() call — "
                "BUG-45 not fully fixed"
            )
        in_json_block = False

# Report
print("=" * 60)
print("BUG-45 verification: purchase-returns/index.blade.php")
print("=" * 60)
print(f"Total lines: {len(lines)}")
print(f"@php blocks:    {len(php_opens)}")
print(f"@push blocks:   {len(push_opens)}")
print(f"@section blocks:{len(sec_opens)}")
print(f"@json blocks:   {len(json_starts)}")
print()
if errors:
    print("FAIL — issues found:")
    for e in errors:
        print(f"  - {e}")
    raise SystemExit(1)
else:
    print("PASS — all structural checks OK; no trim() inside @json blocks.")
