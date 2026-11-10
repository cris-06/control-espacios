<?php

use App\Exceptions\TransicionInvalida;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->workflow = app(W::class);

    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();

    $this->nueva = fn (int $estado = E::BORRADOR, ?User $creador = null, bool $conDetalle = true, array $detalle = []) => Reserva::factory()
        ->enEstado($estado)
        ->when($conDetalle, fn ($f) => $f->conDetalle($detalle))
        ->create([
            'usuario_creador_id' => ($creador ?? $this->docente)->id,
            'licenciatura_id' => $this->licenciatura->id,
        ]);
});

/**
 * Errores de validación agrupados: las claves reserva.N se juntan en 'reserva'.
 */
function errores(Closure $accion): array
{
    try {
        $accion();
    } catch (ValidationException $e) {
        $agrupados = [];

        foreach ($e->errors() as $clave => $mensajes) {
            $base = str_starts_with($clave, 'reserva.') ? 'reserva' : $clave;
            $agrupados[$base] = [...($agrupados[$base] ?? []), ...$mensajes];
        }

        return $agrupados;
    }

    throw new RuntimeException('Se esperaba una ValidationException.');
}

// --- 1 / 2: envío del docente ---

test('a teacher sends a draft to their coordinator, even incomplete', function () {
    $reserva = ($this->nueva)(conDetalle: false);
    $reserva->forceFill(['nombre_actividad' => null, 'objetivo' => ''])->save();

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR, 'Va sin detalles, lo completa coordinación');

    $reserva->refresh();
    expect($reserva->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and($reserva->coordinador_id)->toBe($this->coordinador->id)
        ->and($reserva->observaciones_admin)->toBeNull()
        ->and($reserva->historial()->latest('id')->first())
        ->accion->toBe(HistorialReserva::ENVIADA_COORDINADOR)
        ->comentario->toBe('Va sin detalles, lo completa coordinación');
});

test('a teacher resends after a correction', function () {
    $reserva = ($this->nueva)(E::CORRECCION);

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('sending is blocked when the program has no active coordinator', function () {
    $this->coordinador->update(['estatus' => 'inactivo']);
    $reserva = ($this->nueva)();

    expect(fn () => $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR))
        ->toThrow(AuthorizationException::class, 'no tiene un coordinador asignado');

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR);
});

test('sending is blocked when the teacher is not in the program', function () {
    $this->docente->licenciaturas()->detach();
    $reserva = ($this->nueva)();

    expect(fn () => $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR))
        ->toThrow(AuthorizationException::class, 'No estás asignado a la licenciatura');
});

test('nobody else sends a teacher\'s reservation', function () {
    $reserva = ($this->nueva)();

    expect(fn () => $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR))
        ->toThrow(AuthorizationException::class);
});

// --- 1b / 2b: el coordinador creador envía directo a administración ---

test('a coordinator\'s own reservation goes straight to administration', function () {
    $reserva = ($this->nueva)(creador: $this->coordinador);

    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);

    $reserva->refresh();
    expect($reserva->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($reserva->coordinador_id)->toBe($this->coordinador->id)
        ->and($reserva->historial()->latest('id')->value('accion'))->toBe(HistorialReserva::ENVIADA_ADMIN);
});

test('a coordinator\'s own reservation must be complete to go to administration', function () {
    $reserva = ($this->nueva)(creador: $this->coordinador, conDetalle: false);

    $errores = errores(fn () => $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR));

    expect($errores['reserva'])->toContain('Agrega al menos un espacio con fecha y horario.')
        ->and($reserva->refresh()->estado_id)->toBe(E::BORRADOR);
});

// --- 3 / 4: revisión del coordinador ---

test('the coordinator approves and sends to administration', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);

    $avisos = $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);

    expect($avisos)->toBe([])
        ->and($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($reserva->historial()->latest('id')->value('accion'))->toBe(HistorialReserva::APROBADA_COORDINADOR);
});

