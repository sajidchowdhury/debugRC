# CSS Build Action Plan — `debugRC` / Laravel ERP

**Role:** Senior Laravel Architect + Tailwind CSS Expert + Build-System Engineer
**Prerequisites:** Read `css-build-investigation.md` and `css-build-recommendation.md` first. This plan implements **Option B** (commit compiled CSS + pre-commit guardrail + cache-busting + `@source` directive + Vite cleanup).
**Status:** **DRAFT — pending user approval.** No code changes have been made. This document is the blueprint to be executed once approved.

---

## 0. Guiding Principles

1. **One build tool, one command, one input, one output.**
2. **The end user never runs a build.** `git pull` → refresh → works.
3. **The rebuild is automatic, not remembered.** A pre-commit hook enforces it.
4. **Each phase is independently shippable and independently verifiable.** If phase N is rolled back, phases 1…N−1 still work.
5. **No test code is written** (per project rules). Verification is manual + the existing browser-preview self-check.

---

## Phase 1 — Immediate Fix (unblock the user *now*)

**Goal:** Make the godown page render correctly on the user's machine with the *current* architecture, and sync GitHub so future pulls are correct.

**Steps:**
1. From `laravel/`, run the build with the project's standard package manager:
   ```bash
   bun run build:css
   ```
   (If bun is unavailable, `npm run build:css` works but produces a `package-lock.json` — see Phase 4.)
2. Verify the previously-missing classes are now present:
   ```bash
   for c in bg-indigo-600 from-orange-400 text-slate-800 lg:grid-cols-4 bg-yellow-400; do
     printf "%-18s %s\n" "$c" "$(grep -o "\.$c" public/assets/css/rc-erp.css | wc -l)"
   done
   # expect non-zero for each
   ```
3. Discard the spurious `package-lock.json` if it was created:
   ```bash
   rm -f package-lock.json   # bun uses bun.lockb
   ```
4. Stage only the compiled CSS:
   ```bash
   git add public/assets/css/rc-erp.css
   git commit -m "fix(css): rebuild rc-erp.css with godown redesign classes (cdfe719 et al.)"
   git push
   ```
5. **User verifies:** `git pull` → hard-refresh browser (`Ctrl+Shift+R`) → godown page renders correctly.

**Why hard-refresh in step 5:** The `<link>` tag currently has no cache-busting (fixed in Phase 2). Until then, the browser may serve the old cached CSS. A hard refresh forces a re-download.

**Exit criteria:** Godown page renders with all styles on the user's machine after a plain `git pull` + hard refresh.

---

## Phase 2 — Cache-Busting on the `<link>` Tag

**Goal:** Eliminate the latent "stale browser cache" bug so that future CSS updates are seen without a hard refresh.

**File:** `laravel/resources/views/components/layouts/erp.blade.php` (line 53).

**Change:**
```html
<!-- BEFORE -->
<link rel="stylesheet" href="/assets/css/rc-erp.css">

<!-- AFTER -->
<link rel="stylesheet" href="/assets/css/rc-erp.css?v={{ @filemtime(public_path('assets/css/rc-erp.css')) }}">
```

Notes:
- The `@` suppresses a warning if the file is somehow missing (defensive).
- `filemtime()` returns the file's modification timestamp — changes every time the CSS is rebuilt, so the query string changes, so the browser fetches the new file.
- This mirrors the existing pattern on line 49 (`custom.css?v={{ filemtime(...) }}`), so it is consistent with the codebase.
- Also check `resources/views/layouts/admin.blade.php` (the Bootstrap layout) — if it also links `rc-erp.css`, apply the same change there.

**Verification:**
- View page source in browser → confirm the `<link>` URL ends with `?v=<10-digit-number>`.
- Rebuild CSS → refresh page → confirm the `?v=` number changed.

**Exit criteria:** Every layout that links `rc-erp.css` uses the `?v=filemtime` cache-buster.

---

## Phase 3 — Explicit `@source` Directive (build determinism)

**Goal:** Make Tailwind v4's content scan explicit and independent of the working directory, so the build is deterministic whether run from `laravel/`, the repo root, or a CI runner.

