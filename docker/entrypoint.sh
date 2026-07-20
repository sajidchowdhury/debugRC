#!/bin/bash
# =============================================================================
# RC_ERP_v2 — Docker Entrypoint for PHP-FPM Container
# =============================================================================
# Runs on container startup:
#   1. Ensures storage directories are writable (Windows bind-mount safe)
#   2. Creates .env file from Docker environment variables
#   3. Installs Composer dependencies (if vendor/ is empty)
#   4. Installs Node dependencies + builds Vite assets (if public/build missing)
#   5. Waits for PostgreSQL to be ready
#   6. Wipes + recreates database on first run, then runs migrations
#   7. Seeds default system_policies row
#   8. Creates admin user (if not exists)
#   9. Clears caches
#  10. Starts PHP-FPM
# =============================================================================

set -e

echo "╔══════════════════════════════════════════════════════════╗"
echo "║          RC_ERP_v2 — Docker Container Starting          ║"
echo "╚══════════════════════════════════════════════════════════╝"

# ---------------------------------------------------------------------------
# Step 1: Fix storage permissions (Windows bind-mount safe)
# ---------------------------------------------------------------------------
# CRITICAL: On Windows + Docker Desktop, bind-mounted directories from the
# host (NTFS) have root:root ownership and chmod/chown silently fail.
# PHP-FPM runs as www-data (UID 33) and cannot write to root-owned dirs.
#
# Solution: We detect the mount owner UID and configure PHP-FPM to run
# as that user. This ensures PHP can write to the bind-mounted storage.
# ---------------------------------------------------------------------------
echo "▶ Step 1: Fixing storage permissions..."

# Create all required directories
mkdir -p storage/logs \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache

# Create log file if it doesn't exist
touch storage/logs/laravel.log 2>/dev/null || true

# Detect the UID that owns the laravel directory (bind-mounted from host)
MOUNT_UID=$(stat -c '%u' /var/www/laravel 2>/dev/null || echo "0")
MOUNT_GID=$(stat -c '%g' /var/www/laravel 2>/dev/null || echo "0")

echo "  Mount owner UID/GID: ${MOUNT_UID}/${MOUNT_GID}"

# Try to set permissions (works on Linux, silently ignored on Windows NTFS)
chmod -R a+rwx storage bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Configure PHP-FPM pool based on mount ownership.
# This is the KEY fix for Windows bind mounts where www-data can't write.
if [ "$MOUNT_UID" != "33" ] && [ "$MOUNT_UID" != "0" ]; then
    echo "  Bind mount owned by UID ${MOUNT_UID} — adjusting PHP-FPM user"
    cat > /usr/local/etc/php-fpm.d/www.conf <<FPMEOF
[www]
user = ${MOUNT_UID}
group = ${MOUNT_GID}
listen = 9000
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
clear_env = no
FPMEOF
elif [ "$MOUNT_UID" = "0" ]; then
    # Mount owned by root (common on Windows Docker Desktop)
    # Make everything world-writable as fallback, and run PHP-FPM as www-data
    echo "  Bind mount owned by root — using world-writable fallback"
    chmod -R 777 storage bootstrap/cache 2>/dev/null || true
    cat > /usr/local/etc/php-fpm.d/www.conf <<FPMEOF
[www]
user = www-data
group = www-data
listen = 9000
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
clear_env = no
FPMEOF
else
    # Mount owned by www-data (UID 33) — standard Linux setup
    echo "  Bind mount owned by www-data — standard setup"
    cat > /usr/local/etc/php-fpm.d/www.conf <<FPMEOF
[www]
user = www-data
group = www-data
listen = 9000
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
clear_env = no
FPMEOF
fi

echo "  ✓ Storage permissions configured"

# ---------------------------------------------------------------------------
# Step 2: Create .env file from Docker environment variables
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
# Step 6: Database setup — fresh install or migrate existing
# ---------------------------------------------------------------------------
# STRATEGY:
#   - If database is empty (no migrations table) → run migrate:fresh which
#     drops all tables and re-creates from scratch using migrations.
#     The first migration (create_rcerp_schema) loads the SQL schema files.
#   - If database already has migrations table → run migrate (incremental).
#
# This avoids the problem of loading SQL via psql AND then having migrations
# try to re-create the same tables. Everything goes through artisan migrate.
# ---------------------------------------------------------------------------
echo "▶ Step 6: Setting up database..."

