<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usenet_states', function (Blueprint $table) {
            $table->string('newsgroup', 255)->primary();
            $table->unsignedBigInteger('last_article_id')->default(0);
            $table->unsignedBigInteger('first_article_id')->default(0);
            $table->timestamp('last_retrieval_at')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usenet_states');
    }
};
