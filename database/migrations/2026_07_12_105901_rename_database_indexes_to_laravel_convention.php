<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align legacy hand-named indexes with Laravel's {table}_{descriptor}_index convention.
     */
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table): void {
            $table->renameIndex('idx_spots_fts', 'spots_fts_title_description_index');
            $table->renameIndex('idx_spots_fts_description', 'spots_fts_description_index');
            $table->renameIndex('idx_spots_fts_title_simple', 'spots_fts_title_simple_index');
            $table->renameIndex('idx_spots_subcats', 'spots_subcategories_index');
            $table->renameIndex('idx_spots_unenriched', 'spots_unenriched_index');
        });
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table): void {
            $table->renameIndex('spots_fts_title_description_index', 'idx_spots_fts');
            $table->renameIndex('spots_fts_description_index', 'idx_spots_fts_description');
            $table->renameIndex('spots_fts_title_simple_index', 'idx_spots_fts_title_simple');
            $table->renameIndex('spots_subcategories_index', 'idx_spots_subcats');
            $table->renameIndex('spots_unenriched_index', 'idx_spots_unenriched');
        });
    }
};
