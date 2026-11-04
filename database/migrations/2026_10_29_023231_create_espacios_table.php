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
        Schema::create('espacios', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->string('ubicacion', 150)->nullable();
            $table->string('foto')->nullable();
            $table->integer('capacidad')->nullable();
            $table->integer('minimo_alumnos')->nullable();
            $table->integer('maximo_alumnos')->nullable();
            $table->string('color_calendario', 20)->nullable()->default('#dc2626');
            $table->enum('estatus', ['disponible', 'mantenimiento', 'inactivo'])->nullable()->default('disponible');
            $table->timestamp('fecha_creacion')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('espacios');
    }
};
