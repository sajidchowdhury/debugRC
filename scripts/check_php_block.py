#!/usr/bin/env python3
"""Extract @php block and check for PHP syntax issues."""
import re

with open('/home/z/my-project/laravel/resources/views/admin/purchase-returns/index.blade.php') as f:
    src = f.read()

# Extract @php ... @endphp block
m = re.search(r'@php\b(.*?)@endphp', src, re.DOTALL)
if not m:
    print('No @php block found')
    exit(1)

php_block = m.group(1)
print('=== PHP BLOCK ===')
print(php_block)
print('=== END PHP BLOCK ===')
print()

# Remove strings and comments, then check bracket balance
no_comments = re.sub(r'//.*?$', '', php_block, flags=re.MULTILINE)
no_comments = re.sub(r'/\*.*?\*/', '', no_comments, flags=re.DOTALL)
no_str = re.sub(r"'(?:[^'\\]|\\.)*'", "''", no_comments)
no_str = re.sub(r'"(?:[^"\\]|\\.)*"', '""', no_str)

# Find each line's bracket balance
line = 1
parens = 0
brackets = 0
braces = 0
for ch in no_str:
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

print(f'Final balance: parens={parens} brackets={brackets} braces={braces}')

# Try to compile the @php block as PHP and check syntax
php_code = '<?php\n' + php_block + '\n?>'
with open('/tmp/php_block_test.php', 'w') as f:
    f.write(php_code)
print('\nPHP block written to /tmp/php_block_test.php')
