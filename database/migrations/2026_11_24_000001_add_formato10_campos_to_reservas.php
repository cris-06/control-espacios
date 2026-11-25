<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campos del Formato 10 que se capturan una sola vez por solicitud (no por espacio):
 *
 * - docente_responsable_id: docente o coordinador responsable de la actividad completa (el
 *   responsable de cada espacio sigue en reserva_detalles.docente_responsable_id).
 * - cantidad_total_alumnos: alumnos distintos que participan. No es la suma de los espacios:
 *   un mismo grupo puede usar varios espacios en distintos horarios.
 * - descripcion_actividad y observaciones: texto general de la actividad. Los textos por espacio
 *   que ya existían (reserva_detalles) se conservan.
 *
 * Todas son opcionales (null en las reservas existentes) y cada una se agrega solo si no existe.
 * La llave foránea solo se crea en MySQL/MariaDB (SQLite no permite agregarla a una tabla ya
 * creada); la validación de la solicitud exige igualmente un usuario autorizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columnas = [
            'docente_responsable_id' => fn (Blueprint $t) => $t->integer('docente_responsable_id')->nullable()->after('coordinador_id'),
            'cantidad_total_alumnos' => fn (Blueprint $t) => $t->integer('cantidad_total_alumnos')->nullable()->after('asignaturas'),
            'descripcion_actividad' => fn (Blueprint $t) => $t->text('descripcion_actividad')->nullable()->after('objetivo'),
            'observaciones' => fn (Blueprint $t) => $t->text('observaciones')->nullable()->after('descripcion_actividad'),
        ];

        foreach ($columnas as $nombre => $definir) {
            if (! Schema::hasColumn('reservas', $nombre)) {
                Schema::table('reservas', fn (Blueprint $table) => $definir($table));
            }
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && ! $this->tieneLlave()) {
            Schema::table('reservas', function (Blueprint $table) {
                $table->foreign('docente_responsable_id')->references('id')->on('usuarios');
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && $this->tieneLlave()) {
            Schema::table('reservas', fn (Blueprint $table) => $table->dropForeign(['docente_responsable_id']));
        }

        foreach (['observaciones', 'descripcion_actividad', 'cantidad_total_alumnos', 'docente_responsable_id'] as $columna) {
            if (Schema::hasColumn('reservas', $columna)) {
                Schema::table('reservas', fn (Blueprint $table) => $table->dropColumn($columna));
            }
        }
    }

    private function tieneLlave(): bool
    {
        return collect(Schema::getForeignKeys('reservas'))
            ->contains(fn (array $llave): bool => $llave['columns'] === ['docente_responsable_id']);
    }
};
