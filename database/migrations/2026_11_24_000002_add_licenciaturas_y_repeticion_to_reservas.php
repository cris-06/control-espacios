<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formato 10: licenciaturas que participan además de la principal, y reservas que se repiten.
 *
 * reserva_licenciaturas — licenciaturas adicionales de una solicitud, cada una con sus
 * cuatrimestres y grupos (mismo formato JSON que reservas.cuatrimestres):
 *   - tipo 'participante': una de las licenciaturas de quien llena la solicitud;
 *   - tipo 'invitada': cualquier otra licenciatura activa.
 * Son solo informativas: NO dan permisos ni intervienen en la aprobación. La licenciatura
 * principal (reservas.licenciatura_id) sigue determinando coordinador, permisos, folio y flujo.
 * Una licenciatura aparece a lo más una vez por solicitud.
 *
 * Repetición — la configuración se guarda en la reserva (repeticion_frecuencia, _cantidad,
 * _hasta) y cada fecha generada es un bloque más de reserva_detalles, enlazado a su bloque
 * original (detalle_origen_id) con su número de ocurrencia (0 = original). Así cada fecha
 * pasa por las mismas validaciones, choques, bloqueos y autorización que cualquier bloque.
 *
 * No modifica datos existentes: las reservas anteriores quedan sin licenciaturas adicionales y
 * sin repetición (columnas en null). Cada tabla o columna se agrega solo si no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reserva_licenciaturas')) {
            Schema::create('reserva_licenciaturas', function (Blueprint $table) {
                $table->integer('id', autoIncrement: true);
                $table->integer('reserva_id');
                $table->integer('licenciatura_id');
                $table->enum('tipo', ['participante', 'invitada']);
                $table->json('cuatrimestres')->nullable();
                $table->unsignedSmallInteger('orden')->default(0);

                $table->unique(['reserva_id', 'licenciatura_id']);
                $table->foreign('reserva_id')->references('id')->on('reservas')->cascadeOnDelete();
                $table->foreign('licenciatura_id')->references('id')->on('licenciaturas');
            });
        }

        $reservas = [
            'repeticion_frecuencia' => fn (Blueprint $t) => $t->string('repeticion_frecuencia', 10)->nullable(),
            'repeticion_cantidad' => fn (Blueprint $t) => $t->unsignedSmallInteger('repeticion_cantidad')->nullable(),
            'repeticion_hasta' => fn (Blueprint $t) => $t->date('repeticion_hasta')->nullable(),
        ];

        foreach ($reservas as $columna => $definir) {
            if (! Schema::hasColumn('reservas', $columna)) {
                Schema::table('reservas', fn (Blueprint $table) => $definir($table));
            }
        }

        $detalles = [
            'detalle_origen_id' => fn (Blueprint $t) => $t->integer('detalle_origen_id')->nullable()->index(),
            'ocurrencia' => fn (Blueprint $t) => $t->unsignedSmallInteger('ocurrencia')->nullable(),
        ];

        foreach ($detalles as $columna => $definir) {
            if (! Schema::hasColumn('reserva_detalles', $columna)) {
                Schema::table('reserva_detalles', fn (Blueprint $table) => $definir($table));
            }
        }

        // SQLite no permite agregar la llave a una tabla existente; la integridad la mantiene ReservaService.
        if ($this->esMysql() && ! $this->tieneLlaveOrigen()) {
            Schema::table('reserva_detalles', function (Blueprint $table) {
                $table->foreign('detalle_origen_id')->references('id')->on('reserva_detalles')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->esMysql() && $this->tieneLlaveOrigen()) {
            Schema::table('reserva_detalles', fn (Blueprint $table) => $table->dropForeign(['detalle_origen_id']));
        }

        foreach (['ocurrencia', 'detalle_origen_id'] as $columna) {
            if (Schema::hasColumn('reserva_detalles', $columna)) {
                Schema::table('reserva_detalles', function (Blueprint $table) use ($columna) {
                    if ($columna === 'detalle_origen_id') {
                        $table->dropIndex(['detalle_origen_id']);
                    }
                    $table->dropColumn($columna);
                });
            }
        }

        foreach (['repeticion_hasta', 'repeticion_cantidad', 'repeticion_frecuencia'] as $columna) {
            if (Schema::hasColumn('reservas', $columna)) {
                Schema::table('reservas', fn (Blueprint $table) => $table->dropColumn($columna));
            }
        }

        Schema::dropIfExists('reserva_licenciaturas');
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function tieneLlaveOrigen(): bool
    {
        return collect(Schema::getForeignKeys('reserva_detalles'))
            ->contains(fn (array $llave): bool => $llave['columns'] === ['detalle_origen_id']);
    }
};
