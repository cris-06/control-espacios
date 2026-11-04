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
        Schema::table('reserva_detalles', function (Blueprint $table) {
            $table->foreign(['docente_responsable_id'], 'fk_detalle_docente')->references(['id'])->on('usuarios')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['espacio_id'], 'fk_detalle_espacio')->references(['id'])->on('espacios')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['reserva_id'], 'fk_detalle_reserva')->references(['id'])->on('reservas')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reserva_detalles', function (Blueprint $table) {
            $table->dropForeign('fk_detalle_docente');
            $table->dropForeign('fk_detalle_espacio');
            $table->dropForeign('fk_detalle_reserva');
        });
    }
};
