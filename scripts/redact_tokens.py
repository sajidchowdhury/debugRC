#!/usr/bin/env python3
"""Redact GitHub tokens from SESSION_CONTEXT.md so the push doesn't trip
GitHub's secret scanner."""
import re
from pathlib import Path

p = Path('/home/z/my-project/debugRC/docs/SESSION_CONTEXT.md')
text = p.read_text()

# Match GitHub classic PATs: ghp_ followed by 36 alphanumerics
pattern = re.compile(r'ghp_[A-Za-z0-9]{30,}')
new_text, count = pattern.subn('[REDACTED:github_token]', text)
p.write_text(new_text)
print(f"Redacted {count} GitHub token(s) from {p}")
