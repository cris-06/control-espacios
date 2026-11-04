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
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('usuario_id')->index('fk_notificacion_usuario');
            $table->string('titulo', 180);
            $table->text('mensaje');
            $table->string('tipo', 30)->nullable()->default('info');
            $table->boolean('leida')->nullable()->default(false);
            $table->timestamp('fecha')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
