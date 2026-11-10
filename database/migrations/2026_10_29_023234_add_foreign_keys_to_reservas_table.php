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
        Schema::table('reservas', function (Blueprint $table) {
            $table->foreign(['actividad_id'], 'fk_reserva_actividad')->references(['id'])->on('actividades')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['asignatura_id'], 'fk_reserva_asignatura')->references(['id'])->on('asignaturas')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['coordinador_id'], 'fk_reserva_coordinador')->references(['id'])->on('usuarios')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['usuario_creador_id'], 'fk_reserva_creador')->references(['id'])->on('usuarios')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['cuatrimestre_id'], 'fk_reserva_cuatrimestre')->references(['id'])->on('cuatrimestres')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['estado_id'], 'fk_reserva_estado')->references(['id'])->on('estados_reserva')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['licenciatura_id'], 'fk_reserva_licenciatura')->references(['id'])->on('licenciaturas')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropForeign('fk_reserva_actividad');
            $table->dropForeign('fk_reserva_asignatura');
            $table->dropForeign('fk_reserva_coordinador');
            $table->dropForeign('fk_reserva_creador');
            $table->dropForeign('fk_reserva_cuatrimestre');
            $table->dropForeign('fk_reserva_estado');
            $table->dropForeign('fk_reserva_licenciatura');
        });
    }
};
