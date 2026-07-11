<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropIndex('spots_spot_posted_at_index');
        });

        DB::statement('DROP INDEX IF EXISTS idx_spots_posted_desc');
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->index('spot_posted_at');
        });

        DB::statement('CREATE INDEX idx_spots_posted_desc ON spots(spot_posted_at DESC)');
    }
};
