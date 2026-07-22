#!/usr/bin/env bash
# Diagnose the ParseError in purchase-returns/index.blade.php
# Run this on the host (Windows PowerShell) after the bug fixes are in.
#
# Usage (PowerShell):
#   docker compose exec rcerp_app bash /var/www/laravel/scripts/diagnose_returns_blade.sh
#
# Or run individual commands manually.

set -e

echo "=== 1. Verify route parameter binding for /admin/purchase-receives/create ==="
php artisan route:list --columns=method,uri,name | grep -E "purchase-(receives|returns|orders)" || true

echo ""
echo "=== 2. Clear all Laravel caches (in case stale compiled view is the culprit) ==="
php artisan view:clear
php artisan route:clear
php artisan config:clear
php artisan cache:clear

echo ""
echo "=== 3. Try to compile the returns index blade manually ==="
php -r "
require __DIR__.'/../vendor/autoload.php';
\$app = require __DIR__.'/../bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$blade = \$app->make('blade.compiler');
\$src = file_get_contents(base_path('resources/views/admin/purchase-returns/index.blade.php'));
\$compiled = \$blade->compileString(\$src);
\$tmp = sys_get_temp_dir().'/returns_compiled_'.uniqid().'.php';
file_put_contents(\$tmp, \$compiled);
echo \"Compiled file: \$tmp\n\";
echo \"--- Running php -l on compiled output ---\n\";
passthru('php -l '.escapeshellarg(\$tmp));
"

echo ""
echo "=== 4. If php -l reports an error above, open the compiled file and look at the line number ==="
echo "=== 5. If no error above, the issue is likely a runtime issue, not a parse error ==="
echo ""
echo "=== 6. Visit http://localhost:8080/admin/purchase-returns to trigger fresh compilation ==="
echo "=== 7. If still failing, check the latest compiled file directly: ==="
ls -t storage/framework/views/*.php | head -3
