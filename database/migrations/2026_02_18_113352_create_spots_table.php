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
        Schema::create('spots', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 255)->unique();
            $table->string('poster', 255)->nullable()->index();
            $table->string('poster_key_id', 64)->nullable();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->string('tag', 255)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('category_code', 10)->index();
            $table->jsonb('subcategories')->default('[]');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('image_segment', 255)->nullable();
            $table->jsonb('nzb_segments')->default('[]');
            $table->timestamp('spot_posted_at')->index();
            $table->string('xml_signature', 255)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });

        // GIN index for JSONB subcategories (fast containment queries)
        DB::statement('CREATE INDEX idx_spots_subcats ON spots USING GIN(subcategories)');

        // GIN index for full-text search across title + description
        DB::statement("CREATE INDEX idx_spots_fts ON spots USING GIN(to_tsvector('english', title || ' ' || COALESCE(description, '')))");

        // Descending index on spot_posted_at for listing performance
        DB::statement('CREATE INDEX idx_spots_posted_desc ON spots(spot_posted_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('spots');
    }
};
