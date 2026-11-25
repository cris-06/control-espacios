<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cantidad de cuatrimestres de cada licenciatura (la configura solo el superadministrador).
 *
 * - Las licenciaturas existentes quedan con 9, el valor con el que trabajaba el sistema
 *   (el catálogo `cuatrimestres` tenía del primero al noveno).
 * - Si alguna ya tiene datos en un cuatrimestre mayor (catálogo ampliado a mano, reservas,
 *   licenciaturas participantes o asignaturas), se toma ese número para no ocultarlos.
 * - Los cuatrimestres 10 en adelante se agregan al catálogo cuando el superadministrador
 *   configura una licenciatura que los necesita (Cuatrimestre::asegurarHasta), no aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('licenciaturas', 'cantidad_cuatrimestres')) {
            return;
        }

        Schema::table('licenciaturas', function (Blueprint $table) {
            $table->unsignedTinyInteger('cantidad_cuatrimestres')->default(9);
        });

        $numeros = DB::table('cuatrimestres')->pluck('numero', 'id')->map(fn ($n): int => (int) $n)->all();
        $maximos = [];
        $anotar = function (int $licenciaturaId, mixed $cuatrimestreId) use (&$maximos, $numeros): void {
            $numero = $numeros[(int) $cuatrimestreId] ?? 0;

            if ($numero > ($maximos[$licenciaturaId] ?? 0)) {
                $maximos[$licenciaturaId] = $numero;
            }
        };
        $anotarLista = function (int $licenciaturaId, mixed $json) use ($anotar): void {
            $lista = is_string($json) ? json_decode($json, true) : null;

            foreach (is_array($lista) ? $lista : [] as $c) {
                if (is_array($c) && isset($c['id'])) {
                    $anotar($licenciaturaId, $c['id']);
                }
            }
        };

        if (Schema::hasTable('reservas')) {
            $columnas = array_values(array_filter(['cuatrimestre_id', 'cuatrimestres'], fn (string $c): bool => Schema::hasColumn('reservas', $c)));

            if ($columnas !== []) {
                DB::table('reservas')->select(['id', 'licenciatura_id', ...$columnas])->orderBy('id')
                    ->each(function (object $r) use ($anotar, $anotarLista): void {
                        if (isset($r->cuatrimestre_id)) {
                            $anotar((int) $r->licenciatura_id, $r->cuatrimestre_id);
                        }

                        $anotarLista((int) $r->licenciatura_id, $r->cuatrimestres ?? null);
                    });
            }
        }

        if (Schema::hasTable('reserva_licenciaturas')) {
            DB::table('reserva_licenciaturas')->select(['id', 'licenciatura_id', 'cuatrimestres'])->orderBy('id')
                ->each(fn (object $r) => $anotarLista((int) $r->licenciatura_id, $r->cuatrimestres));
        }

        if (Schema::hasTable('asignaturas')) {
            DB::table('asignaturas')->select(['licenciatura_id', 'cuatrimestre_id'])->get()
                ->each(fn (object $a) => $anotar((int) $a->licenciatura_id, $a->cuatrimestre_id));
        }

        foreach ($maximos as $licenciaturaId => $maximo) {
            if ($maximo > 9) {
                DB::table('licenciaturas')->where('id', $licenciaturaId)->update(['cantidad_cuatrimestres' => $maximo]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('licenciaturas', 'cantidad_cuatrimestres')) {
            Schema::table('licenciaturas', function (Blueprint $table) {
                $table->dropColumn('cantidad_cuatrimestres');
            });
        }
    }
};
