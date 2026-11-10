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
        Schema::create('reserva_detalles', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('reserva_id')->index('fk_detalle_reserva');
            $table->integer('espacio_id')->index('fk_detalle_espacio');
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->integer('docente_responsable_id')->nullable()->index('fk_detalle_docente');
            $table->integer('cantidad_alumnos')->nullable();
            $table->string('lugar_especifico', 150)->nullable();
            $table->text('descripcion_actividad')->nullable();
            $table->text('material_requerido')->nullable();
            $table->text('observaciones')->nullable();
            $table->enum('estado_detalle', ['pendiente', 'autorizada', 'denegada', 'cancelada'])->nullable()->default('pendiente');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserva_detalles');
    }
};
