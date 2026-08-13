<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the sidebar "Dashboard" menu item to "Overview".
 *
 * The performance dashboard is now accessed via the dedicated "Dashboard"
 * button in the top-nav (next to the RC ERP brand badge), not the sidebar.
 * The sidebar item is renamed to "Overview" with a home icon to avoid
 * confusion — it still links to the same dashboard route for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')
            ->where('id', 1)
            ->update([
                'menu_label' => 'Overview',
                'icon'       => 'fa fa-home',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('id', 1)
            ->update([
                'menu_label' => 'Dashboard',
                'icon'       => 'fa fa-dashboard',
                'updated_at' => now(),
            ]);
    }
};