**File:** `laravel/resources/css/rc-erp.css` — add one line immediately after the existing `@import` statements (after line 21):

```css
@import "tailwindcss/theme" layer(theme);
@import "tailwindcss/utilities" layer(utilities);

/* Explicit scan scope — Blade views + ERP Blade components.
   Without this, Tailwind v4 auto-detects from CWD, which is fragile
   if the build is invoked from a different directory. */
@source "../views";
```

Notes:
- `@source "../views"` is relative to the input CSS file (`resources/css/rc-erp.css`), so it resolves to `resources/views/` — covering all admin pages and `components/erp/*` and `components/layouts/*`.
- If Tailwind classes ever appear in PHP files (e.g. a controller echoing markup — discouraged but possible), add a second `@source` line. For now, Blade-only is correct.
- Keep auto-detection as a fallback: `@source` *adds* to the scan list; it does not disable the default CWD scan. So nothing existing breaks.

**Verification:**
- `rm public/assets/css/rc-erp.css && bun run build:css` → file regenerates with all classes.
- Confirm the same class counts as Phase 1 step 2.

**Exit criteria:** Build succeeds and produces identical class coverage with an explicit, documented scan scope.

---

## Phase 4 — Pre-Commit Guardrail (the keystone)

**Goal:** Make it **impossible** to commit Blade changes without a freshly-rebuilt CSS. This is the single change that eliminates the root cause.

### 4.1 Create the hook script

**New file:** `laravel/git-hooks/pre-commit` (executable):
```bash
#!/usr/bin/env bash
# RC ERP pre-commit guard: auto-rebuild rc-erp.css when Blade/CSS-source changes.
set -euo pipefail

# Only act if staged files include blade or the source css
STAGED=$(git diff --cached --name-only --diff-filter=ACMR | grep -E '\.(blade\.php)$|resources/css/rc-erp\.css$' || true)

if [ -z "$STAGED" ]; then
    exit 0   # nothing relevant changed; allow commit
fi

CSS_PATH="public/assets/css/rc-erp.css"
BEFORE=$(git cat-file -p HEAD:"$CSS_PATH" 2>/dev/null | sha256sum | cut -d' ' -f1 || echo "none")

# Rebuild
echo "[pre-commit] Blade/CSS-source changed — rebuilding rc-erp.css…"
( cd "$(git rev-parse --show-toplevel)/laravel" && bun run build:css >/dev/null 2>&1 )

AFTER=$(sha256sum "$CSS_PATH" | cut -d' ' -f1)

if [ "$BEFORE" = "$AFTER" ]; then
    echo "[pre-commit] CSS unchanged after rebuild — no restage needed."
    exit 0
fi

echo "[pre-commit] CSS changed — re-staging $CSS_PATH"
git add "$CSS_PATH"
exit 0
```

### 4.2 Distribute the hook (so fresh clones get it too)

**Edit:** `laravel/composer.json` — add a `post-install-cmd` + `post-update-cmd` that copies the hook into `.git/hooks/`:
```json
"scripts": {
    "post-install-cmd": [
        "bash git-hooks/install.sh"
    ],
    "post-update-cmd": [
        "bash git-hooks/install.sh"
    ]
}
```

**New file:** `laravel/git-hooks/install.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(git rev-parse --show-toplevel 2>/dev/null || echo "")"
if [ -z "$ROOT" ]; then echo "[hooks] not a git repo — skipping"; exit 0; fi
HOOK_SRC="$ROOT/laravel/git-hooks/pre-commit"
HOOK_DST="$ROOT/.git/hooks/pre-commit"
if [ -f "$HOOK_SRC" ]; then
    cp "$HOOK_SRC" "$HOOK_DST"
    chmod +x "$HOOK_DST"
    echo "[hooks] installed pre-commit guard → $HOOK_DST"
fi
```

