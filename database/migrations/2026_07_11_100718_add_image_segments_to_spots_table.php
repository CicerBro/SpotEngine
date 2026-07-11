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
            $table->jsonb('image_segments')->default('[]')->after('image_segment');
        });

        DB::table('spots')
            ->whereNotNull('image_segment')
            ->update(['image_segments' => DB::raw('jsonb_build_array(image_segment)')]);
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('image_segments');
        });
    }
};
