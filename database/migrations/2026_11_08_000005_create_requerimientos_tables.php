<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las tablas de requerimientos SOLO si no existen: requerimientos,
 * espacio_requerimientos y reserva_detalle_requerimientos.
 *
 * En la base real (MySQL) ya existen, así que no hace nada ni toca datos. Sirve
 * para instalaciones nuevas y para los tests (SQLite en memoria). Replica la
 * estructura de BD_Reservas_corregida.sql con el mismo criterio que
 * 0001_01_01_000005_create_reservas_tables (ids INT con signo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('requerimientos')) {
            Schema::create('requerimientos', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->string('nombre', 100)->unique('uq_requerimientos_nombre');
                $table->enum('estatus', ['activo', 'eliminado'])->default('activo');
                $table->timestamp('fecha_registro')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('espacio_requerimientos')) {
            Schema::create('espacio_requerimientos', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->integer('espacio_id');
                $table->integer('requerimiento_id');
                $table->unsignedInteger('cantidad_disponible')->default(1);

                // Nombres explícitos (los de BD_Reservas_corregida.sql): los generados
                // por Laravel superan el límite de 64 caracteres de MySQL.
                $table->unique(['espacio_id', 'requerimiento_id'], 'uq_espacio_requerimiento');
                $table->foreign('espacio_id', 'fk_er_espacio')->references('id')->on('espacios')->cascadeOnDelete();
                $table->foreign('requerimiento_id', 'fk_er_requerimiento')->references('id')->on('requerimientos')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('reserva_detalle_requerimientos')) {
            Schema::create('reserva_detalle_requerimientos', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->integer('reserva_detalle_id');
                $table->integer('requerimiento_id');
                $table->unsignedInteger('cantidad')->default(1);

                $table->unique(['reserva_detalle_id', 'requerimiento_id'], 'uq_detalle_requerimiento');
                $table->foreign('reserva_detalle_id', 'fk_rdr_detalle')->references('id')->on('reserva_detalles')->cascadeOnDelete();
                $table->foreign('requerimiento_id', 'fk_rdr_requerimiento')->references('id')->on('requerimientos');
            });
        }
    }

    /**
     * No se eliminan las tablas: en la base real contienen datos.
     */
    public function down(): void
    {
        //
    }
};
