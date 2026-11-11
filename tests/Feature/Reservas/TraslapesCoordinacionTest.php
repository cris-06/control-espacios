<?php

use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Hallazgo 7b: los mensajes de traslape de ReservaWorkflowService::traslapes() solo
 * muestran el folio de la otra reserva si quien los recibe puede consultarla. La
 * detección, el bloqueo y el rechazo de la autorización no cambian.
 */
beforeEach(function () {
    // Licenciatura A: la del coordinador que revisa.
    $this->licA = Licenciatura::factory()->create(['nombre' => 'Licenciatura A']);
    $this->docenteA = User::factory()->rol('docente')->create(['nombre' => 'Daniela']);
    $this->docenteA->licenciaturas()->attach($this->licA);
    $this->coordA = User::factory()->rol('coordinador')->create(['nombre' => 'Carlos']);
    $this->coordA->licenciaturas()->attach($this->licA);

    // Licenciatura B: ajena al coordinador A.
    $this->licB = Licenciatura::factory()->create(['nombre' => 'Licenciatura privada de B']);
    $this->docenteB = User::factory()->rol('docente')->create(['nombre' => 'Beatriz', 'apellido_paterno' => 'Privada']);
    $this->docenteB->licenciaturas()->attach($this->licB);
    $this->coordB = User::factory()->rol('coordinador')->create();
    $this->coordB->licenciaturas()->attach($this->licB);

    $this->admin = User::factory()->rol('admin')->create();
    $this->workflow = app(W::class);

    $this->laboratorio = Espacio::factory()->create(['nombre' => 'Laboratorio', 'capacidad' => 40]);
    $this->aula = Espacio::factory()->create(['nombre' => 'Aula', 'capacidad' => 40]);
    $this->manana = Date::today()->addDays(30)->toDateString();
    $this->fecha = Date::today()->addDays(30)->format('d/m/Y');

    $this->reserva = fn (User $creador, Licenciatura $licenciatura, int $estado, array $detalle = [], array $datos = []): Reserva => Reserva::factory()
        ->enEstado($estado)
        ->conDetalle(['espacio_id' => $this->laboratorio->id, 'fecha' => $this->manana, ...$detalle])
        ->create(['usuario_creador_id' => $creador->id, 'licenciatura_id' => $licenciatura->id, ...$datos]);

    $this->autorizadaEn = fn (User $creador, Licenciatura $licenciatura, Espacio $espacio, array $datos = []): Reserva => ($this->reserva)(
        $creador, $licenciatura, E::AUTORIZADA,
        ['espacio_id' => $espacio->id, 'hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00', 'estado_detalle' => ReservaDetalle::AUTORIZADA],
        $datos,
    );

    // Reserva AUTORIZADA de la licenciatura B, con datos privados, en el laboratorio de 09:00 a 11:00.
    $this->ajena = fn (): Reserva => ($this->autorizadaEn)($this->docenteB, $this->licB, $this->laboratorio, [
        'nombre_actividad' => 'Actividad secreta de B',
        'objetivo' => 'Objetivo secreto de B',
        'observaciones_admin' => 'Observación secreta de B',
    ]);

    // Solicitud de la licenciatura A que pide el laboratorio de 10:00 a 12:00.
    $this->solicitud = fn (int $estado = E::PENDIENTE_COORDINADOR, ?User $creador = null): Reserva => ($this->reserva)(
        $creador ?? $this->docenteA, $this->licA, $estado, ['hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00'],
    );

    $this->folio = fn (Reserva $r): string => $r->refresh()->folio ?? Reserva::folioPara($r->id);
    $this->conOtra = fn (string $espacio = 'Laboratorio', string $horario = '10:00 a 12:00'): string => "{$espacio} el {$this->fecha} de {$horario} choca con otra reserva autorizada (09:00 a 11:00).";
    $this->conFolio = fn (string $folio, string $espacio = 'Laboratorio', string $horario = '10:00 a 12:00'): string => "{$espacio} el {$this->fecha} de {$horario} choca con la reserva autorizada {$folio} (09:00 a 11:00).";

    $this->pagina = function (Reserva $reserva, User $usuario): array {
        $props = [];

        $this->actingAs($usuario)->get(route('reservas.show', $reserva))
            ->assertOk()
            ->assertInertia(function (Assert $pagina) use (&$props) {
                $props = $pagina->toArray()['props'];
            });

        return $props;
    };

    $this->toast = fn ($respuesta): array => $respuesta->baseResponse->getSession()->get('inertia.flash_data')['toast'] ?? [];

    $this->privados = fn (Reserva $ajena): array => [($this->folio)($ajena), 'Beatriz', 'Privada', 'Actividad secreta de B', 'Objetivo secreto de B', 'Observación secreta de B', 'Licenciatura privada de B'];
});

test('A: the coordinator sees the clash with another program\'s reservation, without its folio', function () {
    ($this->ajena)();
    $reserva = ($this->solicitud)();

    expect(($this->pagina)($reserva, $this->coordA)['traslapes'])->toBe([($this->conOtra)()]);
});

test('B: the whole detail response reveals nothing of the other program\'s reservation', function () {
    $ajena = ($this->ajena)();
    $reserva = ($this->solicitud)();

    $respuesta = json_encode(($this->pagina)($reserva, $this->coordA), JSON_UNESCAPED_UNICODE);

    foreach (($this->privados)($ajena) as $dato) {
        expect($respuesta)->not->toContain($dato);
    }
});

