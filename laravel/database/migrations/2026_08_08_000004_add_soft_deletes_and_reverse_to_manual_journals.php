<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing columns to manual_journals that the Model references.
 *
 * The ManualJournal model uses SoftDeletes (needs deleted_at) and
 * references reversed_by, reversed_at, reverse_reason for reversal
 * tracking — but the original table schema lacks all of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_journals', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('reversed_by')->nullable()->after('created_by');
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reverse_reason', 500)->nullable()->after('reversed_at');
        });

        // Add index for reversal lookups
        DB::statement('CREATE INDEX idx_mj_reversed_by ON manual_journals(reversed_by) WHERE reversed_by IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('manual_journals', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['reversed_by', 'reversed_at', 'reverse_reason']);
        });
    }
};
