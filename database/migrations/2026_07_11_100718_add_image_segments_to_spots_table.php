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
        if (Schema::hasColumn('spots', 'image_segments')) {
            return;
        }

        Schema::table('spots', function (Blueprint $table) {
            $table->jsonb('image_segments')->default('[]');
        });

        if (Schema::hasColumn('spots', 'image_segment')) {
            DB::table('spots')
                ->whereNotNull('image_segment')
                ->update(['image_segments' => DB::raw('jsonb_build_array(image_segment)')]);
        }
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('image_segments');
        });
    }
};
