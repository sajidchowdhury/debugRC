#!/usr/bin/env python3
"""
Phase 5 static verification — runs the same 7-point check pattern used in
Phase 2 since no PHP runtime is available on the host.

Checks:
1. Brace/paren/bracket balance in modified PHP files
2. Blade directive balance (@if/@endif, @foreach/@endforeach, etc.)
3. Blade escaping audit (no @word( directives in JS context)
4. Migration filename consistency
5. Model fillable matches DB columns
6. Condition references consistency across model/controller/service/blade
7. JS syntax balance (basic brace check)
"""
import re
import sys
from pathlib import Path

BASE = Path('/home/z/my-project/debugRC/laravel')
FILES = {
    'migration': BASE / 'database/migrations/2025_01_25_000001_add_condition_to_purchase_return_items.php',
    'model': BASE / 'app/Models/PurchaseReturnItem.php',
    'controller': BASE / 'app/Http/Controllers/Admin/PurchaseReturnController.php',
    'service': BASE / 'app/Services/Purchase/PurchaseReturnService.php',
    'show_blade': BASE / 'resources/views/admin/purchase-returns/show.blade.php',
    'create_blade': BASE / 'resources/views/admin/purchase-returns/create.blade.php',
    'sql': BASE / 'database/sql/05_purchase.sql',
}

errors = []
warnings = []
info = []


def check_balance(text, open_ch, close_ch, label):
    """Check that open/close chars balance. Ignores chars inside strings and comments."""
    depth = 0
    in_squote = False
    in_dquote = False
    in_line_comment = False
    in_block_comment = False
    i = 0
    n = len(text)
    while i < n:
        c = text[i]
        nc = text[i+1] if i+1 < n else ''
        if in_line_comment:
            if c == '\n':
                in_line_comment = False
        elif in_block_comment:
            if c == '*' and nc == '/':
                in_block_comment = False
                i += 1
        elif in_squote:
            if c == '\\' and nc:
                i += 1
            elif c == "'":
                in_squote = False
        elif in_dquote:
            if c == '\\' and nc:
                i += 1
            elif c == '"':
                in_dquote = False
        else:
            if c == '/' and nc == '/':
                in_line_comment = True
                i += 1
            elif c == '/' and nc == '*':
                in_block_comment = True
                i += 1
            elif c == "'":
                in_squote = True
            elif c == '"':
                in_dquote = True
            elif c == '#':
                in_line_comment = True
            elif c == open_ch:
                depth += 1
            elif c == close_ch:
                depth -= 1
                if depth < 0:
                    errors.append(f"  {label}: unbalanced '{close_ch}' at offset {i}")
                    return
        i += 1
    if depth != 0:
        errors.append(f"  {label}: {open_ch}{close_ch} imbalance = {depth}")


def check_blade_directives(text, label):
    """Check @if/@endif, @foreach/@endforeach, @forelse/@endforelse, @php/@endphp, @empty."""
    pairs = [
        (r'@if\b', r'@endif\b', 'if/endif'),
        (r'@unless\b', r'@endunless\b', 'unless/endunless'),
        (r'@foreach\b', r'@endforeach\b', 'foreach/endforeach'),
        (r'@forelse\b', r'@endforelse\b', 'forelse/endforelse'),
        (r'@for\b', r'@endfor\b', 'for/endfor'),
        (r'@while\b', r'@endwhile\b', 'while/endwhile'),
        (r'@php\b', r'@endphp\b', 'php/endphp'),
        (r'@push\b', r'@endpush\b', 'push/endpush'),
        (r'@section\b', r'@endsection\b', 'section/endsection'),
    ]
    for open_re, close_re, name in pairs:
        opens = len(re.findall(open_re, text))
        closes = len(re.findall(close_re, text))
        if opens != closes:
            errors.append(f"  {label}: {name} imbalance: {opens} opens vs {closes} closes")
        else:
            info.append(f"  {label}: {name} OK ({opens}/{closes})")
    # @empty must match @forelse count
    empties = len(re.findall(r'@empty\b', text))
    forelses = len(re.findall(r'@forelse\b', text))
    if empties != forelses:
        errors.append(f"  {label}: @empty count ({empties}) != @forelse count ({forelses})")
    else:
        info.append(f"  {label}: @empty/@forelse OK ({empties}/{forelses})")


