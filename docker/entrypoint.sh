#!/bin/bash
# =============================================================================
# RC_ERP_v2 — Docker Entrypoint for PHP-FPM Container
# =============================================================================
# Runs on container startup:
#   1. Ensures storage directories are writable
#   2. Installs Composer dependencies (if vendor/ is empty)
#   3. Waits for PostgreSQL to be ready
#   4. Loads SQL schema (if tables don't exist yet)
#   5. Runs database migrations
#   6. Creates admin user (if not exists)
#   7. Clears caches
#   8. Starts PHP-FPM
#
# NOTE: This script does NOT depend on the MySQL Archive container.
#       The MySQL archive is optional (started via --profile archive).
#       The Laravel ArchiveService handles MySQL connection failures gracefully.
# =============================================================================

set -e

echo "╔══════════════════════════════════════════════════════════╗"
echo "║          RC_ERP_v2 — Docker Container Starting          ║"
echo "╚══════════════════════════════════════════════════════════╝"

# ---------------------------------------------------------------------------
# Step 1: Fix storage permissions
# ---------------------------------------------------------------------------
echo "▶ Step 1: Fixing storage permissions..."
mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
echo "  ✓ Storage permissions set"

# ---------------------------------------------------------------------------
# Step 2: Install Composer dependencies
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
    echo "▶ Step 2: Installing Composer dependencies..."
    composer install --no-interaction --no-dev --optimize-autoloader 2>/dev/null || \
    composer install --no-interaction --optimize-autoloader
    echo "  ✓ Composer dependencies installed"
else
    echo "▶ Step 2: Composer dependencies already present — skipping"
fi

# ---------------------------------------------------------------------------
# Step 3: Wait for PostgreSQL to be ready
# ---------------------------------------------------------------------------
echo "▶ Step 3: Waiting for PostgreSQL..."
MAX_RETRIES=30
RETRY_COUNT=0
until php -r "new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
    RETRY_COUNT=$((RETRY_COUNT + 1))
    if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
        echo "  ✗ PostgreSQL not ready after $MAX_RETRIES attempts — continuing anyway"
        break
    fi
    echo "  ⏳ PostgreSQL not ready — retrying in 2s... ($RETRY_COUNT/$MAX_RETRIES)"
    sleep 2
done
if [ $RETRY_COUNT -lt $MAX_RETRIES ]; then
    echo "  ✓ PostgreSQL is ready"
fi

# ---------------------------------------------------------------------------
# Step 4: Run database setup (schema + migrations)
# ---------------------------------------------------------------------------
echo "▶ Step 4: Setting up database..."

# Check if schema already exists (tables present)
TABLE_COUNT=$(php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    \$stmt = \$pdo->query(\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'\");
    echo \$stmt->fetchColumn();
} catch (\Exception \$e) {
    echo '0';
}
" 2>/dev/null)

if [ "$TABLE_COUNT" -lt 10 ] 2>/dev/null; then
    echo "  Loading SQL schema files..."
    for sql_file in database/sql/01_*.sql database/sql/02_*.sql database/sql/03_*.sql database/sql/04_*.sql database/sql/05_*.sql database/sql/06_*.sql database/sql/07_*.sql; do
        if [ -f "$sql_file" ]; then
            echo "    → Loading $(basename $sql_file)..."
            PGPASSWORD="${DB_PASSWORD}" psql -h rcerp_postgres -U "${DB_USERNAME}" -d "${DB_DATABASE}" -f "$sql_file" 2>&1 | tail -1 || true
        fi
    done
    echo "  ✓ Schema loaded"
else
    echo "  ✓ Schema already loaded ($TABLE_COUNT tables found) — skipping"
fi

# Run migrations
echo "  Running Laravel migrations..."
php artisan migrate --force 2>&1 | tail -5 || echo "  ⚠ Migration warning (may already be migrated)"
echo "  ✓ Migrations complete"

# Create migrations table if it doesn't exist (for tracking)
php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    \$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id SERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL DEFAULT 1)');
} catch (\Exception \$e) {}
" 2>/dev/null

# Fix the migrations IDENTITY sequence if out of sync
php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    \$stmt = \$pdo->query('SELECT MAX(id) FROM migrations');
    \$maxId = \$stmt->fetchColumn();
    if (\$maxId) {
        \$pdo->exec(\"SELECT setval('migrations_id_seq', \$maxId)\");
    }
} catch (\Exception \$e) {}
" 2>/dev/null

# ---------------------------------------------------------------------------
# Step 5: Create admin user (if not exists)
# ---------------------------------------------------------------------------
echo "▶ Step 5: Creating admin user..."
php artisan tinker --execute="
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Create Head Office branch
\$branch = Branch::firstOrCreate(
    ['branch_code' => 'HO'],
    ['branch_name' => 'Head Office', 'address' => '123 Main Street, Dhaka', 'phone' => '02-1234567', 'email' => 'ho@rcerp.com', 'is_active' => true]
);

// Create admin employee
\$employee = Employee::firstOrCreate(
    ['employee_code' => 'EMP-0001'],
    ['name' => 'System Administrator', 'role' => 'admin', 'branch_id' => \$branch->id, 'phone' => '01711111111', 'email' => 'admin@rcerp.com', 'salary' => 100000, 'joining_date' => '2024-01-01', 'is_active' => true]
);

// Create admin user
\$user = User::firstOrCreate(
    ['username' => 'admin'],
    ['employee_id' => \$employee->id, 'password_hash' => Hash::make('password123'), 'is_active' => true, 'credential_version' => 1]
);

echo 'Admin user: admin / password123';
" 2>/dev/null && echo "  ✓ Admin user ready (admin / password123)" || echo "  ⚠ Admin user creation skipped"

# ---------------------------------------------------------------------------
# Step 6: Clear caches + optimize
# ---------------------------------------------------------------------------
echo "▶ Step 6: Clearing caches..."
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
echo "  ✓ Caches cleared"

# ---------------------------------------------------------------------------
# Done — start PHP-FPM
# ---------------------------------------------------------------------------
echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║              Docker Setup Complete!                      ║"
echo "╠══════════════════════════════════════════════════════════╣"
echo "║  Application:  http://localhost:8080                     ║"
echo "║  Login:        admin / password123                       ║"
echo "║  PostgreSQL:   localhost:5432                            ║"
echo "║  Redis:        localhost:6379                            ║"
echo "║  MySQL Archive: localhost:3307 (optional, --profile archive) ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Execute the main command (php-fpm)
exec "$@"
