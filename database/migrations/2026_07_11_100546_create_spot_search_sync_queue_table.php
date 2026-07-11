<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_search_sync_queue', function (Blueprint $table) {
            $table->unsignedBigInteger('spot_id')->primary();
            $table->uuid('token');
            $table->timestamp('updated_at', precision: 6)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_search_sync_queue');
    }
};