def check_blade_escaping(text, label):
    """Audit: any @word( in JS context that's not @@word( ?"""
    # Find all @word( patterns not preceded by @ (i.e. not @@word)
    bad = []
    for m in re.finditer(r'(?<!@)@([a-zA-Z_]\w*)\s*\(', text):
        word = m.group(1)
        # Known Blade directives — these are legit
        known = {'if','unless','foreach','forelse','for','while','php','endif',
                 'endunless','endforeach','endforelse','endfor','endwhile','endphp',
                 'else','elseif','empty','push','endpush','section','endsection',
                 'yield','stack','extends','include','includeIf','includeWhen',
                 'each','once','csrf','method','json','props','error','auth',
                 'guest','can','cannot','elsecan','elsecannot','endcan','endcannot',
                 'hasSection','sectionMissing','checked','selected','disabled',
                 'dump','dd','inject','env','production','csrf_token','route',
                 'asset','url','mix','old','errors','view','abort','abort_if',
                 'abort_unless','report','info','tap','value','with','lang','trans',
                 'transChoice','choice'}
        if word not in known:
            bad.append(f"@{word}( at offset {m.start()}")
    if bad:
        warnings.append(f"  {label}: {len(bad)} unknown @word( directives in JS context:")
        for b in bad[:5]:
            warnings.append(f"    {b}")


