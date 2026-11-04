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
        Schema::create('reserva_detalle_requerimientos', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('reserva_detalle_id');
            $table->integer('requerimiento_id')->index('fk_rdr_requerimiento');
            $table->integer('cantidad')->default(1);

            $table->unique(['reserva_detalle_id', 'requerimiento_id'], 'uq_detalle_requerimiento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserva_detalle_requerimientos');
    }
};
