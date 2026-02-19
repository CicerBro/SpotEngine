<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('usenet_states', function (Blueprint $table) {
            $table->unsignedBigInteger('last_backfilled_article_id')->default(0)->after('first_article_id');
        });
    }

    public function down(): void
    {
        Schema::table('usenet_states', function (Blueprint $table) {
            $table->dropColumn('last_backfilled_article_id');
        });
    }
};
