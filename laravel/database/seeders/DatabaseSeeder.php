<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Application database seeder.
 *
 * Phase 4 / F-18d: seeds the default notification rules. Run via
 * `php artisan db:seed` (fresh install) or `php artisan db:seed
 * --class=NotificationRuleSeeder` (re-run defaults without touching
 * anything else). The admin "Reset to defaults" button on the
 * notification rules page calls NotificationRuleSeeder::run() directly
 * after truncating existing rules.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            NotificationRuleSeeder::class,
        ]);
    }
}
