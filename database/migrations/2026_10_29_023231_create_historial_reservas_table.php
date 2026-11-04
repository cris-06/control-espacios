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
        Schema::create('historial_reservas', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('reserva_id')->index('fk_historial_reserva');
            $table->integer('usuario_id')->index('fk_historial_usuario');
            $table->enum('accion', ['creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador', 'enviada_admin', 'autorizada', 'denegada', 'cancelada']);
            $table->text('comentario')->nullable();
            $table->timestamp('fecha')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historial_reservas');
    }
};
