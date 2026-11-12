<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * La descripción de la actividad de cada bloque se guarda y llega al detalle de la
 * reserva (Show.vue la muestra dentro de su bloque; la plantilla se valida a mano).
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->actividad = Actividad::factory()->create();
    $this->laboratorio = Espacio::factory()->create(['nombre' => 'Laboratorio', 'capacidad' => 40]);
    $this->aula = Espacio::factory()->create(['nombre' => 'Aula', 'capacidad' => 40]);

    $this->bloque = fn (Espacio $espacio, string $fecha, mixed $descripcion): array => [
        'espacio_id' => $espacio->id,
        'fecha' => $fecha,
        'hora_inicio' => '10:00',
        'hora_fin' => '12:00',
        'cantidad_alumnos' => 20,
        'descripcion_actividad' => $descripcion,
    ];

    // Guarda la solicitud como borrador por la ruta normal y devuelve la reserva creada.
    $this->guardar = function (array $detalles): Reserva {
        $this->actingAs($this->docente)->post(route('reservas.store'), [
            'licenciatura_id' => $this->licenciatura->id,
            'actividad_id' => $this->actividad->id,
            'nombre_actividad' => 'Taller de redes',
            'objetivo' => 'Configurar routers',
            'detalles' => $detalles,
        ])->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };

    // Bloques tal como los recibe Show.vue.
    $this->detallesVista = function (Reserva $reserva): array {
        $detalles = [];

        $this->actingAs($this->docente)->get(route('reservas.show', $reserva))
            ->assertOk()
            ->assertInertia(function (Assert $pagina) use (&$detalles) {
                $detalles = $pagina->toArray()['props']['reserva']['detalles'];
            });

        return $detalles;
    };
});

test('the saved activity description reaches the detail page', function () {
    $texto = "Práctica de conexión de equipos.\nSegunda línea con <b>etiquetas</b> que no deben interpretarse.";
    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), $texto)]);

    expect(($this->detallesVista)($reserva)[0]['descripcion_actividad'])->toBe($texto);
});

test('an empty or blank activity description is stored and sent as null', function (?string $descripcion) {
    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), $descripcion)]);

    expect($reserva->detalles()->value('descripcion_actividad'))->toBeNull()
        ->and(($this->detallesVista)($reserva)[0]['descripcion_actividad'])->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'only spaces' => ['    '],
]);

test('each description stays with its own block, in the order the blocks are shown', function () {
    // El bloque más tardío se envía primero: el detalle ordena por fecha y hora.
    $reserva = ($this->guardar)([
        ($this->bloque)($this->laboratorio, Date::today()->addDays(3)->toDateString(), 'Práctica en el laboratorio'),
        ($this->bloque)($this->aula, Date::tomorrow()->toDateString(), 'Exposición en el aula'),
        ($this->bloque)($this->laboratorio, Date::today()->addDays(5)->toDateString(), ''),
    ]);

    $vista = array_map(fn (array $d): array => [$d['espacio']['nombre'], $d['fecha'], $d['descripcion_actividad']], ($this->detallesVista)($reserva));

    expect($vista)->toBe([
        ['Aula', Date::tomorrow()->toDateString(), 'Exposición en el aula'],
        ['Laboratorio', Date::today()->addDays(3)->toDateString(), 'Práctica en el laboratorio'],
        ['Laboratorio', Date::today()->addDays(5)->toDateString(), null],
    ]);
});

test('viewing the detail does not modify anything', function () {
    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), 'Práctica de redes')]);
    $foto = fn (): array => [
        'reserva' => Reserva::query()->findOrFail($reserva->id)->only(['estado_id', 'coordinador_id', 'observaciones_admin']),
        'detalles' => ReservaDetalle::query()->where('reserva_id', $reserva->id)->orderBy('id')->get()->toArray(),
        'historial' => HistorialReserva::query()->count(),
        'notificaciones' => Notificacion::query()->count(),
    ];
    $antes = $foto();

    $escrituras = [];
    DB::listen(function ($consulta) use (&$escrituras) {
        if (preg_match('/^\s*(insert|update|delete)\b.*\b(reservas|reserva_detalles|reserva_detalle_requerimientos|historial_reservas|notificaciones)\b/i', $consulta->sql)) {
            $escrituras[] = $consulta->sql;
        }
    });

    ($this->detallesVista)($reserva);

    expect($escrituras)->toBe([])
        ->and($foto())->toBe($antes);
});
