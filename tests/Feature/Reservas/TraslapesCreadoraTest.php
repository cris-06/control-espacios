<?php

use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\DisponibilidadEspacioService;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * La docente creadora ve en el detalle los choques de su solicitud con reservas
 * AUTORIZADAS, sin folio ni datos de las reservas ajenas que no puede consultar.
 * Coordinación y administración conservan su comportamiento actual.
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create(['nombre' => 'Ingeniería de prueba']);
    $this->docente = User::factory()->rol('docente')->create(['nombre' => 'Daniela']);
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();

    // Docente de OTRA licenciatura, dueña de la reserva ajena.
    $this->otraLicenciatura = Licenciatura::factory()->create(['nombre' => 'Licenciatura privada de B']);
    $this->otraDocente = User::factory()->rol('docente')->create(['nombre' => 'Beatriz', 'apellido_paterno' => 'Privada']);
    $this->otraDocente->licenciaturas()->attach($this->otraLicenciatura);

    $this->laboratorio = Espacio::factory()->create(['nombre' => 'Laboratorio']);
    $this->aula = Espacio::factory()->create(['nombre' => 'Aula']);
    $this->manana = Date::tomorrow()->toDateString();
    $this->fecha = Date::tomorrow()->format('d/m/Y');

    $this->reserva = fn (User $creador, Licenciatura $licenciatura, int $estado, array $detalle = [], array $datos = []): Reserva => Reserva::factory()
        ->enEstado($estado)
        ->conDetalle(['espacio_id' => $this->laboratorio->id, 'fecha' => $this->manana, ...$detalle])
        ->create(['usuario_creador_id' => $creador->id, 'licenciatura_id' => $licenciatura->id, ...$datos]);

    // Reserva autorizada que ocupa el laboratorio mañana de 09:00 a 11:00.
    $this->autorizada = fn (User $creador, Licenciatura $licenciatura, array $datos = []): Reserva => ($this->reserva)(
        $creador, $licenciatura, E::AUTORIZADA,
        ['hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00', 'estado_detalle' => ReservaDetalle::AUTORIZADA],
        $datos,
    );

    // Solicitud de la docente que pide el laboratorio de 10:00 a 12:00 (choca con la anterior).
    $this->solicitud = fn (int $estado = E::PENDIENTE_COORDINADOR): Reserva => ($this->reserva)(
        $this->docente, $this->licenciatura, $estado, ['hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00'],
    );

    // Props completas de la página de detalle.
    $this->pagina = function (Reserva $reserva, User $usuario): array {
        $props = [];

        $this->actingAs($usuario)->get(route('reservas.show', $reserva))
            ->assertOk()
            ->assertInertia(function (Assert $pagina) use (&$props) {
                $props = $pagina->toArray()['props'];
            });

        return $props;
    };
});

test('the creator sees the clash with her own authorized reservation, with its folio', function () {
    $propia = ($this->autorizada)($this->docente, $this->licenciatura);
    $reserva = ($this->solicitud)();

    expect(($this->pagina)($reserva, $this->docente)['traslapes'])->toBe([
        'Laboratorio el '.$this->fecha.' de 10:00 a 12:00 choca con la reserva autorizada '.Reserva::folioPara($propia->id).' (09:00 a 11:00).',
    ]);
});

test('the creator sees a clash with someone else\'s reservation without its folio or any private data', function () {
    $ajena = ($this->autorizada)($this->otraDocente, $this->otraLicenciatura, [
        'nombre_actividad' => 'Actividad secreta de B',
        'objetivo' => 'Objetivo secreto de B',
        'observaciones_admin' => 'Observación secreta de B',
    ]);
    // Folio que el sistema mostraría si la docente pudiera ver esa reserva.
    $folioAjeno = $ajena->refresh()->folio ?? Reserva::folioPara($ajena->id);
    expect($folioAjeno)->not->toBe('');
    $reserva = ($this->solicitud)();

    $props = ($this->pagina)($reserva, $this->docente);

    expect($props['traslapes'])->toBe([
        "Laboratorio el {$this->fecha} de 10:00 a 12:00 choca con otra reserva autorizada (09:00 a 11:00).",
    ]);

    // Ni en los mensajes ni en ninguna otra parte de la respuesta aparecen datos de la reserva ajena.
    $mensajes = implode(' ', $props['traslapes']);
    $respuesta = json_encode($props, JSON_UNESCAPED_UNICODE);
    foreach ([$folioAjeno, 'Beatriz', 'Privada', 'Actividad secreta de B', 'Objetivo secreto de B', 'Observación secreta de B', 'Licenciatura privada de B'] as $dato) {
        expect($mensajes)->not->toContain($dato)
            ->and($respuesta)->not->toContain($dato);
    }
});

