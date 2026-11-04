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
        Schema::table('solicitudes_cambio_password', function (Blueprint $table) {
            $table->foreign(['usuario_id'], 'fk_scp_usuario')->references(['id'])->on('usuarios')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitudes_cambio_password', function (Blueprint $table) {
            $table->dropForeign('fk_scp_usuario');
        });
    }
};
