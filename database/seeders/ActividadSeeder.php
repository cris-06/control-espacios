<?php

namespace Database\Seeders;

use App\Models\Actividad;
use Illuminate\Database\Seeder;

class ActividadSeeder extends Seeder
{
    public function run(): void
    {
        $actividades = [
            'Práctica de laboratorio',
            'Conferencia',
            'Taller',
            'Visita guiada',
            'Evento institucional',
        ];

        foreach ($actividades as $nombre) {
            Actividad::firstOrCreate(['nombre' => $nombre], ['estatus' => 'activa']);
        }
    }
}
