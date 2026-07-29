#!/usr/bin/env python3
"""Check bracket balance in compiled blade PHP."""
import re

with open('/tmp/compiled_returns.php') as f:
    src = f.read()

# Remove PHP comments
src = re.sub(r'//.*?$', '', src, flags=re.MULTILINE)
src = re.sub(r'/\*.*?\*/', '', src, flags=re.DOTALL)
# Remove single-quoted strings
src = re.sub(r"'(?:[^'\\]|\\.)*'", "''", src)
# Remove double-quoted strings
src = re.sub(r'"(?:[^"\\]|\\.)*"', '""', src)

parens = 0
brackets = 0
braces = 0
line = 1
for i, ch in enumerate(src):
    if ch == '\n':
        line += 1
        continue
    if ch == '(':
        parens += 1
    elif ch == ')':
        parens -= 1
    elif ch == '[':
        brackets += 1
    elif ch == ']':
        brackets -= 1
    elif ch == '{':
        braces += 1
    elif ch == '}':
        braces -= 1
    if parens < 0 or brackets < 0 or braces < 0:
        print('Unbalanced at line', line, ': parens=', parens, 'brackets=', brackets, 'braces=', braces)
        context = src.split('\n')[line-1] if line-1 < len(src.split('\n')) else ''
        print('Context:', context[:200])
        # Show 3 lines before and after
        lines = src.split('\n')
        for j in range(max(0, line-4), min(len(lines), line+3)):
            print(f'  L{j+1}: {lines[j][:200]}')
        break
else:
    print('Final balance: parens=', parens, 'brackets=', brackets, 'braces=', braces)
