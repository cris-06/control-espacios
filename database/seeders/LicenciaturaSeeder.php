<?php

namespace Database\Seeders;

use App\Models\Licenciatura;
use Illuminate\Database\Seeder;

class LicenciaturaSeeder extends Seeder
{
    public function run(): void
    {
        // Datos de ejemplo: cámbialos por las licenciaturas reales.
        $licenciaturas = [
            'Administración',
            'Derecho',
            'Ingeniería en Sistemas',
            'Psicología',
        ];

        foreach ($licenciaturas as $nombre) {
            Licenciatura::firstOrCreate(['nombre' => $nombre], ['estatus' => 'activa']);
        }
    }
}
