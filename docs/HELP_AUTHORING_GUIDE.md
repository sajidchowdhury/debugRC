# Help Authoring Guide

> **How to add or edit in-app help content for RC_ERP_v2.**
> Audience: developers + content authors. ~5-minute read.
> Source of truth for the schema: `docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md` §5.
> Source of truth for the architecture: `AI_CONTEXT/architecture/help-system.md`.

The help system is **file-based** — no database, no admin panel, no migrations. Every piece
of help content is a PHP file returning an array. Edit a file → commit → done. This guide
covers the 4 things you'll actually do.

---

## 1. The content file contract

Every menu's help lives at `laravel/resources/help/menus/{module}/{slug}.php` and returns
this exact array shape (12 keys — all required except `diagram`):

```php
<?php
// resources/help/menus/sales/invoices.php
return [
    'key'        => 'sales.invoices',          // MUST equal "{module}.{slug}" (filename without .php)
    'module'     => 'sales',                    // MUST equal the parent dir name
    'title_bn'   => 'সেলস ইনভয়েস',            // Bangla display title (shown in header + breadcrumb)
    'title_en'   => 'Sales Invoice',            // English title (shown in search + meta)
    'icon'       => 'fa-file-invoice-dollar',   // FontAwesome 6 solid icon class (no "fa-" prefix needed in some spots, but include it)
    'summary'    => 'এক বাক্যে পেজের উদ্দেশ্য।',  // ONE Bangla sentence. (Soft limit — 2 is tolerated with a validator warning.)

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],  // who this page is for

    'what_you_can_do' => [                       // 3–6 action bullets, each {icon, text}
        ['icon' => 'fa-plus',         'text' => 'নতুন ইনভয়েস তৈরি করা'],
        ['icon' => 'fa-list',         'text' => 'আগের সব ইনভয়েস দেখা ও খুঁজা'],
        ['icon' => 'fa-circle-check', 'text' => 'কাস্টমারের পেমেন্ট এন্ট্রি করা'],
    ],

    'impacts' => [                               // who/what changes when you use this page
        ['who' => 'খদ্দের', 'what' => 'বকেয়া বাড়ে'],
        ['who' => 'স্টক',   'what' => 'পণ্য কমে যায়'],
    ],

    'cautions' => [                              // 1–3 risk callouts (amber box)
        'ইনভয়েস একবার ফাইনাল হলে সরাসরি এডিট করা যায় না — রিটার্ন দিতে হবে।',
    ],

    'related' => ['sales.cart', 'sales.challans', 'sales.returns'],  // other menu_keys (clickable chips)

    'diagram' => 'sales-invoice-flow',           // OPTIONAL — key into diagrams.php (Mermaid). Omit if no diagram.

    'updated_at' => '2026-08-07',                // ISO date. Bump when you edit the file.
];
```

**The two non-negotiable rules** (the validator enforces these):
1. `key` **must equal** `"{module}.{slug}"` where slug = the filename without `.php`.
   e.g. file `menus/sales/invoices.php` → `key` = `sales.invoices`. If these don't match,
   `HelpService` won't find the file at runtime (it loads by slug = filename).
2. `module` **must equal** the parent directory name.

---

## 2. Add help for a brand-new page

When a developer adds a new ERP page (a new route + controller + view), three things must
happen for it to get help:

### Step A — create the content file

```
laravel/resources/help/menus/{module}/{slug}.php
```

Pick the `module` (one of: `master-data`, `inventory`, `purchasing`, `sales`, `accounting`,
`finance`, `reports`, `system`) and a kebab-case `slug` matching the route's resource name
(e.g. `delivery-notes`). Copy the template in §1 above, fill it in.

### Step B — register the route → menu_key mapping

Add one line to **`laravel/resources/help/registry.php`** (the Layer-1 exact-match map):

```php
'admin.delivery-notes.index' => 'sales.delivery-notes',
```

If the page is a standard resource action (`create`/`show`/`edit`) on a controller that
already has a `Controller@*` wildcard in `action-registry.php`, you can **skip Step B** —
Layer 3 will resolve it automatically. Only add to `registry.php` when you want an exact,
non-wildcard mapping (recommended for the `index` page of each resource).

### Step C — list the menu in its module

Add the new menu_key string to the `menus` array of the right module in
**`laravel/resources/help/modules.php`**:

```php
'menus' => ['sales.cart', 'sales.invoices', 'sales.delivery-notes', /* ... */],
```

This makes it appear as a clickable row in the Door-2 module offcanvas. (If the page is a
sub-page like an audit trail or print view, you may skip Step C and instead link it from the
parent menu's `related` array — it'll still load via `HelpService`, just won't be a primary
card. See the 32 "secondary" files in Appendix A for precedent.)

### Step D — clear the cache

The registries are cached for 1 day (`HelpService::CACHE_TTL`). After editing any of the
four registry/config files, clear the cache:

```bash
php artisan cache:forget help:registry
php artisan cache:forget help:action-registry
php artisan cache:forget help:modules
php artisan cache:forget help:diagrams
# or simply:
php artisan cache:clear
```

(Content files in `menus/` are NOT cached — they're `require`d per request and opcode-cached
by PHP. So editing a content file needs no cache clear.)

### ⚠️ Blade gotcha: never pass a multi-statement expression to `@json()`

The `@json()` Blade directive splits its argument on the first comma (to inject
the default JSON-flags argument). This means **multi-statement closures,
IIFEs, or any expression with a comma inside `(...)`** will be mangled into
invalid PHP at compile time — producing `ParseError: syntax error, unexpected
token ";"` at runtime.

**Don't do this:**
```blade
searchIndex: @json((function () use ($helpService) {
    $out = [];
    foreach ($helpService->modules() as $k => $m) { /* ... */ }
    return $out;
})()),
```

