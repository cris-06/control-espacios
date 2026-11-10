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
        Schema::table('reserva_detalle_requerimientos', function (Blueprint $table) {
            $table->foreign(['reserva_detalle_id'], 'fk_rdr_detalle')->references(['id'])->on('reserva_detalles')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['requerimiento_id'], 'fk_rdr_requerimiento')->references(['id'])->on('requerimientos')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reserva_detalle_requerimientos', function (Blueprint $table) {
            $table->dropForeign('fk_rdr_detalle');
            $table->dropForeign('fk_rdr_requerimiento');
        });
    }
};
