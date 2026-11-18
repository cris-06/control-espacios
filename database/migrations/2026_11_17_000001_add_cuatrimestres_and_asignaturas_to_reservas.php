<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una solicitud puede abarcar varios cuatrimestres y varias asignaturas escritas a mano.
 *
 * - reservas.cuatrimestres: lista JSON de {id, grupos}: cada cuatrimestre marcado con sus grupos
 *   (A, B, C, …), p. ej. [{"id": 1, "grupos": ["A", "B"]}, {"id": 3, "grupos": ["B"]}].
 * - reservas.asignaturas: lista JSON de nombres libres (no dependen del catálogo `asignaturas`).
 *
 * Las reservas existentes conservan lo que tenían: su cuatrimestre_id pasa a ser una lista de
 * un elemento (sin grupos) y su asignatura_id, el nombre de esa asignatura. Las columnas anteriores no se
 * borran (tienen llaves foráneas y los registros siguen siendo válidos), pero el formulario
 * ya no las usa. Cada columna se agrega solo si no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reservas', 'cuatrimestres')) {
            Schema::table('reservas', function (Blueprint $table) {
                $table->json('cuatrimestres')->nullable()->after('cuatrimestre_id');
            });

            DB::table('reservas')->whereNotNull('cuatrimestre_id')->orderBy('id')
                ->each(fn (object $reserva) => DB::table('reservas')->where('id', $reserva->id)
                    ->update(['cuatrimestres' => json_encode([['id' => (int) $reserva->cuatrimestre_id, 'grupos' => []]])]));
        }

        if (! Schema::hasColumn('reservas', 'asignaturas')) {
            Schema::table('reservas', function (Blueprint $table) {
                $table->json('asignaturas')->nullable()->after('asignatura_id');
            });

            DB::table('reservas')
                ->join('asignaturas', 'asignaturas.id', '=', 'reservas.asignatura_id')
                ->orderBy('reservas.id')
                ->select('reservas.id', 'asignaturas.nombre')
                ->each(fn (object $fila) => DB::table('reservas')->where('id', $fila->id)
                    ->update(['asignaturas' => json_encode([$fila->nombre], JSON_UNESCAPED_UNICODE)]));
        }
    }

    public function down(): void
    {
        foreach (['asignaturas', 'cuatrimestres'] as $columna) {
            if (Schema::hasColumn('reservas', $columna)) {
                Schema::table('reservas', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
};
