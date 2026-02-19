<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->constrained()->cascadeOnDelete();
            $table->timestamp('downloaded_at')->useCurrent();

            $table->unique(['user_id', 'spot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_downloads');
    }
};