test('C: approving keeps the warning and the clash, but the message hides the foreign folio', function () {
    $ajena = ($this->ajena)();
    $reserva = ($this->solicitud)();

    $respuesta = $this->actingAs($this->coordA)->post(route('reservas.transicion', $reserva), ['accion' => W::APROBAR]);
    $toast = ($this->toast)($respuesta);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($toast['type'])->toBe('warning')
        ->and($toast['message'])->toContain(($this->conOtra)())
        ->and($toast['message'])->not->toContain(($this->folio)($ajena))
        // La detección interna sigue completa: el mismo choque, ahora contado con el folio visible para administración.
        ->and($this->workflow->traslapes($reserva, $this->admin))->toBe([($this->conFolio)(($this->folio)($ajena))]);
});

test('D: a coordinator sending their own request to administration gets the clash without the foreign folio', function () {
    $ajena = ($this->ajena)();
    $propia = ($this->solicitud)(E::BORRADOR, $this->coordA);

    $respuesta = $this->actingAs($this->coordA)->post(route('reservas.transicion', $propia), ['accion' => W::ENVIAR]);
    $toast = ($this->toast)($respuesta);

    expect($propia->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($toast['type'])->toBe('warning')
        ->and($toast['message'])->toContain(($this->conOtra)())
        ->and($toast['message'])->not->toContain(($this->folio)($ajena));
});

test('E: the coordinator keeps the folio of a reservation they may view', function () {
    $visible = ($this->autorizadaEn)($this->docenteA, $this->licA, $this->laboratorio);
    $reserva = ($this->solicitud)();

    expect(($this->pagina)($reserva, $this->coordA)['traslapes'])->toBe([($this->conFolio)(($this->folio)($visible))]);
});

test('F: administration keeps the same message, with the folio', function () {
    $ajena = ($this->ajena)();
    $reserva = ($this->solicitud)(E::PENDIENTE_ADMIN);

    expect(($this->pagina)($reserva, $this->admin)['traslapes'])->toBe([($this->conFolio)(($this->folio)($ajena))]);
});

test('G: authorizing a request with a clash is still rejected, whoever could see the folio', function () {
    $ajena = ($this->ajena)();
    $reserva = ($this->solicitud)(E::PENDIENTE_ADMIN);

    // Ocultar el folio no quita el choque: hay el mismo número de mensajes para quien no ve la otra reserva.
    expect($this->workflow->traslapes($reserva, $this->coordA))->toHaveCount(1)
        ->and($this->workflow->traslapes($reserva, $this->admin))->toHaveCount(1);

    try {
        $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Autorizada.');
        $this->fail('La autorización con choque debió rechazarse.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain('choca con la reserva autorizada '.($this->folio)($ajena));
    }

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($reserva->detalles()->value('estado_detalle'))->toBe(ReservaDetalle::PENDIENTE);
});

test('H: whoever cannot view the request still gets 403', function () {
    ($this->ajena)();
    $reserva = ($this->solicitud)();

    $this->actingAs($this->coordB)->get(route('reservas.show', $reserva))->assertForbidden();
    $this->actingAs($this->docenteB)->get(route('reservas.show', $reserva))->assertForbidden();
});

test('I: a request without clashes has no messages', function () {
    ($this->ajena)();
    $libre = ($this->reserva)($this->docenteA, $this->licA, E::PENDIENTE_COORDINADOR, ['hora_inicio' => '11:00:00', 'hora_fin' => '12:00:00']);

    expect(($this->pagina)($libre, $this->coordA)['traslapes'])->toBe([]);
});

test('J: with several blocks every real clash is reported once, folio only where allowed', function () {
    $ajena = ($this->ajena)();
    $visible = ($this->autorizadaEn)($this->docenteA, $this->licA, $this->aula);
    $reserva = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)
        ->conDetalle(['espacio_id' => $this->laboratorio->id, 'fecha' => $this->manana, 'hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00'])
        ->conDetalle(['espacio_id' => $this->aula->id, 'fecha' => $this->manana, 'hora_inicio' => '10:30:00', 'hora_fin' => '11:30:00'])
        ->conDetalle(['espacio_id' => $this->laboratorio->id, 'fecha' => Date::today()->addDays(3)->toDateString(), 'hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00'])
        ->create(['usuario_creador_id' => $this->docenteA->id, 'licenciatura_id' => $this->licA->id]);

    $traslapes = ($this->pagina)($reserva, $this->coordA)['traslapes'];

    expect($traslapes)->toHaveCount(2)
        ->and($traslapes)->toContain(($this->conOtra)())
        ->and($traslapes)->toContain(($this->conFolio)(($this->folio)($visible), 'Aula', '10:30 a 11:30'))
        ->and(implode(' ', $traslapes))->not->toContain(($this->folio)($ajena));
});

test('K: the creator keeps the protection of finding 7', function () {
    $ajena = ($this->ajena)();
    $reserva = ($this->solicitud)();

    $traslapes = ($this->pagina)($reserva, $this->docenteA)['traslapes'];

    expect($traslapes)->toBe([($this->conOtra)()])
        ->and(implode(' ', $traslapes))->not->toContain(($this->folio)($ajena));
});

test('L: viewing the detail writes nothing and keeps history and notifications', function () {
    ($this->ajena)();
    $reserva = ($this->solicitud)();
    $reserva->historial()->create(['usuario_id' => $this->docenteA->id, 'accion' => HistorialReserva::ENVIADA_COORDINADOR]);
    Notificacion::query()->create(['usuario_id' => $this->coordA->id, 'reserva_id' => $reserva->id, 'titulo' => 'Aviso', 'mensaje' => 'Prueba', 'tipo' => 'info']);
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

    foreach ([$this->coordA, $this->admin, $this->docenteA] as $usuario) {
        ($this->pagina)($reserva, $usuario);
    }

    expect($escrituras)->toBe([])
        ->and($foto())->toBe($antes);
});
