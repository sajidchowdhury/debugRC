#!/usr/bin/env python3
"""
Audit: which tables in the RC_ERP schema have a `deleted_at` column,
and which SoftDeletes-using Eloquent models map to tables WITHOUT that column.

Sources checked:
  - laravel/database/sql/0*.sql  (baseline schema)
  - laravel/database/migrations/*.php  (any ALTER TABLE ... ADD COLUMN deleted_at)

A model is flagged only if NO source (SQL or migration) provides the column.
"""
from __future__ import annotations
import re
import os
from pathlib import Path

ROOT = Path("/home/z/my-project/debugRC/laravel")
SQL_DIR = ROOT / "database" / "sql"
MIG_DIR = ROOT / "database" / "migrations"
MODELS_DIR = ROOT / "app" / "Models"

# 1. Parse SQL files: capture table_name -> has deleted_at?
tables_with_deleted_at: set[str] = set()
all_tables: set[str] = set()

CREATE_RE = re.compile(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[\"']?(\w+)[\"']?\s*\(", re.IGNORECASE)

for sql_file in sorted(SQL_DIR.glob("*.sql")):
    text = sql_file.read_text()
    # find each CREATE TABLE block
    for m in CREATE_RE.finditer(text):
        tbl = m.group(1)
        all_tables.add(tbl)
        # extract block from m.start() to first ');' at start of line
        start = m.end()
        # find end of CREATE TABLE: next '\n);' or '\n)' line
        end_match = re.search(r"\n\)\s*;", text[start:])
        if not end_match:
            continue
        block = text[start:start + end_match.start()]
        if re.search(r"\bdeleted_at\b", block):
            tables_with_deleted_at.add(tbl)

# 2. Parse migrations: ALTER TABLE x ADD COLUMN deleted_at ... / ->table('x')->softDeletes()
ADD_RE = re.compile(
    r"ALTER\s+TABLE\s+[\"']?(\w+)[\"']?\s+ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?[\"']?deleted_at[\"']?",
    re.IGNORECASE,
)
SCHEMA_TABLE_RE = re.compile(
    r"Schema::table\(\s*[\"'](\w+)[\"']\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\)",
    re.DOTALL,
)

for mig_file in sorted(MIG_DIR.glob("*.php")):
    text = mig_file.read_text()
    # explicit ALTER TABLE
    for m in ADD_RE.finditer(text):
        tables_with_deleted_at.add(m.group(1))
    # Schema::table(...)->softDeletes() / ->softDeletes('col')
    for m in SCHEMA_TABLE_RE.finditer(text):
        tbl, body = m.group(1), m.group(2)
        if re.search(r"->softDeletes\s*\(", body):
            tables_with_deleted_at.add(tbl)
    # Schema::create(...)->softDeletes()
    CREATE_SCHEMA_RE = re.compile(
        r"Schema::create\(\s*[\"'](\w+)[\"']\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\)",
        re.DOTALL,
    )
    for m in CREATE_SCHEMA_RE.finditer(text):
        tbl, body = m.group(1), m.group(2)
        if re.search(r"->softDeletes\s*\(", body):
            tables_with_deleted_at.add(tbl)

# 3. Parse models: which use SoftDeletes, what table do they map to?
# Laravel convention: Model name -> snake_case plural, unless protected $table is set.
def snake(s: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "_", s).lower()

# naive pluralization
def plural(s: str) -> str:
    if s.endswith("y") and s[-2:-1] not in "aeiou":
        return s[:-1] + "ies"
    if s.endswith(("s","x","ch","sh")):
        return s + "es"
    return s + "s"

model_to_table: dict[str, str] = {}
soft_deletes_models: dict[str, str] = {}  # model_name -> table_name

for php_file in sorted(MODELS_DIR.rglob("*.php")):
    text = php_file.read_text()
    if "use SoftDeletes" not in text and "use SoftDeletes;" not in text:
        continue
    cls = php_file.stem
    # find protected $table
    mt = re.search(r"protected\s+\\\$table\s*=\s*[\"'](\w+)[\"']", text)
    if mt:
        tbl = mt.group(1)
    else:
        tbl = plural(snake(cls))
    soft_deletes_models[cls] = tbl
    model_to_table[cls] = tbl

# 4. Report
print("=" * 78)
print("AUDIT: SoftDeletes models vs tables missing `deleted_at`")
print("=" * 78)
print(f"\nTotal SoftDeletes-using models: {len(soft_deletes_models)}")
print(f"Tables known to have deleted_at: {len(tables_with_deleted_at)}\n")

print("Models that WILL fail at runtime (table has no deleted_at):")
print("-" * 78)
bad = []
for cls, tbl in sorted(soft_deletes_models.items()):
    if tbl not in tables_with_deleted_at:
        bad.append((cls, tbl))
        print(f"  ✗ {cls:30s} -> table `{tbl}` (no deleted_at column anywhere)")
if not bad:
    print("  (none)")
print()
print("Models that are OK (table has deleted_at):")
print("-" * 78)
for cls, tbl in sorted(soft_deletes_models.items()):
    if tbl in tables_with_deleted_at:
        print(f"  ✓ {cls:30s} -> table `{tbl}`")
