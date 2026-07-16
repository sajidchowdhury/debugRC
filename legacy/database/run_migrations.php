<?php
/**
 * Apply SQL migrations in database/migrations/ (tracks in schema_migrations).
 * Usage: php database/run_migrations.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

$existingErp = (bool) $pdo->query("SHOW TABLES LIKE 'sales_invoices'")->fetch();

if ($existingErp && $applied === []) {
    foreach ($files as $path) {
        $name = basename($path);
        if (preg_match('/^0\d{2}_/', $name) && (int) substr($name, 0, 3) < 11) {
            $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
            $appliedSet[$name] = true;
            echo "Skipped baseline (already deployed): {$name}\n";
        }
    }
}

$ran = 0;
foreach ($files as $path) {
    $name = basename($path);
    if (isset($appliedSet[$name])) {
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        continue;
    }

    echo "Applying {$name}...\n";

    foreach (splitSqlStatements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '' || strpos(ltrim($statement), '--') === 0) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $code = (string) $e->getCode();
            $msg = $e->getMessage();
            $benign = str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate key name')
                || str_contains($msg, "Can't DROP")
                || str_contains($msg, 'check that column/key exists');
            if (!$benign) {
                throw $e;
            }
            echo "  (skipped benign: {$msg})\n";
        }
    }

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
    $stmt->execute([$name]);
    $ran++;
    echo "  OK\n";
}

echo $ran > 0 ? "Done. Applied {$ran} migration(s).\n" : "No pending migrations.\n";

/**
 * @return string[]
 */
function splitSqlStatements(string $sql): array
{
    $parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
    if (count($parts) === 1) {
        $parts = preg_split('/;\s*$/m', $sql) ?: [];
    }
    return $parts;
}