<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada notificación con su reserva: notificaciones.reserva_id INT NULL,
 * llave foránea a reservas.id con ON DELETE SET NULL. Solo si la columna no existe.
 * Las notificaciones existentes quedan con reserva_id = NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notificaciones', 'reserva_id')) {
            return;
        }

        Schema::table('notificaciones', function (Blueprint $table) {
            $table->integer('reserva_id')->nullable()->after('usuario_id');
            $table->foreign('reserva_id', 'fk_notificacion_reserva')
                ->references('id')->on('reservas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('notificaciones', 'reserva_id')) {
            return;
        }

        Schema::table('notificaciones', function (Blueprint $table) {
            $table->dropForeign('fk_notificacion_reserva');
            $table->dropColumn('reserva_id');
        });
    }
};
