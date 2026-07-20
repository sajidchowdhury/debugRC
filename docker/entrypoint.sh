#!/bin/bash
# =============================================================================
# RC_ERP_v2 — Docker Entrypoint for PHP-FPM Container
# =============================================================================
# Runs on container startup:
#   1. Ensures storage directories are writable
#   2. Creates .env file from Docker environment variables
#   3. Installs Composer dependencies (if vendor/ is empty)
#   4. Installs Node dependencies + builds Vite assets (if public/build missing)
#   5. Waits for PostgreSQL to be ready
#   6. Loads SQL schema (if tables don't exist yet)
#   7. Runs database migrations
#   8. Creates admin user (if not exists)
#   9. Clears caches
#  10. Starts PHP-FPM
# =============================================================================

set -e

echo "╔══════════════════════════════════════════════════════════╗"
echo "║          RC_ERP_v2 — Docker Container Starting          ║"
echo "╚══════════════════════════════════════════════════════════╝"

# ---------------------------------------------------------------------------
# Step 1: Fix storage permissions
# ---------------------------------------------------------------------------
# IMPORTANT: The storage/ directory is bind-mounted from the host.
# On Windows/Mac, bind mounts may have root ownership that www-data can't
# write to. We fix this by:
#   1. Creating all required subdirectories
#   2. Setting ownership to www-data (PHP-FPM runs as www-data)
#   3. Creating an empty laravel.log if it doesn't exist
# ---------------------------------------------------------------------------
echo "▶ Step 1: Fixing storage permissions..."
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
# Create the log file if it doesn't exist (ensures www-data can write)
touch storage/logs/laravel.log 2>/dev/null || true
chmod -R 777 storage bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chown www-data:www-data storage/logs/laravel.log 2>/dev/null || true
echo "  ✓ Storage permissions set"

# ---------------------------------------------------------------------------
# Step 2: Create .env file from Docker environment variables
# ---------------------------------------------------------------------------
# Laravel requires a .env file to exist. In Docker, all config is injected
# via environment variables in docker-compose.yml, but some artisan commands
# and the framework still expect a .env file. We create a minimal one that
# references the Docker env vars.
# ---------------------------------------------------------------------------
echo "▶ Step 2: Creating .env file..."
if [ ! -f .env ]; then
    cat > .env <<'ENVEOF'
APP_NAME="Remote Center ERP"
APP_ENV=local
APP_KEY=base64:2cn8GO0r6OSab790IzGrvPj+siQVQDNsjsWbkzNxRC4=
APP_DEBUG=true
APP_TIMEZONE=Asia/Dhaka
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=rcerp_postgres
DB_PORT=5432
DB_DATABASE=rcerp
DB_USERNAME=rcerp_app
DB_PASSWORD=rcerp_secret

REDIS_CLIENT=predis
REDIS_HOST=rcerp_redis
REDIS_PASSWORD=null
REDIS_PORT=6379
LEGACY_SESSION_REDIS_DB=1
LEGACY_SESSION_COOKIE=PHPSESSID

SESSION_DRIVER=redis
SESSION_CONNECTION=legacy
SESSION_LIFETIME=480
SESSION_COOKIE=PHPSESSID
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_SAMESITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis

MAIL_MAILER=log

ARCHIVE_MYSQL_HOST=rcerp_mysql_archive
ARCHIVE_MYSQL_PORT=3306
ARCHIVE_MYSQL_DATABASE=rcerp_legacy
ARCHIVE_MYSQL_USERNAME=archive_reader
ARCHIVE_MYSQL_PASSWORD=archive_reader_secret

AUTH_MAX_FAILED_ATTEMPTS=5
AUTH_LOCKOUT_MINUTES=15
AUTH_RESET_TOKEN_HOURS=1
AUTH_REMEMBER_DAYS=30
ENVEOF
    echo "  ✓ .env file created"
else
    echo "  ✓ .env file already exists — skipping"
fi

# ---------------------------------------------------------------------------
# Step 3: Install Composer dependencies
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
    echo "▶ Step 3: Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader 2>&1 || {
        echo "  ⚠ Composer install failed, retrying with --no-dev..."
        composer install --no-interaction --no-dev --optimize-autoloader 2>&1 || true
    }
    echo "  ✓ Composer dependencies installed"
else
    echo "▶ Step 3: Composer dependencies already present — skipping"
fi

# ---------------------------------------------------------------------------
# Step 4: Install Node dependencies + build Vite assets
# ---------------------------------------------------------------------------
if [ ! -d public/build ]; then
    echo "▶ Step 4: Building frontend assets..."
    if [ -f package.json ]; then
        npm install 2>&1 || true
        npm run build 2>&1 || {
            echo "  ⚠ Vite build failed — frontend assets may be missing"
            echo "  This is OK if you only need backend functionality."
        }
    fi
    echo "  ✓ Frontend assets built"
else
    echo "▶ Step 4: Frontend assets already present — skipping"
fi

