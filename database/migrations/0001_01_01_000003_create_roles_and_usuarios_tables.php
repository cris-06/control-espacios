<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `roles` y `usuarios` SOLO si no existen.
 *
 * En la base real (reservas_cuv_2) ambas tablas ya existen, así que esta
 * migración no hace nada. Sirve para instalaciones nuevas y para los tests
 * (SQLite en memoria). La estructura replica la de la base real: ids INT con signo.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->string('nombre', 50)->unique();
                $table->unsignedTinyInteger('nivel')->unique();
                $table->string('descripcion', 150)->nullable();
            });

            DB::table('roles')->insert([
                ['nombre' => 'superadmin', 'nivel' => 100, 'descripcion' => 'Control total del sistema'],
                ['nombre' => 'admin', 'nivel' => 90, 'descripcion' => 'Autoriza o deniega solicitudes y administra catálogos y usuarios'],
                ['nombre' => 'coordinador', 'nivel' => 70, 'descripcion' => 'Revisa solicitudes de los docentes de sus licenciaturas'],
                ['nombre' => 'docente', 'nivel' => 50, 'descripcion' => 'Llena formatos y los envía a su coordinador'],
            ]);
        }

        if (! Schema::hasTable('usuarios')) {
            Schema::create('usuarios', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->string('nombre', 100);
                $table->string('apellido_paterno', 100);
                $table->string('apellido_materno', 100)->nullable();
                $table->string('correo', 150)->unique();
                $table->string('telefono', 20)->nullable();
                $table->string('password');
                $table->integer('rol_id')->index();
                $table->enum('estatus', ['activo', 'inactivo'])->nullable()->default('activo');
                $table->boolean('debe_cambiar_password')->nullable()->default(true);
                $table->timestamp('fecha_registro')->nullable()->useCurrent();

                $table->foreign('rol_id')->references('id')->on('roles');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * No se eliminan `usuarios` ni `roles`: contienen datos de la base existente.
     */
    public function down(): void
    {
        //
    }
};
