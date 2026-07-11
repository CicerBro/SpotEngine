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
        if (! Schema::hasColumn('spots', 'image_segment')) {
            return;
        }

        DB::table('spots')
            ->whereRaw("image_segments = '[]'::jsonb")
            ->whereNotNull('image_segment')
            ->update(['image_segments' => DB::raw('jsonb_build_array(image_segment)')]);

        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('image_segment');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('spots', 'image_segment')) {
            return;
        }

        Schema::table('spots', function (Blueprint $table) {
            $table->string('image_segment', 255)->nullable()->after('file_size');
        });

        DB::table('spots')
            ->whereRaw("image_segments != '[]'::jsonb")
            ->update(['image_segment' => DB::raw('image_segments->>0')]);
    }
};
