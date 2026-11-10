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
        Schema::create('usuarios', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('nombre', 100);
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100)->nullable();
            $table->string('correo', 150)->unique('correo');
            $table->string('telefono', 20)->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->integer('rol_id')->index('fk_usuario_rol');
            $table->enum('estatus', ['activo', 'inactivo'])->nullable()->default('activo');
            $table->boolean('debe_cambiar_password')->nullable()->default(true);
            $table->timestamp('fecha_registro')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
