<?php

namespace Database\Seeders;

use App\Models\Espacio;
use App\Models\Requerimiento;
use Illuminate\Database\Seeder;

class EspacioSeeder extends Seeder
{
    public function run(): void
    {
        // Datos de ejemplo. 'requerimientos' => [nombre => cantidad disponible en ese espacio]
        $espacios = [
            [
                'nombre' => 'Auditorio',
                'descripcion' => 'Auditorio principal para eventos y conferencias',
                'ubicacion' => 'Edificio A, planta baja',
                'capacidad' => 200,
                'minimo_alumnos' => 20,
                'maximo_alumnos' => 200,
                'color_calendario' => '#dc2626',
                'requerimientos' => ['Proyector' => 1, 'Sonido' => 1, 'Micrófono' => 4, 'Sillas adicionales' => 50],
            ],
            [
                'nombre' => 'Sala de cómputo',
                'descripcion' => 'Sala con equipos de cómputo para prácticas',
                'ubicacion' => 'Edificio B, planta alta',
                'capacidad' => 30,
                'minimo_alumnos' => 5,
                'maximo_alumnos' => 30,
                'color_calendario' => '#2563eb',
                'requerimientos' => ['Proyector' => 1, 'Pizarrón' => 1, 'Extensión eléctrica' => 5],
            ],
            [
                'nombre' => 'Laboratorio de ciencias',
                'descripcion' => 'Laboratorio para prácticas de ciencias',
                'ubicacion' => 'Edificio C, planta baja',
                'capacidad' => 25,
                'minimo_alumnos' => 5,
                'maximo_alumnos' => 25,
                'color_calendario' => '#16a34a',
                'requerimientos' => ['Pizarrón' => 1, 'Mesas adicionales' => 6],
            ],
        ];

        foreach ($espacios as $datos) {
            $requerimientos = $datos['requerimientos'];
            unset($datos['requerimientos']);

            $espacio = Espacio::updateOrCreate(['nombre' => $datos['nombre']], $datos);

            $sync = [];
            foreach ($requerimientos as $nombre => $cantidad) {
                $id = Requerimiento::where('nombre', $nombre)->value('id');
                if ($id) {
                    $sync[$id] = ['cantidad_disponible' => $cantidad];
                }
            }
            $espacio->requerimientos()->sync($sync);
        }
    }
}
