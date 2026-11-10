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
        Schema::create('asignaturas', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('nombre', 150);
            $table->integer('licenciatura_id')->index('fk_asig_licenciatura');
            $table->integer('cuatrimestre_id')->index('fk_asig_cuatrimestre');
            $table->enum('estatus', ['activa', 'inactiva'])->nullable()->default('activa');

            $table->unique(['nombre', 'licenciatura_id', 'cuatrimestre_id'], 'uq_asignatura');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asignaturas');
    }
};