test('a request without clashes gets an empty list', function () {
    ($this->autorizada)($this->docente, $this->licenciatura);
    $libre = ($this->reserva)($this->docente, $this->licenciatura, E::PENDIENTE_COORDINADOR, ['hora_inicio' => '11:00:00', 'hora_fin' => '12:00:00']);

    expect(($this->pagina)($libre, $this->docente)['traslapes'])->toBe([]);
});

test('with several blocks only the block that clashes is reported', function () {
    ($this->autorizada)($this->docente, $this->licenciatura);
    $reserva = Reserva::factory()->enEstado(E::CORRECCION)
        ->conDetalle(['espacio_id' => $this->laboratorio->id, 'fecha' => $this->manana, 'hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00'])
        ->conDetalle(['espacio_id' => $this->aula->id, 'fecha' => $this->manana, 'hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00'])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);

    $traslapes = ($this->pagina)($reserva, $this->docente)['traslapes'];

    expect($traslapes)->toHaveCount(1)
        ->and($traslapes[0])->toStartWith("Laboratorio el {$this->fecha} de 10:00 a 12:00");
});

test('the creator sees the clashes while the request is in progress', function (int $estado) {
    ($this->autorizada)($this->docente, $this->licenciatura);

    expect(($this->pagina)(($this->solicitud)($estado), $this->docente)['traslapes'])->toHaveCount(1);
})->with([
    'draft' => [E::BORRADOR],
    'correction' => [E::CORRECCION],
    'waiting for the coordinator' => [E::PENDIENTE_COORDINADOR],
    'waiting for administration' => [E::PENDIENTE_ADMIN],
]);

test('the creator gets no clash notice once the request is decided', function (int $estado) {
    ($this->autorizada)($this->docente, $this->licenciatura);

    expect(($this->pagina)(($this->solicitud)($estado), $this->docente)['traslapes'])->toBe([]);
})->with([
    'authorized' => [E::AUTORIZADA],
    'denied' => [E::DENEGADA],
]);

test('the coordinator reviewing and administration authorizing keep their current messages', function (string $quien, int $estado) {
    ($this->autorizada)($this->docente, $this->licenciatura);
    $reserva = ($this->solicitud)($estado);
    $esperado = app(W::class)->traslapes($reserva);

    expect($esperado)->toHaveCount(1)
        ->and(($this->pagina)($reserva, $this->{$quien})['traslapes'])->toBe($esperado);
})->with([
    'coordinator reviewing' => ['coordinador', E::PENDIENTE_COORDINADOR],
    'administration authorizing' => ['admin', E::PENDIENTE_ADMIN],
]);

test('whoever cannot view the request still gets 403', function () {
    $reserva = ($this->solicitud)();

    $this->actingAs($this->otraDocente)->get(route('reservas.show', $reserva))->assertForbidden();
});

test('clashes with requests still pending are not shown in this box', function () {
    ($this->reserva)($this->otraDocente, $this->otraLicenciatura, E::PENDIENTE_ADMIN, ['hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00']);
    $reserva = ($this->solicitud)();

    expect(($this->pagina)($reserva, $this->docente)['traslapes'])->toBe([]);
});

test('the creator\'s query never locks rows', function () {
    ($this->autorizada)($this->docente, $this->licenciatura);
    $reserva = ($this->solicitud)();

    // SQLite ignora FOR UPDATE, así que se comprueba en la llamada: ni bloqueo ni pendientes.
    $disponibilidad = Mockery::mock(DisponibilidadEspacioService::class)->makePartial();
    $disponibilidad->shouldReceive('choques')
        ->withArgs(fn (...$args): bool => ($args[5] ?? false) === false && ($args[6] ?? false) === false)
        ->atLeast()->once()
        ->passthru();
    $this->app->instance(DisponibilidadEspacioService::class, $disponibilidad);

    $sql = [];
    DB::listen(function ($consulta) use (&$sql) {
        $sql[] = $consulta->sql;
    });

    expect(($this->pagina)($reserva, $this->docente)['traslapes'])->toHaveCount(1)
        ->and(collect($sql)->filter(fn (string $s): bool => stripos($s, 'for update') !== false)->all())->toBe([]);
});

test('viewing the detail writes nothing and keeps history and notifications', function () {
    ($this->autorizada)($this->docente, $this->licenciatura);
    $reserva = ($this->solicitud)();
    $reserva->historial()->create(['usuario_id' => $this->docente->id, 'accion' => HistorialReserva::ENVIADA_COORDINADOR]);
    Notificacion::query()->create(['usuario_id' => $this->docente->id, 'reserva_id' => $reserva->id, 'titulo' => 'Aviso', 'mensaje' => 'Prueba', 'tipo' => 'info', 'leida' => false]);
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

    ($this->pagina)($reserva, $this->docente);

    expect($escrituras)->toBe([])
        ->and($foto())->toBe($antes);
});
