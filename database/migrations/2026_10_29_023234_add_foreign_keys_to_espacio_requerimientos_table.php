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
        Schema::table('espacio_requerimientos', function (Blueprint $table) {
            $table->foreign(['espacio_id'], 'fk_er_espacio')->references(['id'])->on('espacios')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['requerimiento_id'], 'fk_er_requerimiento')->references(['id'])->on('requerimientos')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('espacio_requerimientos', function (Blueprint $table) {
            $table->dropForeign('fk_er_espacio');
            $table->dropForeign('fk_er_requerimiento');
        });
    }
};
