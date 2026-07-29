#!/usr/bin/env python3
"""
BUG-46 verification: bracket/paren/brace balance check on
PurchaseReturnController.php after the getReceiveDetails refactor.
Doesn't replace `php -l` but catches gross mistakes locally.
"""
from pathlib import Path

src = Path('/home/z/my-project/laravel/app/Http/Controllers/Admin/PurchaseReturnController.php').read_text()

# Strip strings (single + double quoted) and comments before counting
# so brackets inside strings don't throw off the count.
out = []
i = 0
n = len(src)
in_line = False
in_block = False
while i < n:
    c = src[i]
    nxt = src[i+1] if i+1 < n else ''
    if in_line:
        if c == '\n':
            in_line = False
            out.append(c)
        i += 1
        continue
    if in_block:
        if c == '*' and nxt == '/':
            in_block = False
            i += 2
            continue
        i += 1
        continue
    if c == '/' and nxt == '/':
        in_line = True
        i += 2
        continue
    if c == '/' and nxt == '*':
        in_block = True
        i += 2
        continue
    if c == '#':
        in_line = True
        i += 1
        continue
    if c == "'":
        # skip single-quoted (with \' escapes)
        i += 1
        while i < n:
            if src[i] == '\\' and i+1 < n:
                i += 2
                continue
            if src[i] == "'":
                i += 1
                break
            i += 1
        out.append("''")
        continue
    if c == '"':
        i += 1
        while i < n:
            if src[i] == '\\' and i+1 < n:
                i += 2
                continue
            if src[i] == '"':
                i += 1
                break
            i += 1
        out.append('""')
        continue
    out.append(c)
    i += 1

clean = ''.join(out)
p = clean.count('(') - clean.count(')')
b = clean.count('[') - clean.count(']')
br = clean.count('{') - clean.count('}')

print("=" * 60)
print("BUG-46 bracket balance: PurchaseReturnController.php")
print("=" * 60)
print(f"() balance: {p}")
print(f"[] balance: {b}")
print(f"{{}} balance: {br}")
print()
# Also confirm key markers
print(f"Uses StockAvailabilityService:    {'stockService->getBranchWarehouseBreakdown' in clean}")
print(f"OLD broken ws.physical_qty ref:   {'ws.physical_qty' in clean}")
print(f"OLD broken ws.available_qty ref:  {'ws.available_qty' in clean}")
print()
if p == 0 and b == 0 and br == 0 and 'ws.physical_qty' not in clean and 'ws.available_qty' not in clean:
    print("PASS")
else:
    print("FAIL")
    raise SystemExit(1)
