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
        Schema::create('anuncios', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('admin_id')->index('fk_anuncio_admin');
            $table->string('titulo', 200);
            $table->text('contenido');
            $table->string('imagen')->nullable();
            $table->timestamp('fecha_publicacion')->nullable()->useCurrent();
            $table->enum('estatus', ['activo', 'inactivo'])->nullable()->default('activo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anuncios');
    }
};
