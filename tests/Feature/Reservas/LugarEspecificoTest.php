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
 * El lugar específico de cada bloque se guarda normalizado y llega al detalle de la
 * reserva (Show.vue lo muestra dentro de su bloque; la plantilla se valida a mano).
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->actividad = Actividad::factory()->create();
    $this->laboratorio = Espacio::factory()->create(['nombre' => 'Laboratorio', 'capacidad' => 40]);
    $this->aula = Espacio::factory()->create(['nombre' => 'Aula', 'capacidad' => 40]);

    $this->bloque = fn (Espacio $espacio, string $fecha, mixed $lugar): array => [
        'espacio_id' => $espacio->id,
        'fecha' => $fecha,
        'hora_inicio' => '10:00',
        'hora_fin' => '12:00',
        'cantidad_alumnos' => 20,
        'lugar_especifico' => $lugar,
    ];

    $this->enviar = fn (array $detalles) => $this->actingAs($this->docente)->post(route('reservas.store'), [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Taller de redes',
        'objetivo' => 'Configurar routers',
        'detalles' => $detalles,
    ]);

    // Guarda la solicitud como borrador por la ruta normal y devuelve la reserva creada.
    $this->guardar = function (array $detalles): Reserva {
        ($this->enviar)($detalles)->assertSessionHasNoErrors();

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

test('A: a saved specific place reaches the detail page', function () {
    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), 'Edificio A, sala 2')]);

    expect(($this->detallesVista)($reserva)[0]['lugar_especifico'])->toBe('Edificio A, sala 2');
});

test('B, C and D: null, empty or blank specific places are stored and sent as null', function (?string $lugar) {
    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), $lugar)]);

    expect($reserva->detalles()->value('lugar_especifico'))->toBeNull()
        ->and(($this->detallesVista)($reserva)[0]['lugar_especifico'])->toBeNull();
})->with([
    'B null' => [null],
    'C empty' => [''],
    'D only spaces' => ['    '],
]);

test('E: each specific place stays with its own block, in the order the blocks are shown', function () {
    // El bloque más tardío se envía primero: el detalle ordena por fecha y hora.
    $reserva = ($this->guardar)([
        ($this->bloque)($this->laboratorio, Date::today()->addDays(3)->toDateString(), 'Edificio A, sala 2'),
        ($this->bloque)($this->aula, Date::tomorrow()->toDateString(), 'Edificio B, planta baja'),
        ($this->bloque)($this->laboratorio, Date::today()->addDays(5)->toDateString(), null),
    ]);

    $vista = array_map(fn (array $d): array => [$d['espacio']['nombre'], $d['fecha'], $d['lugar_especifico']], ($this->detallesVista)($reserva));

    expect($vista)->toBe([
        ['Aula', Date::tomorrow()->toDateString(), 'Edificio B, planta baja'],
        ['Laboratorio', Date::today()->addDays(3)->toDateString(), 'Edificio A, sala 2'],
        ['Laboratorio', Date::today()->addDays(5)->toDateString(), null],
    ]);
});

test('F: a value with HTML tags arrives as plain text and the page shows it by safe interpolation', function () {
    $texto = 'Sala <b>2</b> <script>alert(1)</script>';
    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), $texto)]);

    expect(($this->detallesVista)($reserva)[0]['lugar_especifico'])->toBe($texto);

    // La plantilla lo interpola con {{ }} (Vue escapa el HTML) y no usa v-html en ningún lugar.
    $plantilla = (string) file_get_contents(resource_path('js/pages/reservas/Show.vue'));
    expect($plantilla)->toContain('{{ detalle.lugar_especifico }}')
        ->and($plantilla)->not->toContain('v-html');
});

test('G: a specific place longer than 150 characters is rejected', function () {
    ($this->enviar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), str_repeat('a', 151))])
        ->assertSessionHasErrors('detalles.0.lugar_especifico');

    expect(Reserva::query()->count())->toBe(0);

    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), str_repeat('a', 150))]);
    expect(mb_strlen((string) $reserva->detalles()->value('lugar_especifico')))->toBe(150);
});

test('H: viewing the detail writes nothing and keeps history and notifications', function () {
    $reserva = ($this->guardar)([($this->bloque)($this->laboratorio, Date::tomorrow()->toDateString(), 'Edificio A, sala 2')]);
    Notificacion::query()->create(['usuario_id' => $this->docente->id, 'reserva_id' => $reserva->id, 'titulo' => 'Aviso', 'mensaje' => 'Prueba', 'tipo' => 'info']);
    $foto = fn (): array => [
        'reservas' => Reserva::query()->orderBy('id')->get(['id', 'estado_id', 'observaciones_admin'])->toArray(),
        'detalles' => ReservaDetalle::query()->orderBy('id')->get()->toArray(),
        'historial' => HistorialReserva::query()->orderBy('id')->get(['id', 'accion', 'comentario'])->toArray(),
        'notificaciones' => Notificacion::query()->orderBy('id')->get(['id', 'leida'])->toArray(),
    ];
    $antes = $foto();

    $escrituras = [];
    DB::listen(function ($consulta) use (&$escrituras) {
        if (preg_match('/^\s*(insert|update|delete)\b.*\b(reservas|reserva_detalles|historial_reservas|notificaciones)\b/i', $consulta->sql)) {
            $escrituras[] = $consulta->sql;
        }
    });

    ($this->detallesVista)($reserva);

    expect($escrituras)->toBe([])
        ->and($foto())->toBe($antes);
});
