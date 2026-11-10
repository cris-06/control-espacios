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
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->foreign(['cuatrimestre_id'], 'fk_asig_cuatrimestre')->references(['id'])->on('cuatrimestres')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['licenciatura_id'], 'fk_asig_licenciatura')->references(['id'])->on('licenciaturas')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropForeign('fk_asig_cuatrimestre');
            $table->dropForeign('fk_asig_licenciatura');
        });
    }
};
