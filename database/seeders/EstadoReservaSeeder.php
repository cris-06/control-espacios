<?php

namespace Database\Seeders;

use App\Models\EstadoReserva;
use Illuminate\Database\Seeder;

class EstadoReservaSeeder extends Seeder
{
    public function run(): void
    {
        // Los ids son fijos porque el código (y reservas.estado_id DEFAULT 1) dependen de ellos.
        $estados = [
            ['id' => 1, 'nombre' => 'borrador',              'descripcion' => 'El creador aún la está llenando',      'orden' => 1],
            ['id' => 2, 'nombre' => 'pendiente_coordinador', 'descripcion' => 'Enviada por docente, espera revisión', 'orden' => 2],
            ['id' => 3, 'nombre' => 'correccion',            'descripcion' => 'Se pidieron ajustes',                  'orden' => 3],
            ['id' => 4, 'nombre' => 'pendiente_admin',       'descripcion' => 'Aprobada por coordinador, espera admin', 'orden' => 4],
            ['id' => 5, 'nombre' => 'autorizada',            'descripcion' => 'Autorizada por el admin',              'orden' => 5],
            ['id' => 6, 'nombre' => 'denegada',              'descripcion' => 'Rechazada por admin',                  'orden' => 6],
        ];

        foreach ($estados as $estado) {
            EstadoReserva::updateOrCreate(['id' => $estado['id']], $estado);
        }
    }
}
