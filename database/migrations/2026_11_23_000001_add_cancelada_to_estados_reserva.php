<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el estado 'cancelada' (id 7) al catálogo estados_reserva, para que administración
 * pueda cancelar una reserva ya autorizada (ReservaWorkflowService::CANCELAR).
 *
 * Solo inserta una fila: no modifica la estructura ni los seis estados existentes. Si el id 7
 * o el nombre 'cancelada' ya existen (p. ej. se agregó a mano en la base real), no hace nada.
 */
return new class extends Migration
{
    private const ID = 7;

    private const NOMBRE = 'cancelada';

    public function up(): void
    {
        $existe = DB::table('estados_reserva')
            ->where('id', self::ID)
            ->orWhere('nombre', self::NOMBRE)
            ->exists();

        if ($existe) {
            return;
        }

        DB::table('estados_reserva')->insert([
            'id' => self::ID,
            'nombre' => self::NOMBRE,
            'descripcion' => 'Cancelada por administración después de autorizarse',
            'orden' => 7,
        ]);
    }

    /**
     * Solo quita el estado si ninguna reserva lo usa: nunca borra ni convierte reservas.
     */
    public function down(): void
    {
        if (DB::table('reservas')->where('estado_id', self::ID)->exists()) {
            throw new RuntimeException('Hay reservas canceladas; no se puede quitar el estado sin perder datos.');
        }

        DB::table('estados_reserva')->where('id', self::ID)->where('nombre', self::NOMBRE)->delete();
    }
};
