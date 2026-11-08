<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `notificaciones` (la tabla propia del sistema, no la de Laravel) SOLO si no existe.
 * En la base real ya existe, así que no hace nada; sirve para instalaciones nuevas y tests.
 * La columna reserva_id la agrega 2026_11_08_000004_add_reserva_id_to_notificaciones_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notificaciones')) {
            return;
        }

        Schema::create('notificaciones', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('usuario_id');
            $table->string('titulo', 180);
            $table->text('mensaje');
            $table->string('tipo', 30)->nullable()->default('info');
            $table->boolean('leida')->nullable()->default(false);
            $table->timestamp('fecha')->nullable()->useCurrent();

            $table->foreign('usuario_id')->references('id')->on('usuarios');
        });
    }

    /**
     * No se elimina: en la base real contiene datos.
     */
    public function down(): void
    {
        //
    }
};
