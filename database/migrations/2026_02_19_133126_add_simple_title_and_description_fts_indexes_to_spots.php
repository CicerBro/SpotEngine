<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // GIN index using the 'simple' dictionary (lowercase only, no stemming).
        // Required for prefix matching: to_tsquery('simple', 'candy:*') correctly
        // matches the lexeme 'candyman', whereas the english dict would stem 'candy'
        // to 'candi' which does not match 'candyman'.
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_spots_fts_title_simple
            ON spots USING gin (to_tsvector('simple', title))
        ");

        // Separate GIN index on description only, using the english dictionary.
        // The existing combined index (title + description) cannot be used for
        // description-only queries without also matching on title.
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_spots_fts_description
            ON spots USING gin (to_tsvector('english', COALESCE(description, '')))
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_spots_fts_title_simple');
        DB::statement('DROP INDEX IF EXISTS idx_spots_fts_description');
    }
};
