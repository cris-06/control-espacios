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
        Schema::create('espacio_requerimientos', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('espacio_id');
            $table->integer('requerimiento_id')->index('fk_er_requerimiento');
            $table->integer('cantidad_disponible')->default(1);

            $table->unique(['espacio_id', 'requerimiento_id'], 'uq_espacio_requerimiento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('espacio_requerimientos');
    }
};
