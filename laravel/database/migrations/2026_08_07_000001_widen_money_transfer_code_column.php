<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen money_transfers.transfer_code from varchar(30) to varchar(50).
 *
 * The old code generation produced codes like "MT-20260731-money_transfer-20260731-00002"
 * (40 chars) which exceeded the varchar(30) column limit. The fixed code generation
 * now produces "MT-20260731-00001" (17 chars), but we widen the column to 50 for
 * safety margin and to accommodate any future format changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE money_transfers ALTER COLUMN transfer_code TYPE varchar(50)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE money_transfers ALTER COLUMN transfer_code TYPE varchar(30)");
    }
};