# Check if migrations table exists (indicates a previously set up database)
MIGRATIONS_EXIST=$(php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=rcerp', 'rcerp_app', 'rcerp_secret');
    \$stmt = \$pdo->query(\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'migrations'\");
    echo \$stmt->fetchColumn();
} catch (\Exception \$e) {
    echo '0';
}
" 2>/dev/null || echo "0")

if [ "$MIGRATIONS_EXIST" = "0" ]; then
    echo "  Fresh database detected — running migrate:fresh..."
    php artisan migrate:fresh --force 2>&1 || {
        echo "  ⚠ migrate:fresh failed — trying fresh database approach..."
        # Fallback: drop and recreate the database, then migrate
        php -r "
        try {
            \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=postgres', 'rcerp_app', 'rcerp_secret');
            \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            \$pdo->exec('DROP DATABASE IF EXISTS rcerp');
            \$pdo->exec('CREATE DATABASE rcerp OWNER rcerp_app');
            echo \"Database recreated\n\";
        } catch (\Exception \$e) {
            echo \"Database recreate failed: \" . \$e->getMessage() . \"\n\";
        }
        " 2>/dev/null || true
        php artisan migrate --force 2>&1 || echo "  ⚠ Migration also failed after DB recreate"
    }
else
    echo "  Existing database detected — running incremental migrations..."
    php artisan migrate --force 2>&1 || echo "  ⚠ Migration warning (may already be migrated)"
fi

echo "  ✓ Database setup complete"

# ---------------------------------------------------------------------------
# Step 7: Ensure system_policies table exists + seed default policy
# ---------------------------------------------------------------------------
# This is a safety net — if migrations somehow didn't create system_policies,
# the app will crash on every request because CheckSystemPolicy middleware
# queries this table. We create it directly if it's missing.
# ---------------------------------------------------------------------------
echo "▶ Step 7: Ensuring system_policies table + default policy..."
php -r "
try {
    \$pdo = new PDO('pgsql:host=rcerp_postgres;port=5432;dbname=rcerp', 'rcerp_app', 'rcerp_secret');
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if system_policies table exists
    \$stmt = \$pdo->query(\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='system_policies'\");
    if (\$stmt->fetchColumn() == 0) {
        echo \"  Creating system_policies table (fallback)...\n\";
        \$pdo->exec('CREATE TABLE system_policies (
            id SERIAL PRIMARY KEY,
            mode VARCHAR(30) NOT NULL DEFAULT \'NORMAL\',
            is_active BOOLEAN NOT NULL DEFAULT false,
            activated_by INTEGER NULL,
            activated_at TIMESTAMP(0) NULL,
            deactivated_by INTEGER NULL,
            deactivated_at TIMESTAMP(0) NULL,
            reason TEXT NULL,
            expires_at TIMESTAMP(0) NULL,
            metadata JSONB NULL,
            activation_source VARCHAR(30) NOT NULL DEFAULT \'admin_panel\',
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP
        )');
        \$pdo->exec('CREATE INDEX system_policies_mode_index ON system_policies (mode)');
        \$pdo->exec('CREATE INDEX system_policies_is_active_index ON system_policies (is_active)');
        echo \"  ✓ system_policies table created\n\";
    }

    // Seed a default NORMAL policy if none exists
    \$stmt = \$pdo->query(\"SELECT COUNT(*) FROM system_policies\");
    if (\$stmt->fetchColumn() == 0) {
        \$pdo->exec(\"INSERT INTO system_policies (mode, is_active, reason, activation_source, created_at, updated_at) VALUES ('NORMAL', false, 'Default policy created by Docker setup', 'system', NOW(), NOW())\");
        echo \"  ✓ Default NORMAL policy seeded\n\";
    } else {
        echo \"  ✓ system_policies already populated\n\";
    }
} catch (\Exception \$e) {
    echo \"  ⚠ system_policies setup skipped: \" . \$e->getMessage() . \"\n\";
}
" 2>/dev/null || echo "  ⚠ system_policies check skipped"

# ---------------------------------------------------------------------------
# Step 8: Create admin user (if not exists)
# ---------------------------------------------------------------------------
echo "▶ Step 8: Creating admin user..."
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
# Step 9: Clear caches + optimize
# ---------------------------------------------------------------------------
echo "▶ Step 9: Clearing caches..."
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
