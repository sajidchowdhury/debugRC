#!/usr/bin/env python3
"""Smarter PHP structure checker — preserves newlines so line numbers stay accurate."""
import sys
import re

def check_php(path: str) -> list:
    errors = []
    try:
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
    except FileNotFoundError:
        return [f"FILE NOT FOUND: {path}"]

    # 1) Block comments → preserve newlines (replace with same number of newlines)
    def block_repl(m):
        return '\n' * m.group(0).count('\n')
    s = re.sub(r'/\*.*?\*/', block_repl, content, flags=re.DOTALL)
    # 2) Strings → empty (preserve nothing — strings are single-line usually)
    s = re.sub(r'"(?:\\.|[^"\\])*"', '""', s)
    s = re.sub(r"'(?:\\.|[^'\\])*'", "''", s)
    # 3) Line comments → strip to end of line
    s = re.sub(r'//[^\n]*', '', s)
    s = re.sub(r'#[^\n]*', '', s)
    # 4) Heredoc/nowdoc (best-effort) → preserve newlines
    def heredoc_repl(m):
        return '\n' * m.group(0).count('\n')
    s = re.sub(r'<<<[A-Z_]+\n.*?\n[A-Z_]+;', heredoc_repl, s, flags=re.DOTALL)

    # Balance check
    pairs = {')': '(', ']': '[', '}': '{'}
    opens = set(pairs.values())
    stack = []
    line = 1
    for ch in s:
        if ch == '\n':
            line += 1
            continue
        if ch in opens:
            stack.append((ch, line))
        elif ch in pairs:
            if not stack:
                errors.append(f"Line {line}: closing '{ch}' with empty stack")
                continue
            top, top_line = stack.pop()
            if top != pairs[ch]:
                errors.append(f"Line {line}: closing '{ch}' does not match opening '{top}' at line {top_line}")
                stack.append((top, top_line))
    if stack:
        for ch, line in stack[:5]:
            errors.append(f"Unclosed '{ch}' opened at line {line}")
    return errors

if __name__ == '__main__':
    any_err = False
    for f in sys.argv[1:]:
        errs = check_php(f)
        if errs:
            any_err = True
            print(f"FAIL  {f}")
            for e in errs:
                print(f"      {e}")
        else:
            print(f"OK    {f}")
    sys.exit(1 if any_err else 0)