test('strict validations are required when the coordinator sends to administration', function () {
    $espacio = Espacio::factory()->create(['capacidad' => 10, 'estatus' => 'mantenimiento']);
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR, detalle: [
        'espacio_id' => $espacio->id,
        'fecha' => Date::yesterday()->toDateString(),
        'cantidad_alumnos' => 30,
    ]);
    $reserva->forceFill(['nombre_actividad' => '', 'objetivo' => ''])->save();

    $errores = errores(fn () => $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR))['reserva'];

    expect($errores)->toContain('Falta el nombre de la actividad.')
        ->toContain('Falta el objetivo de la actividad.')
        ->toContain('Espacio 1: el espacio no está disponible (inactivo o en mantenimiento).')
        ->toContain('Espacio 1: la fecha ya pasó.')
        ->and(collect($errores)->contains(fn ($e) => str_contains($e, 'superan la capacidad')))->toBeTrue()
        ->and($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('the coordinator asks for a correction with a mandatory observation', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);

    expect(errores(fn () => $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, '   ')))
        ->toHaveKey('observacion');

    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el material requerido');

    $reserva->refresh();
    expect($reserva->estado_id)->toBe(E::CORRECCION)
        ->and($reserva->observaciones_admin)->toBe('Falta el material requerido')
        ->and($reserva->historial()->latest('id')->first())
        ->accion->toBe(HistorialReserva::CORRECCION_SOLICITADA)
        ->comentario->toBe('Falta el material requerido');
});

test('a coordinator of another program cannot review', function () {
    $ajeno = User::factory()->rol('coordinador')->create();
    $ajeno->licenciaturas()->attach(Licenciatura::factory()->create());
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);

    expect(fn () => $this->workflow->ejecutar($reserva, $ajeno, W::APROBAR))->toThrow(AuthorizationException::class);
});

// --- 5 / 6 / 7: decisión de administración ---

test('authorizing requires an observation and authorizes every detail', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_ADMIN);

    expect(errores(fn () => $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR)))->toHaveKey('observacion');

    $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Autorizada para el evento');

    $reserva->refresh();
    expect($reserva->estado_id)->toBe(E::AUTORIZADA)
        ->and($reserva->observaciones_admin)->toBe('Autorizada para el evento')
        ->and($reserva->detalles()->pluck('estado_detalle')->unique()->all())->toBe([ReservaDetalle::AUTORIZADA]);
});

test('denying requires an observation and denies every detail', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_ADMIN);

    $this->workflow->ejecutar($reserva, $this->admin, W::DENEGAR, 'El auditorio está comprometido');

    $reserva->refresh();
    expect($reserva->estado_id)->toBe(E::DENEGADA)
        ->and($reserva->detalles()->pluck('estado_detalle')->unique()->all())->toBe([ReservaDetalle::DENEGADA])
        ->and($reserva->historial()->latest('id')->value('accion'))->toBe(HistorialReserva::DENEGADA);
});

