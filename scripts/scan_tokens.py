#!/usr/bin/env python3
"""Scan ALL files in the debugRC repo (excluding .git) for any GitHub
classic PAT patterns and report them."""
import re
from pathlib import Path

BASE = Path('/home/z/my-project/debugRC')
pattern = re.compile(r'ghp_[A-Za-z0-9]{30,}')

found = []
for p in BASE.rglob('*'):
    if not p.is_file():
        continue
    if '.git' in p.parts:
        continue
    try:
        text = p.read_text(errors='ignore')
    except Exception:
        continue
    for m in pattern.finditer(text):
        # Find line number
        line_no = text[:m.start()].count('\n') + 1
        found.append((str(p.relative_to(BASE)), line_no, m.group(0)))

if not found:
    print("✅ No GitHub tokens found in any tracked file.")
else:
    print(f"❌ Found {len(found)} token(s):")
    for path, line, tok in found:
        print(f"  {path}:{line}: {tok[:10]}...{tok[-4:]}")
