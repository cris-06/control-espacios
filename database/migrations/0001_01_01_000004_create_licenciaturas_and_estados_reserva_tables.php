<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `licenciaturas`, `usuario_licenciaturas` y `estados_reserva` SOLO si no existen.
 *
 * En la base real (reservas_cuv_2) ya existen, así que no hace nada. Sirve para
 * instalaciones nuevas y para los tests (SQLite en memoria). La estructura replica
 * la de la base real: ids INT con signo.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('licenciaturas')) {
            Schema::create('licenciaturas', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->string('nombre', 100);
                $table->enum('estatus', ['activa', 'inactiva'])->nullable()->default('activa');
            });
        }

        if (! Schema::hasTable('usuario_licenciaturas')) {
            Schema::create('usuario_licenciaturas', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->integer('usuario_id');
                $table->integer('licenciatura_id');

                $table->unique(['usuario_id', 'licenciatura_id'], 'uq_usuario_licenciatura');
                $table->foreign('usuario_id')->references('id')->on('usuarios');
                $table->foreign('licenciatura_id')->references('id')->on('licenciaturas');
            });
        }

        if (! Schema::hasTable('estados_reserva')) {
            Schema::create('estados_reserva', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->string('nombre', 50)->unique();
                $table->string('descripcion', 150)->nullable();
                $table->unsignedTinyInteger('orden');
            });

            DB::table('estados_reserva')->insert([
                ['id' => 1, 'nombre' => 'borrador', 'descripcion' => 'El creador aún la está llenando', 'orden' => 1],
                ['id' => 2, 'nombre' => 'pendiente_coordinador', 'descripcion' => 'Enviada por docente, espera revisión', 'orden' => 2],
                ['id' => 3, 'nombre' => 'correccion', 'descripcion' => 'El admin pidió ajustes', 'orden' => 3],
                ['id' => 4, 'nombre' => 'pendiente_admin', 'descripcion' => 'Aprobada por coordinador, espera admin', 'orden' => 4],
                ['id' => 5, 'nombre' => 'autorizada', 'descripcion' => 'Autorizada por el admin', 'orden' => 5],
                ['id' => 6, 'nombre' => 'denegada', 'descripcion' => 'Rechazada por admin', 'orden' => 6],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * No se eliminan las tablas: en la base real contienen datos.
     */
    public function down(): void
    {
        //
    }
};
