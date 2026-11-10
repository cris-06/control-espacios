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
        Schema::table('usuario_licenciaturas', function (Blueprint $table) {
            $table->foreign(['licenciatura_id'], 'fk_ul_licenciatura')->references(['id'])->on('licenciaturas')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['usuario_id'], 'fk_ul_usuario')->references(['id'])->on('usuarios')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuario_licenciaturas', function (Blueprint $table) {
            $table->dropForeign('fk_ul_licenciatura');
            $table->dropForeign('fk_ul_usuario');
        });
    }
};