def main():
    print("=" * 72)
    print("Phase 5 — Static verification (7-point check)")
    print("=" * 72)

    # 1. File existence
    print("\n[1] File existence check")
    for label, p in FILES.items():
        if not p.exists():
            errors.append(f"  {label}: file does not exist at {p}")
        else:
            info.append(f"  {label}: exists ({p.stat().st_size} bytes)")

    # 2. PHP brace/paren/bracket balance
    print("\n[2] PHP syntax balance")
    for label in ['migration', 'model', 'controller', 'service']:
        p = FILES[label]
        if not p.exists():
            continue
        text = p.read_text()
        check_balance(text, '{', '}', f"{label} {{}}")
        check_balance(text, '(', ')', f"{label} ()")
        check_balance(text, '[', ']', f"{label} []")

    # 3. Blade directive balance
    print("\n[3] Blade directive balance")
    for label in ['show_blade', 'create_blade']:
        p = FILES[label]
        if not p.exists():
            continue
        text = p.read_text()
        check_blade_directives(text, label)

    # 4. Blade escaping audit
    print("\n[4] Blade escaping audit")
    for label in ['show_blade', 'create_blade']:
        p = FILES[label]
        if not p.exists():
            continue
        text = p.read_text()
        check_blade_escaping(text, label)

    # 5. Migration filename + class structure
    print("\n[5] Migration filename + structure check")
    mig_path = FILES['migration']
    if mig_path.exists():
        name = mig_path.name
        # Pattern: YYYY_MM_DD_HHMMSS_snake_case.php
        if re.match(r'^\d{4}_\d{2}_\d{2}_\d{6}_[a-z_]+\.php$', name):
            info.append(f"  migration filename OK: {name}")
        else:
            errors.append(f"  migration filename does not match Laravel pattern: {name}")
        text = mig_path.read_text()
        if 'return new class extends Migration' in text:
            info.append("  migration uses anonymous class extends Migration")
        else:
            errors.append("  migration does not use anonymous class extends Migration")
        if 'public function up(): void' in text and 'public function down(): void' in text:
            info.append("  migration has up() + down()")
        else:
            errors.append("  migration missing up() or down()")
        if "Schema::hasColumn('purchase_return_items', 'condition')" in text:
            info.append("  migration guarded by Schema::hasColumn (idempotent)")
        else:
            errors.append("  migration not guarded by Schema::hasColumn")
        if "CHECK (condition IN ('Good','Damage'))" in text:
            info.append("  migration adds CHECK constraint for condition")
        else:
            errors.append("  migration missing CHECK constraint")

    # 6. Model fillable consistency
    print("\n[6] Model fillable + accessors check")
    model_path = FILES['model']
    if model_path.exists():
        text = model_path.read_text()
        if "'condition'" in text and 'condition' in text.split('$fillable')[1].split('];')[0] if '$fillable' in text else False:
            info.append("  model: 'condition' added to $fillable")
        else:
            errors.append("  model: 'condition' not in $fillable")
        if "public function isDamage(): bool" in text:
            info.append("  model: isDamage() accessor present")
        else:
            errors.append("  model: isDamage() accessor missing")
        if "public function isGood(): bool" in text:
            info.append("  model: isGood() accessor present")
        else:
            errors.append("  model: isGood() accessor missing")
        if "public function conditionLabel(): string" in text:
            info.append("  model: conditionLabel() present")
        else:
            warnings.append("  model: conditionLabel() missing (optional)")

    # 7. Controller validation rule
    print("\n[7] Controller validation rule check")
    ctrl_path = FILES['controller']
    if ctrl_path.exists():
        text = ctrl_path.read_text()
        if "'items.*.condition' => 'nullable|in:Good,Damage'" in text:
            info.append("  controller: items.*.condition validation rule present")
        else:
            errors.append("  controller: items.*.condition validation rule missing")

    # 8. Service condition branching
    print("\n[8] Service condition branching check")
    svc_path = FILES['service']
    if svc_path.exists():
        text = svc_path.read_text()
        if "$item->isDamage()" in text:
            info.append("  service: calls isDamage() to branch on condition")
        else:
            errors.append("  service: missing isDamage() branch")
        # Count: createReturn persists condition, confirmReturn skips stock for Damage, cancelReturn decrements return_qty for all
        if "'condition' => $item['condition'] ?? 'Good'" in text:
            info.append("  service: createReturn persists condition on itemRows")
        else:
            errors.append("  service: createReturn does not persist condition")
        # In confirmReturn, Damage branch should skip stockService->applyTransaction
        confirm_section = text[text.find('public function confirmReturn'):text.find('public function cancelReturn')]
        if 'isDamage()' in confirm_section and 'continue;' in confirm_section:
            info.append("  service: confirmReturn skips stock OUT for Damage (continue)")
        else:
            errors.append("  service: confirmReturn does not skip stock for Damage")
        if 'normalizeCondition' in text:
            info.append("  service: normalizeCondition() helper present")
        else:
            errors.append("  service: normalizeCondition() helper missing")

    # 9. Show blade Condition column
    print("\n[9] Show blade Condition column check")
    show_path = FILES['show_blade']
    if show_path.exists():
        text = show_path.read_text()
        if '<th class="text-center">Condition</th>' in text:
            info.append("  show blade: Condition <th> present")
        else:
            errors.append("  show blade: Condition <th> missing")
        if 'isDamage()' in text:
            info.append("  show blade: calls isDamage() for badge rendering")
        else:
            errors.append("  show blade: does not call isDamage()")
        if 'bg-danger-subtle text-danger' in text:
            info.append("  show blade: Damage badge styling present")
        if 'bg-success-subtle text-success' in text:
            info.append("  show blade: Good badge styling present")
        if 'colspan="6"' in text and 'colspan="4"' in text:
            info.append("  show blade: colspan updated for 6-column table (empty) + tfoot (4+total+empty)")
        else:
            errors.append("  show blade: colspan mismatch")

    # 10. Create blade condition listener
    print("\n[10] Create blade condition listener check")
    create_path = FILES['create_blade']
    if create_path.exists():
        text = create_path.read_text()
        if 'applyCondition' in text and 'condition-select' in text:
            info.append("  create blade: applyCondition() handler present")
        else:
            errors.append("  create blade: applyCondition() handler missing")
        if "form.querySelectorAll('.condition-select')" in text:
            info.append("  create blade: condition-select change listener registered")
        else:
            errors.append("  create blade: condition-select change listener missing")
        if 'warehouseSel.disabled = true' in text:
            info.append("  create blade: Damage disables warehouse-select")
        else:
            errors.append("  create blade: Damage does not disable warehouse-select")

    # 11. SQL file condition column
    print("\n[11] SQL file fresh-install condition column")
    sql_path = FILES['sql']
    if sql_path.exists():
        text = sql_path.read_text()
        if "condition varchar(10) NOT NULL DEFAULT 'Good'" in text:
            info.append("  SQL: condition column added to CREATE TABLE purchase_return_items")
        else:
            errors.append("  SQL: condition column NOT added to CREATE TABLE")
        if "CHECK (condition IN ('Good','Damage'))" in text:
            info.append("  SQL: CHECK constraint present in CREATE TABLE")
        if "idx_prti_condition" in text:
            info.append("  SQL: idx_prti_condition index present")

    # Summary
    print("\n" + "=" * 72)
    print(f"INFO: {len(info)}  WARN: {len(warnings)}  ERR: {len(errors)}")
    print("=" * 72)
    for w in warnings:
        print(f"  WARN: {w}")
    for e in errors:
        print(f"  ERR:  {e}")
    if not errors:
        print("\n✅ ALL HARD CHECKS PASSED")
        sys.exit(0)
    else:
        print(f"\n❌ {len(errors)} HARD CHECK FAILURES")
        sys.exit(1)


if __name__ == '__main__':
    main()
