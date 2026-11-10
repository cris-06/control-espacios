<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 'editada_coordinador' AL FINAL del ENUM historial_reservas.accion, para
 * registrar cuando el coordinador edita la reserva de un docente.
 *
 * Agregar un valor al final de un ENUM no modifica los registros existentes.
 * Si el valor ya existe, no hace nada.
 */
return new class extends Migration
{
    private const ORIGINALES = [
        'creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador',
        'enviada_admin', 'autorizada', 'denegada', 'cancelada',
    ];

    private const NUEVA = 'editada_coordinador';

    public function up(): void
    {
        if ($this->tipoActual() !== null && str_contains($this->tipoActual(), "'".self::NUEVA."'")) {
            return;
        }

        Schema::table('historial_reservas', function (Blueprint $table) {
            $table->enum('accion', [...self::ORIGINALES, self::NUEVA])->change();
        });
    }

    /**
     * Solo se revierte si ningún registro usa el valor nuevo.
     */
    public function down(): void
    {
        if (DB::table('historial_reservas')->where('accion', self::NUEVA)->exists()) {
            throw new RuntimeException('Hay registros con la acción '.self::NUEVA.'; no se puede quitar del ENUM sin perder datos.');
        }

        Schema::table('historial_reservas', function (Blueprint $table) {
            $table->enum('accion', self::ORIGINALES)->change();
        });
    }

    /**
     * Definición actual de la columna en MySQL (p. ej. "enum('creada',...)").
     * En SQLite el tipo es varchar con CHECK, así que se aplica el cambio siempre.
     */
    private function tipoActual(): ?string
    {
        foreach (Schema::getColumns('historial_reservas') as $columna) {
            if ($columna['name'] === 'accion') {
                return str_starts_with((string) $columna['type'], 'enum(') ? (string) $columna['type'] : null;
            }
        }

        return null;
    }
};
