#!/usr/bin/env bash
# ====================================================================
# Installs the RC ERP pre-commit CSS guard into .git/hooks/pre-commit.
#
# Wired into composer.json's post-autoload-dump script so it runs
# automatically after `composer install` / `composer update`. Safe to
# run repeatedly (idempotent). Also runnable directly:
#     bash laravel/git-hooks/install.sh
#
# Does NOT clobber an existing pre-commit hook unless it is already the
# RC ERP guard (detected by its header comment). If a different hook
# exists, it is left untouched with a warning.
# ====================================================================
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || echo "")"
if [ -z "$ROOT" ]; then
    # Not a git repo (e.g. composer install on an extracted archive) — skip.
    exit 0
fi

HOOK_SRC="$ROOT/laravel/git-hooks/pre-commit"
HOOK_DST="$ROOT/.git/hooks/pre-commit"

if [ ! -f "$HOOK_SRC" ]; then
    exit 0   # defensive — source hook missing
fi

# If a hook already exists, only overwrite if it's ours.
if [ -f "$HOOK_DST" ]; then
    if head -3 "$HOOK_DST" 2>/dev/null | grep -q "RC ERP pre-commit guard"; then
        : # ours — overwrite below
    else
        echo "[hooks] A pre-commit hook already exists at $HOOK_DST — skipping install." >&2
        echo "[hooks] Inspect it and merge manually if you need the RC ERP CSS guard." >&2
        exit 0
    fi
fi

cp "$HOOK_SRC" "$HOOK_DST"
chmod +x "$HOOK_DST"
echo "[hooks] installed RC ERP pre-commit CSS guard -> $HOOK_DST"
