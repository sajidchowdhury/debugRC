#!/usr/bin/env python3
"""
BUG-50 regression check.

Verifies that the purchase-returns create + index blade files no longer
submit the items array as a JSON-encoded string. The previous pattern:

    formData.set('items', JSON.stringify(items));

caused Laravel's 'items' => 'required|array' validation rule to fail with
"The items field must be an array." because a JSON string is a string,
not an array, from PHP's perspective.

The correct pattern (matching purchase-orders/create.blade.php and
purchase-receives/create.blade.php) is to append each item field as
items[index][key] using Laravel's standard form-encoded array notation:

    formData.append(`items[${idx}][product_id]`, item.product_id);
    formData.append(`items[${idx}][qty]`, item.qty);
    ...
"""

import re
import sys
from pathlib import Path

BLADES = [
    Path("/home/z/my-project/laravel/resources/views/admin/purchase-returns/create.blade.php"),
    Path("/home/z/my-project/laravel/resources/views/admin/purchase-returns/index.blade.php"),
]

# Forbidden: any line that calls formData.set('items', JSON.stringify(...))
JSON_STRINGIFY_PATTERN = re.compile(
    r"formData\.set\(\s*['\"]items['\"]\s*,\s*JSON\.stringify\(",
    re.MULTILINE,
)

# Required: at least one formData.append(`items[${idx}][...]`, ...) call
APPEND_PATTERN = re.compile(
    r"formData\.append\(\s*`items\[\$\{idx\}\]\[",
    re.MULTILINE,
)

failures = []
for blade in BLADES:
    if not blade.exists():
        failures.append(f"MISSING: {blade}")
        continue
    text = blade.read_text()

    # Strip JS line comments (// ...) and block comments (/* ... */) so
    # documentation references to the old buggy pattern don't trigger a
    # false positive. We only want to flag ACTUAL code occurrences.
    cleaned = re.sub(r"/\*.*?\*/", "", text, flags=re.DOTALL)  # block comments
    cleaned = re.sub(r"^\s*//.*$", "", cleaned, flags=re.MULTILINE)  # line comments

    # 1. Must NOT contain the JSON.stringify pattern in actual code
    if JSON_STRINGIFY_PATTERN.search(cleaned):
        failures.append(
            f"FAIL: {blade.name} still contains `formData.set('items', JSON.stringify(...))` "
            f"in CODE (not comment) — this sends items as a JSON string and triggers "
            f"'The items field must be an array.' validation error."
        )

    # 2. MUST contain the indexed append pattern
    if not APPEND_PATTERN.search(cleaned):
        failures.append(
            f"FAIL: {blade.name} is missing `formData.append(`items[${{idx}}][...]`, ...)` calls "
            f"— items must be appended as indexed array fields for Laravel to parse them as an array."
        )

print("================================================================")
print("BUG-50 regression check")
print(f"Scanned: {len(BLADES)} blade files under purchase-returns/")
print("================================================================")
print()

if failures:
    for f in failures:
        print(f"  ❌ {f}")
    print()
    print(f"FAIL — {len(failures)} problem(s) found.")
    sys.exit(1)
else:
    print("PASS — no JSON.stringify(items) pattern found in any returns blade.")
    print("All blades now use formData.append(`items[idx][key]`, ...) — Laravel")
    print("will parse these as a proper nested array, satisfying the 'array' rule.")
    sys.exit(0)
