<?php

namespace Database\Seeders;

use App\Models\Requerimiento;
use Illuminate\Database\Seeder;

class RequerimientoSeeder extends Seeder
{
    public function run(): void
    {
        $requerimientos = [
            'Proyector',
            'Sonido',
            'Micrófono',
            'Extensión eléctrica',
            'Pizarrón',
            'Sillas adicionales',
            'Mesas adicionales',
        ];

        foreach ($requerimientos as $nombre) {
            Requerimiento::firstOrCreate(['nombre' => $nombre], ['estatus' => 'activo']);
        }
    }
}
