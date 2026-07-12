<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_bans', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('blacklist');
            $table->string('type', 20);
            $table->string('name', 255)->nullable();
            $table->string('value', 255);
            $table->timestamps();

            $table->unique(['kind', 'type', 'value']);
            $table->index(['kind', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_bans');
    }
};
