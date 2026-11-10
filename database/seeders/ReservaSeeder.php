<?php

namespace Database\Seeders;

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\Licenciatura;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReservaSeeder extends Seeder
{
    /** Una reserva completa de ejemplo para probar todas las relaciones. */
    public function run(): void
    {
        $docente = User::where('correo', 'docente1@cuv.test')->first();
        $coordinador = User::where('correo', 'coordinador1@cuv.test')->first();
        $actividad = Actividad::where('nombre', 'Conferencia')->first();
        $licenciatura = Licenciatura::where('nombre', 'Administración')->first();
        $espacio = Espacio::where('nombre', 'Auditorio')->first();

        if (! $docente || ! $coordinador || ! $actividad || ! $licenciatura || ! $espacio) {
            return; // faltan catálogos: corre primero el resto de los seeders
        }

        $reserva = Reserva::firstOrCreate(
            ['folio' => 'FOLIO-PRUEBA-001'],
            [
                'usuario_creador_id' => $docente->id,
                'coordinador_id' => $coordinador->id,
                'actividad_id' => $actividad->id,
                'licenciatura_id' => $licenciatura->id,
                'tipo_formato' => 'normal',
                'nombre_actividad' => 'Conferencia de prueba',
                'objetivo' => 'Reserva de ejemplo para probar relaciones.',
                'costo_alumno' => 0,
                'promocion_redes' => 'no',
                'limite_min_usuarios' => 10,
                'limite_max_usuarios' => 100,
                'estado_id' => 2, // pendiente_coordinador
            ]
        );

        if (! $reserva->wasRecentlyCreated) {
            return;
        }

        $detalle = $reserva->detalles()->create([
            'espacio_id' => $espacio->id,
            'fecha' => now()->addDays(7)->toDateString(),
            'hora_inicio' => '10:00:00',
            'hora_fin' => '12:00:00',
            'docente_responsable_id' => $docente->id,
            'cantidad_alumnos' => 50,
            'descripcion_actividad' => 'Conferencia de ejemplo',
            'estado_detalle' => 'pendiente',
        ]);

        $detalle->requerimientos()->attach([
            Requerimiento::where('nombre', 'Proyector')->value('id') => ['cantidad' => 1],
            Requerimiento::where('nombre', 'Micrófono')->value('id') => ['cantidad' => 2],
        ]);

        $reserva->historial()->createMany([
            ['usuario_id' => $docente->id, 'accion' => 'creada', 'comentario' => 'Reserva de prueba'],
            ['usuario_id' => $docente->id, 'accion' => 'enviada_coordinador', 'comentario' => null],
        ]);
    }
}