test('administration can send back for correction and the resend goes to the coordinator', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_ADMIN);

    $this->workflow->ejecutar($reserva, $this->admin, W::SOLICITAR_CORRECCION, 'Cambia el horario');
    expect($reserva->refresh()->estado_id)->toBe(E::CORRECCION);

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('authorizing is blocked by an overlap with an authorized reservation', function () {
    $espacio = Espacio::factory()->create();
    $fecha = Date::tomorrow()->toDateString();

    ($this->nueva)(E::AUTORIZADA, detalle: ['espacio_id' => $espacio->id, 'fecha' => $fecha, 'hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00', 'estado_detalle' => ReservaDetalle::AUTORIZADA]);
    $reserva = ($this->nueva)(E::PENDIENTE_ADMIN, detalle: ['espacio_id' => $espacio->id, 'fecha' => $fecha, 'hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00']);

    $errores = errores(fn () => $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Ok'));

    expect($errores['reserva'][0])->toContain('choca con la reserva autorizada')
        ->and($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN);
});

test('back to back reservations do not overlap', function () {
    $espacio = Espacio::factory()->create();
    $fecha = Date::tomorrow()->toDateString();

    ($this->nueva)(E::AUTORIZADA, detalle: ['espacio_id' => $espacio->id, 'fecha' => $fecha, 'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00', 'estado_detalle' => ReservaDetalle::AUTORIZADA]);
    $reserva = ($this->nueva)(E::PENDIENTE_ADMIN, detalle: ['espacio_id' => $espacio->id, 'fecha' => $fecha, 'hora_inicio' => '10:00:00', 'hora_fin' => '12:00:00']);

    $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Ok');

    expect($reserva->refresh()->estado_id)->toBe(E::AUTORIZADA);
});

test('the coordinator is warned, not blocked, about overlaps', function () {
    $espacio = Espacio::factory()->create();
    $fecha = Date::tomorrow()->toDateString();

    ($this->nueva)(E::AUTORIZADA, detalle: ['espacio_id' => $espacio->id, 'fecha' => $fecha, 'estado_detalle' => ReservaDetalle::AUTORIZADA]);
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR, detalle: ['espacio_id' => $espacio->id, 'fecha' => $fecha]);

    $avisos = $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);

    expect($avisos)->toHaveCount(1)
        ->and($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN);
});

// --- Transiciones inválidas y permisos ---

test('a teacher never approves, authorizes or denies', function (int $estado, string $accion) {
    $reserva = ($this->nueva)($estado);

    expect(fn () => $this->workflow->ejecutar($reserva, $this->docente, $accion, 'x'))
        ->toThrow(AuthorizationException::class);

    expect($reserva->refresh()->estado_id)->toBe($estado);
})->with([
    'aprobar' => [E::PENDIENTE_COORDINADOR, W::APROBAR],
    'pedir corrección' => [E::PENDIENTE_COORDINADOR, W::SOLICITAR_CORRECCION],
    'autorizar' => [E::PENDIENTE_ADMIN, W::AUTORIZAR],
    'denegar' => [E::PENDIENTE_ADMIN, W::DENEGAR],
]);

test('transitions outside the table are rejected', function (int $estado, string $accion) {
    $reserva = ($this->nueva)($estado);

    expect(fn () => $this->workflow->ejecutar($reserva, $this->admin, $accion, 'x'))
        ->toThrow(TransicionInvalida::class);

    expect($reserva->refresh()->estado_id)->toBe($estado)
        ->and($reserva->historial()->count())->toBe(0);
})->with([
    'autorizar un borrador' => [E::BORRADOR, W::AUTORIZAR],
    'aprobar algo pendiente de admin' => [E::PENDIENTE_ADMIN, W::APROBAR],
    'denegar algo pendiente de coordinador' => [E::PENDIENTE_COORDINADOR, W::DENEGAR],
    'reabrir una autorizada' => [E::AUTORIZADA, W::SOLICITAR_CORRECCION],
    'autorizar una denegada' => [E::DENEGADA, W::AUTORIZAR],
    'enviar una autorizada' => [E::AUTORIZADA, W::ENVIAR],
]);

test('the available actions depend on role and state', function () {
    $paraCoordinador = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $paraAdmin = ($this->nueva)(E::PENDIENTE_ADMIN);

    expect(collect($this->workflow->disponibles(($this->nueva)(), $this->docente))->pluck('etiqueta')->all())->toBe(['Enviar a coordinación'])
        ->and(collect($this->workflow->disponibles($paraCoordinador, $this->coordinador))->pluck('accion')->all())->toBe([W::APROBAR, W::SOLICITAR_CORRECCION])
        ->and(collect($this->workflow->disponibles($paraAdmin, $this->admin))->pluck('accion')->all())->toBe([W::SOLICITAR_CORRECCION, W::AUTORIZAR, W::DENEGAR])
        ->and($this->workflow->disponibles($paraAdmin, $this->docente))->toBe([]);
});