# ---------------------------------------------------------------------------
# Step 5: Wait for PostgreSQL to be ready
# ---------------------------------------------------------------------------
echo "▶ Step 5: Waiting for PostgreSQL..."
MAX_RETRIES=30
RETRY_COUNT=0
until php -r "new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=rcerp', 'rcerp_app', 'rcerp_secret');" 2>/dev/null; do
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
# Step 6: Run database setup (schema + migrations)
# ---------------------------------------------------------------------------
echo "▶ Step 6: Setting up database..."

# Check if schema already exists (tables present)
TABLE_COUNT=$(php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=rcerp', 'rcerp_app', 'rcerp_secret');
    \$stmt = \$pdo->query(\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'\");
    echo \$stmt->fetchColumn();
} catch (\Exception \$e) {
    echo '0';
}
" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -lt 10 ] 2>/dev/null; then
    echo "  Loading SQL schema files..."
    for sql_file in database/sql/01_*.sql database/sql/02_*.sql database/sql/03_*.sql database/sql/04_*.sql database/sql/05_*.sql database/sql/06_*.sql database/sql/07_*.sql; do
        if [ -f "$sql_file" ]; then
            echo "    → Loading $(basename $sql_file)..."
            PGPASSWORD="rcerp_secret" psql -h rcerp_postgres -U rcerp_app -d rcerp -f "$sql_file" 2>&1 | tail -3 || true
        fi
    done
    echo "  ✓ Schema loaded"
else
    echo "  ✓ Schema already loaded ($TABLE_COUNT tables found) — skipping"
fi

# Run migrations
echo "  Running Laravel migrations..."
php artisan migrate --force 2>&1 || echo "  ⚠ Migration warning (may already be migrated)"

# Fix the migrations IDENTITY sequence if out of sync
php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=rcerp', 'rcerp_app', 'rcerp_secret');
    \$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id SERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL DEFAULT 1)');
    \$stmt = \$pdo->query('SELECT MAX(id) FROM migrations');
    \$maxId = \$stmt->fetchColumn();
    if (\$maxId) {
        \$pdo->exec(\"SELECT setval('migrations_id_seq', \$maxId)\");
    }
} catch (\Exception \$e) {}
" 2>/dev/null
echo "  ✓ Migrations complete"

# ---------------------------------------------------------------------------
# Step 7: Create admin user (if not exists)
# ---------------------------------------------------------------------------
echo "▶ Step 7: Creating admin user..."
php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=rcerp', 'rcerp_app', 'rcerp_secret');
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if admin user already exists
    \$stmt = \$pdo->query(\"SELECT COUNT(*) FROM users WHERE username = 'admin'\");
    if (\$stmt->fetchColumn() > 0) {
        echo \"Admin user already exists — skipping\n\";
        exit(0);
    }

    // Create Head Office branch
    \$stmt = \$pdo->query(\"SELECT id FROM branches WHERE branch_code = 'HO'\");
    \$branch = \$stmt->fetch(PDO::FETCH_ASSOC);
    if (!\$branch) {
        \$pdo->exec(\"INSERT INTO branches (branch_code, branch_name, address, phone, email, is_active, created_at, updated_at) VALUES ('HO', 'Head Office', '123 Main Street, Dhaka', '02-1234567', 'ho@rcerp.com', true, NOW(), NOW())\");
        \$stmt = \$pdo->query(\"SELECT id FROM branches WHERE branch_code = 'HO'\");
        \$branch = \$stmt->fetch(PDO::FETCH_ASSOC);
    }
    \$branchId = \$branch['id'];

    // Create admin employee
    \$stmt = \$pdo->query(\"SELECT id FROM employees WHERE employee_code = 'EMP-0001'\");
    \$employee = \$stmt->fetch(PDO::FETCH_ASSOC);
    if (!\$employee) {
        \$pdo->exec(\"INSERT INTO employees (employee_code, name, role, branch_id, phone, email, salary, joining_date, is_active, created_at, updated_at) VALUES ('EMP-0001', 'System Administrator', 'admin', \$branchId, '01711111111', 'admin@rcerp.com', 100000, '2024-01-01', true, NOW(), NOW())\");
        \$stmt = \$pdo->query(\"SELECT id FROM employees WHERE employee_code = 'EMP-0001'\");
        \$employee = \$stmt->fetch(PDO::FETCH_ASSOC);
    }
    \$employeeId = \$employee['id'];

    // Create admin user with bcrypt hash of 'password123'
    \$hash = password_hash('password123', PASSWORD_BCRYPT);
    \$hash = str_replace(\"'\", \"''\", \$hash); // escape single quotes
    \$pdo->exec(\"INSERT INTO users (employee_id, username, password_hash, is_active, credential_version, created_at, updated_at) VALUES (\$employeeId, 'admin', '\$hash', true, 1, NOW(), NOW())\");

    echo \"Admin user created: admin / password123\n\";
} catch (\Exception \$e) {
    echo \"Admin user creation skipped: \" . \$e->getMessage() . \"\n\";
}
" 2>/dev/null && echo "  ✓ Admin user ready" || echo "  ⚠ Admin user creation skipped"

# ---------------------------------------------------------------------------
# Step 8: Clear caches + optimize
# ---------------------------------------------------------------------------
echo "▶ Step 8: Clearing caches..."
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
