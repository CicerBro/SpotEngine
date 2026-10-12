<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('spots', 'external_identifiers')) {
            Schema::table('spots', function (Blueprint $table): void {
                $table->jsonb('external_identifiers')->nullable();
            });
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS spots_external_identifiers_index ON spots USING gin (external_identifiers jsonb_path_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS spots_identifiers_pending_index ON spots (id) WHERE external_identifiers IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table): void {
            $table->dropColumn('external_identifiers');
        });
    }
};
