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
        Schema::create('solicitudes_cambio_password', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('usuario_id')->index('fk_scp_usuario');
            $table->text('motivo');
            $table->enum('estado', ['pendiente', 'autorizada', 'rechazada'])->nullable()->default('pendiente');
            $table->text('respuesta_admin')->nullable();
            $table->timestamp('fecha_solicitud')->nullable()->useCurrent();
            $table->dateTime('fecha_respuesta')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cambio_password');
    }
};
