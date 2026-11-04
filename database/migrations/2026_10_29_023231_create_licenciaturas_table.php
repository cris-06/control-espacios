<?php

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
        Schema::create('licenciaturas', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('nombre', 100)->unique('uq_licenciatura_nombre');
            $table->enum('estatus', ['activa', 'inactiva'])->nullable()->default('activa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenciaturas');
    }
};
