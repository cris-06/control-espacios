<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las tablas del flujo de reservas SOLO si no existen: actividades,
 * cuatrimestres, asignaturas, espacios, reservas, reserva_detalles e historial_reservas.
 *
 * En la base real (reservas_cuv_2) todas existen, así que no hace nada. Sirve para
 * instalaciones nuevas y para los tests (SQLite en memoria). Replica la estructura
 * de la base real (ids INT con signo, mismos ENUM y reglas ON DELETE).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('actividades')) {
            Schema::create('actividades', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->string('nombre', 150);
                $table->enum('estatus', ['activa', 'eliminada'])->nullable()->default('activa');
                $table->timestamp('fecha_registro')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('cuatrimestres')) {
            Schema::create('cuatrimestres', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->unsignedTinyInteger('numero')->unique();
                $table->string('nombre', 50);
            });

            $nombres = ['Primer', 'Segundo', 'Tercer', 'Cuarto', 'Quinto', 'Sexto', 'Séptimo', 'Octavo', 'Noveno'];
            DB::table('cuatrimestres')->insert(array_map(
                fn (string $nombre, int $i): array => ['id' => $i + 1, 'numero' => $i + 1, 'nombre' => "$nombre cuatrimestre"],
                $nombres,
                array_keys($nombres),
            ));
        }

        if (! Schema::hasTable('asignaturas')) {
            Schema::create('asignaturas', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->string('nombre', 150)->index();
                $table->integer('licenciatura_id');
                $table->integer('cuatrimestre_id');
                $table->enum('estatus', ['activa', 'inactiva'])->nullable()->default('activa');

                $table->foreign('licenciatura_id')->references('id')->on('licenciaturas');
                $table->foreign('cuatrimestre_id')->references('id')->on('cuatrimestres');
            });
        }

        if (! Schema::hasTable('espacios')) {
            Schema::create('espacios', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
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

        if (! Schema::hasTable('reservas')) {
            Schema::create('reservas', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->integer('usuario_creador_id');
                $table->integer('coordinador_id')->nullable();
                $table->integer('actividad_id');
                $table->integer('licenciatura_id');
                $table->enum('tipo_formato', ['normal', 'ext'])->nullable()->default('normal');
                $table->string('nombre_actividad')->nullable();
                $table->string('folio', 100)->nullable();
                $table->text('objetivo');
                $table->integer('cuatrimestre_id')->nullable();
                $table->integer('asignatura_id')->nullable();
                $table->decimal('costo_alumno', 10, 2)->nullable()->default(0);
                $table->enum('promocion_redes', ['si', 'no'])->nullable()->default('no');
                $table->integer('limite_min_usuarios')->nullable();
                $table->integer('limite_max_usuarios')->nullable();
                $table->date('fecha_entrega_formato')->nullable();
                $table->integer('estado_id')->default(1);
                $table->text('observaciones_admin')->nullable();
                $table->timestamp('fecha_creacion')->nullable()->useCurrent();
                $table->timestamp('fecha_actualizacion')->nullable()->useCurrent();

                $table->foreign('usuario_creador_id')->references('id')->on('usuarios');
                $table->foreign('coordinador_id')->references('id')->on('usuarios');
                $table->foreign('actividad_id')->references('id')->on('actividades');
                $table->foreign('licenciatura_id')->references('id')->on('licenciaturas');
                $table->foreign('cuatrimestre_id')->references('id')->on('cuatrimestres');
                $table->foreign('asignatura_id')->references('id')->on('asignaturas');
                $table->foreign('estado_id')->references('id')->on('estados_reserva');
            });
        }

        if (! Schema::hasTable('reserva_detalles')) {
            Schema::create('reserva_detalles', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->integer('reserva_id');
                $table->integer('espacio_id');
                $table->date('fecha');
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->integer('docente_responsable_id')->nullable();
                $table->integer('cantidad_alumnos')->nullable();
                $table->string('lugar_especifico', 150)->nullable();
                $table->text('descripcion_actividad')->nullable();
                $table->text('material_requerido')->nullable();
                $table->text('observaciones')->nullable();
                $table->enum('estado_detalle', ['pendiente', 'autorizada', 'denegada', 'cancelada'])->nullable()->default('pendiente');

                $table->foreign('reserva_id')->references('id')->on('reservas')->cascadeOnDelete();
                $table->foreign('espacio_id')->references('id')->on('espacios');
                $table->foreign('docente_responsable_id')->references('id')->on('usuarios');
            });
        }

        if (! Schema::hasTable('historial_reservas')) {
            Schema::create('historial_reservas', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->integer('reserva_id');
                $table->integer('usuario_id');
                $table->enum('accion', [
                    'creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador',
                    'enviada_admin', 'autorizada', 'denegada', 'cancelada',
                ]);
                $table->text('comentario')->nullable();
                $table->timestamp('fecha')->nullable()->useCurrent();

                $table->foreign('reserva_id')->references('id')->on('reservas')->cascadeOnDelete();
                $table->foreign('usuario_id')->references('id')->on('usuarios');
            });
        }
    }

    /**
     * No se eliminan las tablas: en la base real contienen datos.
     */
    public function down(): void
    {
        //
    }
};
