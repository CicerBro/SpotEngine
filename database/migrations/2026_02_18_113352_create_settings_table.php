<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Settings table removed; registration_open is config-only (spotengine.registration_open).
    }

    public function down(): void
    {
        //
    }
};
