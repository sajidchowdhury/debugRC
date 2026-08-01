<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empty all data from the database (preserve schema/tables).
 *
 * Usage:
 *   php artisan db:make-empty
 *
 * What it does:
 *   - Discovers ALL tables in the 'public' schema.
 *   - Excludes Laravel's `migrations` table (so migration history survives).
 *   - TRUNCATEs everything else with RESTART IDENTITY CASCADE in one statement.
 *
 * What it does NOT do:
 *   - Does NOT drop any tables, indexes, sequences, constraints, or views.
 *   - Does NOT touch the schema structure.
 *
 * This is the equivalent of MAKE_ALL_EMPTY the user asked for, implemented as
 * an Artisan command (so it can be run repeatedly — migrations only run once).
 *
 * Safety:
 *   - Asks for confirmation unless --force is passed.
 *   - Warns if no basic_data_snapshot.sql exists yet (would lose basic data
 *     permanently). The user should run `php artisan db:snapshot-basic` first.
 */
class DbMakeEmpty extends Command
{
    protected $signature = 'db:make-empty
                            {--force : Skip confirmation prompt}';

    protected $description = 'EMPTY ALL DATA from every table (keeps schema). Excludes the migrations table.';

    public function handle(): int
    {
        $this->info('=== DB MAKE-EMPTY ===');
        $this->newLine();

        // ---- Safety: warn if no snapshot exists ----
        $snapshotPath = database_path('sql/basic_data_snapshot.sql');
        if (!file_exists($snapshotPath)) {
            $this->warn('⚠ WARNING: No basic_data_snapshot.sql found.');
            $this->warn('  After emptying, you CANNOT restore basic data unless you have a snapshot.');
            $this->warn('  Consider running `php artisan db:snapshot-basic` FIRST.');
            $this->newLine();
        }

        // ---- Discover all tables in public schema ----
        $tables = $this->getAllTables();

        if (empty($tables)) {
            $this->warn('No tables found in public schema. Nothing to empty.');
            return self::SUCCESS;
        }

        // ---- Confirm ----
        $tableCount = count($tables);
        $this->info("Discovered {$tableCount} tables in public schema.");
        $this->info('The following data will be PERMANENTLY DELETED:');
        $preview = array_slice($tables, 0, 10);
        $this->line('  ' . implode(', ', $preview) . ($tableCount > 10 ? " ... (+".($tableCount-10)." more)" : ''));
        $this->newLine();
        $this->warn('⚠ The `migrations` table is PRESERVED so Laravel knows which migrations ran.');
        $this->newLine();

        if (!$this->option('force')) {
            if (!$this->confirm('Are you sure you want to TRUNCATE ALL TABLES?', false)) {
                $this->info('Aborted. No changes made.');
                return self::SUCCESS;
            }
        }

        // ---- Execute TRUNCATE ----
        $this->info('Emptying all tables...');
        $quotedTables = array_map(fn($t) => '"'.$t.'"', $tables);
        $truncateSql = 'TRUNCATE ' . implode(', ', $quotedTables) . ' RESTART IDENTITY CASCADE';

        try {
            DB::statement($truncateSql);
        } catch (\Throwable $e) {
            $this->error('TRUNCATE failed: ' . $e->getMessage());
            $this->info('Falling back to per-table TRUNCATE...');
            $failed = [];
            foreach ($tables as $t) {
                try {
                    DB::statement('TRUNCATE TABLE "'.$t.'" RESTART IDENTITY CASCADE');
                } catch (\Throwable $e2) {
                    $failed[] = ['table' => $t, 'error' => $e2->getMessage()];
                }
            }
            if (!empty($failed)) {
                $this->error('Failed to truncate '.count($failed).' table(s):');
                foreach ($failed as $f) {
                    $this->line("  - {$f['table']}: {$f['error']}");
                }
                return self::FAILURE;
            }
        }

        // ---- Verify ----
        $this->newLine();
        $this->info('✓ All tables emptied. Verifying...');
        $nonEmpty = [];
        foreach ($tables as $t) {
            try {
                $count = DB::table($t)->count();
                if ($count > 0) {
                    $nonEmpty[] = "$t ($count rows)";
                }
            } catch (\Throwable $e) {
                // Skip tables that can't be counted (views, etc.)
            }
        }

        if (empty($nonEmpty)) {
            $this->info('✓ Verified: all tables are empty.');
        } else {
            $this->warn('Some tables still have rows:');
            foreach ($nonEmpty as $n) {
                $this->line("  - $n");
            }
        }

        $this->newLine();
        $this->info('Done. To restore basic data, run: php artisan db:restore-basic');
        return self::SUCCESS;
    }

    /**
     * Get all base tables in the public schema, excluding Laravel's migrations table.
     *
     * @return string[]
     */
    private function getAllTables(): array
    {
        $rows = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->pluck('table_name')
            ->all();

        // Exclude Laravel's migration tracking + this app's snapshot audit (if any).
        $excluded = ['migrations'];

        return array_values(array_filter($rows, fn($t) => !in_array($t, $excluded, true)));
    }
}