**One-time manual install** (for the current clone, since composer won't have run yet):
```bash
cd laravel && bash git-hooks/install.sh
```

### 4.3 How it behaves

- AI/dev edits `godown.blade.php`, adds Tailwind classes, `git add` + `git commit`.
- The pre-commit hook fires: detects `.blade.php` in the staged set → runs `bun run build:css` → if the compiled CSS changed, re-stages it → commit proceeds with **both** the Blade and the CSS.
- If the AI forgets to run build:css manually, **the hook does it for them.** The root cause is structurally eliminated.
- If `bun` is not on PATH, the hook fails loudly (set -e) → commit aborts with a clear message → developer installs bun or runs the build manually. No silent stale CSS.

**Verification:**
- Touch a Blade file (add a `text-rose-700` class somewhere trivial), `git add` + `git commit -m "test"` → observe the hook output "CSS changed — re-staging".
- `git show --stat HEAD` → confirm both the Blade and `rc-erp.css` are in the commit.
- Try `git commit --no-verify` (bypass) — this is the documented escape hatch, caught by the CI check in Phase 6.

**Exit criteria:** Any commit that changes Blade markup automatically includes a freshly-rebuilt CSS. The godown-style stale-CSS failure mode is no longer reproducible.

---

## Phase 5 — Remove the Dead Vite Pipeline

**Goal:** Reduce the number of build systems from two to one. Eliminate the confusion of "which pipeline is real?"

**Pre-check:** Confirm no Blade file uses `@vite()`:
```bash
cd laravel && grep -rn "@vite" resources/views/   # expect no matches
```
If matches exist, **stop** and reassess — Vite is in use and this phase must be skipped or reworked.

**Changes:**
1. Delete `laravel/vite.config.js`.
2. Delete `laravel/resources/css/app.css` (empty placeholder).
3. Delete `laravel/resources/js/app.js` if it is the unmodified Laravel starter (verify it's not referenced by any `<script>` first: `grep -rn "resources/js/app" resources/views/ public/`).
4. From `laravel/package.json`, remove:
   - `"dev": "vite"`
   - `"build": "vite build"`
   - devDependencies: `laravel-vite-plugin`, `vite`
5. Keep: `@tailwindcss/cli`, `tailwindcss`, and the `dev:css` / `build:css` scripts.
6. Run `bun install` to refresh `bun.lockb`.
7. `git add -A && git commit -m "chore(build): remove dead Vite pipeline — Tailwind CLI is the sole CSS build"`

**Verification:**
- `bun run build:css` still works and produces the same CSS.
- `php artisan serve` (or whatever serves the app) → pages still render.
- No `@vite` 404s in the browser console.

**Exit criteria:** One build system remains (Tailwind CLI). `package.json` has only CSS scripts. The repo's build story is self-evident.

---

## Phase 6 — CI Safety Net (catch hook bypasses)

**Goal:** Defend against `git commit --no-verify` (which skips the local hook) by re-checking in CI.

**New file:** `.github/workflows/css-guard.yml` (at the repo root, since GitHub Actions must live at `.github/`):
```yaml
name: css-build-guard
on: [push, pull_request]
jobs:
  verify-css:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: oven-sh/setup-bun@v2
      - run: bun install
        working-directory: laravel
      - run: bun run build:css
        working-directory: laravel
      - name: CSS must be in sync with sources
        run: |
          cd laravel
          if ! git diff --exit-code -- public/assets/css/rc-erp.css; then
            echo "::error::Compiled CSS is stale. Run 'bun run build:css' and commit the result."
            git diff --stat -- public/assets/css/rc-erp.css
            exit 1
          fi
```

Notes:
- This rebuilds the CSS in CI and fails if the committed CSS differs from a fresh build — i.e. exactly the stale-CSS condition.
- Catches both `--no-verify` bypasses and any machine where the hook isn't installed.
- Uses bun to match the local environment.

**Exit criteria:** A push with stale CSS fails CI. A push with fresh CSS passes.

---

## Phase 7 — Documentation & Lockfile Hygiene

**Goal:** Make the workflow self-documenting so the next contributor (human or AI) doesn't re-introduce the bug.

**Changes:**
1. **`laravel/README.md`** — add a "Frontend CSS build" section:
   ```markdown
   ## Frontend CSS (Tailwind v4)

   The design system is compiled by the Tailwind CLI (not Vite). Source:
   `resources/css/rc-erp.css` → compiled: `public/assets/css/rc-erp.css`.

   - Build once: `bun run build:css`
   - Watch mode: `bun run dev:css`

   **You normally do not need to run this manually.** A pre-commit hook
   (installed via `composer install` or `bash git-hooks/install.sh`)
   automatically rebuilds and re-stages the compiled CSS whenever a
   `.blade.php` file changes. Just edit Blade, `git commit`, and push.

   The compiled CSS is committed to git on purpose — this lets the
   end user `git pull` and refresh with no build step.
   ```
2. **`.gitignore`** — add `package-lock.json` so an accidental `npm install` doesn't dirty the tree:
   ```
   package-lock.json
   ```
3. Commit `bun.lockb` if it isn't already (it should be — bun's lockfile is meant to be committed).
4. Add a one-line note to the top of `resources/css/rc-erp.css` header comment: *"After editing this file or any Blade, the pre-commit hook rebuilds the compiled output automatically."*

**Exit criteria:** The build workflow is documented in README; lockfile drift is prevented; the source CSS points readers to the hook.

---

## Implementation Order & Dependencies

```
Phase 1 (immediate fix)        ← do FIRST, unblocks the user
   │
   ├─ Phase 2 (cache-busting)  ← independent, can do anytime after Phase 1
   ├─ Phase 3 (@source)        ← independent
   ├─ Phase 5 (Vite removal)   ← independent, but audit @vite first
   │
   └─ Phase 4 (pre-commit hook) ← keystone; depends on nothing but should come
                                   before relying on the workflow
        │
        └─ Phase 6 (CI guard)  ← depends on Phase 4's command existing
              │
              └─ Phase 7 (docs) ← last; documents the final state
```

**Recommended execution order:** 1 → 2 → 3 → 4 → 5 → 6 → 7.

Each phase is a separate commit. If any phase reveals a problem, it can be reverted in isolation.

---

## Verification Matrix (final, after all phases)

| Scenario | Expected behaviour | How to verify |
|---|---|---|
| AI edits Blade, commits normally | Hook rebuilds CSS automatically; commit contains both files | `git show --stat HEAD` lists both Blade + `rc-erp.css` |
| AI edits Blade, commits with `--no-verify` | Local commit succeeds BUT CI fails | Push → GitHub Action "css-build-guard" red |
| User runs `git pull` + normal refresh | New styles appear (cache-busted `?v=` changes) | Browser DevTools → Network → `rc-erp.css?v=...` is the new timestamp |
| Fresh clone of the repo | `composer install` installs the hook; CSS is already committed so page works immediately | Clone → `composer install` → `php artisan serve` → page renders styled |
| Build run from repo root instead of `laravel/` | Still scans the right files (`@source` directive) | `cd /repo/root && bun --cwd laravel run build:css` → class counts unchanged |
| `npm install` run by mistake | `package-lock.json` is gitignored; no tree pollution | `git status` clean after `npm install` |

---

## What This Plan Does NOT Do

- **Does not switch to Vite** — Vite is removed, not adopted. The Tailwind CLI stays.
- **Does not use a runtime CDN** — rejected for the Bootstrap-coexistence and no-CDN reasons in the Recommendation.
- **Does not change the Bootstrap/Tailwind coexistence model** — Preflight stays skipped; `@layer` scoping stays.
- **Does not add a JS bundler** — the project loads JS via plain `<script>` tags from `public/assets/js/`, which works fine.
- **Does not write test code** — per project rules. Verification is manual + CI guard.
- **Does not touch any Blade view's markup** — the godown redesign itself is unaffected; only the build pipeline around it changes.

---

## Approval Gate

This plan is **ready for execution** but **has not been executed**. No code has been modified. Per the user's instruction: *"Do NOT modify any code until you have completed the investigation and documented your findings."*

Upon approval, execution of Phase 1 alone will unblock the user immediately; the remaining phases harden the workflow so the failure cannot recur.

**Estimated effort:** Phase 1 (~2 min) + Phases 2–7 (~30–45 min total). All phases are low-risk, surgical, and independently revertible.