**Do this instead** — compute in `@php`, emit the variable:
```blade
@php
    $searchIndex = [];
    foreach ($helpService->modules() as $k => $m) { /* ... */ }
@endphp

<script>
    searchIndex: @json($searchIndex),
</script>
```

Single-line expressions with arrow functions (e.g. `@json(collect($x)->mapWithKeys(fn ($m, $k) => [$k => $m['title_bn']]))`) are safe — the comma in `fn ($m, $k)` only adds harmless extra whitespace after the split-and-rejoin. The rule of thumb: **if it has braces `{ }` or more than one statement, move it to `@php`.**

---

## 3. Add a Mermaid diagram

Diagrams are enhancement-only — a page works fine without one. To add one:

1. Pick a diagram key (kebab-case, e.g. `delivery-flow`).
2. Add the Mermaid snippet to **`laravel/resources/help/diagrams.php`**:

   ```php
   'delivery-flow' => <<<MERMAID
   flowchart LR
       A[অর্ডার] --> B[পিকিং]
       B --> C[প্যাকেজিং]
       C --> D[ডেলিভারি]
   MERMAID,
   ```

3. Reference it from a content file: `'diagram' => 'delivery-flow',`

Mermaid itself is **lazy-loaded** from CDN (`cdn.jsdelivr.net/npm/mermaid@10`) — the
~1 MB script is only fetched the first time a page with a diagram is opened. If the CDN is
blocked (BDIX VPS), the diagram block silently hides; no crash.

---

## 4. Edit existing content

1. Open the file: `laravel/resources/help/menus/{module}/{slug}.php`.
2. Edit the Bangla text. Keep `summary` to **one sentence** (the validator warns at 2).
3. Bump `'updated_at'` to today's date.
4. Commit. No cache clear needed (content files aren't cached, only the 4 registries are).

---

## 5. Validate before you commit

Two static validators live in `docs/help-sweep/` and run without a PHP runtime (pure Python,
regex-based). Run both after any content change:

```bash
# Validates every content file's schema + cross-references (10 checks per file).
python3 docs/help-sweep/phase7_validate.py

# Re-runs the route-resolution sweep + regenerates the coverage report.
python3 docs/help-sweep/phase6_sweep.py
```

**`phase7_validate.py`** checks per file: opens with `<?php`, ends with `];`, brace balance,
all 12 required keys present, `key` == path-derived key, `module` == dir, `updated_at` format,
no Unicode replacement chars (Bangla corruption), `diagram` key exists in `diagrams.php`,
every `related` key exists in `modules.php`. Plus cross-checks A (every modules.php menu has
a content file) and B (every content file's key is reachable).

**`phase6_sweep.py`** simulates the 4-layer resolution for all 215 curated page routes +
81 resource-expanded runtime routes, and regenerates `docs/help-coverage-matrix.csv` +
`docs/help-coverage-report.md`.

**Pass criteria:** `TOTAL ERRORS: 0`. Soft warnings (summary sentence count) are acceptable.

---

## 6. The module colour palette

Each module has a Tailwind colour token (`modules.php` → `color`). This drives the tint of
the card, header gradient, focus rings, chips, and callout borders via a CSS custom property
(`--help-tint-c1/c2`) set by `help.js` from `data-help-color`. The 8 colours:

| Module | Colour | Tailwind token |
|---|---|---|
| Master Data | slate | `slate` |
| Inventory | amber | `amber` |
| Purchasing | sky | `sky` |
| Sales | emerald | `emerald` |
| Accounting | violet | `violet` |
| Finance | rose | `rose` |
| Reports | teal | `teal` |
| System | indigo | `indigo` |

To change a module's colour, edit the `color` value in `modules.php`. The CSS variable chain
propagates it everywhere automatically. (Per project convention, indigo/violet are allowed
here because they were the owner-confirmed module palette, not a UI-wide theme choice.)

---

## 7. Where things live (cheat sheet)

| What | Where |
|---|---|
| Content files (215) | `laravel/resources/help/menus/{module}/{slug}.php` |
| Module metadata (8) | `laravel/resources/help/modules.php` |
| Route → menu_key (214) | `laravel/resources/help/registry.php` |
| Controller@action → menu_key (273) | `laravel/resources/help/action-registry.php` |
| Mermaid snippets | `laravel/resources/help/diagrams.php` |
| Service (resolver + loader) | `laravel/app/Services/Help/HelpService.php` |
| Controller (2 endpoints) | `laravel/app/Http/Controllers/HelpController.php` |
| Blade components (6) | `laravel/resources/views/components/help/*.blade.php` |
| Layout include (1 line) | `laravel/resources/views/partials/help-system.blade.php` |
| CSS | `laravel/public/assets/css/help-system.css` |
| JS | `laravel/public/assets/js/help.js` |
| Routes (throttled) | `laravel/routes/web.php` → `Route::prefix('help')` |
| Validators | `docs/help-sweep/phase6_sweep.py`, `phase7_validate.py` |
| Coverage report | `docs/help-coverage-report.md` (auto-generated) |
| Coverage matrix | `docs/help-coverage-matrix.csv` (auto-generated) |

---

## 8. Reverting

The help system is **opt-in per layout** via a single `@include('partials.help-system')`
line in `layouts/admin.blade.php` + `layouts/app.blade.php`. Remove those two lines (or
`git revert` the help-system commits) and the entire help UI disappears with zero trace —
no DB changes, no composer deps, no npm packages. Content files remain on disk but are
unreachable until re-included.

---

*Questions? Read `AI_CONTEXT/architecture/help-system.md` for the full architecture deep-dive,
or `docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md` for the original 10-phase design + acceptance
criteria.*
