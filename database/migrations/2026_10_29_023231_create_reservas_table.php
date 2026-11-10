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
        Schema::create('reservas', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('usuario_creador_id')->index('fk_reserva_creador');
            $table->integer('coordinador_id')->nullable()->index('fk_reserva_coordinador');
            $table->integer('actividad_id')->index('fk_reserva_actividad');
            $table->integer('licenciatura_id')->index('fk_reserva_licenciatura');
            $table->enum('tipo_formato', ['normal', 'ext'])->nullable()->default('normal');
            $table->string('nombre_actividad')->nullable();
            $table->string('folio', 100)->nullable();
            $table->text('objetivo');
            $table->integer('cuatrimestre_id')->nullable()->index('fk_reserva_cuatrimestre');
            $table->integer('asignatura_id')->nullable()->index('fk_reserva_asignatura');
            $table->decimal('costo_alumno', 10)->nullable()->default(0);
            $table->enum('promocion_redes', ['si', 'no'])->nullable()->default('no');
            $table->integer('limite_min_usuarios')->nullable();
            $table->integer('limite_max_usuarios')->nullable();
            $table->date('fecha_entrega_formato')->nullable();
            $table->integer('estado_id')->default(1)->index('fk_reserva_estado');
            $table->text('observaciones_admin')->nullable();
            $table->timestamp('fecha_creacion')->nullable()->useCurrent();
            $table->timestamp('fecha_actualizacion')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservas');
    }
};
