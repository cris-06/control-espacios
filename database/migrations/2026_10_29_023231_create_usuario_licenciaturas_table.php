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
        Schema::create('usuario_licenciaturas', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('usuario_id');
            $table->integer('licenciatura_id')->index('fk_ul_licenciatura');

            $table->unique(['usuario_id', 'licenciatura_id'], 'uq_usuario_licenciatura');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuario_licenciaturas');
    }
};
