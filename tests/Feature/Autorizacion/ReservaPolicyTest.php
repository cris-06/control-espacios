<?php

use App\Models\EstadoReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Reserva en memoria: la policy solo lee atributos, así que no hace falta la tabla
 * `reservas` (que aún no existe en las migraciones de los tests).
 */
function reserva(User $creador, Licenciatura $licenciatura, int $estado): Reserva
{
    return (new Reserva)->forceFill([
        'id' => 1,
        'usuario_creador_id' => $creador->id,
        'licenciatura_id' => $licenciatura->id,
        'estado_id' => $estado,
    ]);
}

beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->otraLicenciatura = Licenciatura::factory()->create();

    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);

    $this->otroDocente = User::factory()->rol('docente')->create();
    $this->otroDocente->licenciaturas()->attach($this->licenciatura);

    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);

    $this->coordinadorAjeno = User::factory()->rol('coordinador')->create();
    $this->coordinadorAjeno->licenciaturas()->attach($this->otraLicenciatura);

    $this->admin = User::factory()->rol('admin')->create();
    $this->superadmin = User::factory()->rol('superadmin')->create();
});

test('only teachers and coordinators create reservations', function () {
    expect($this->docente->can('create', Reserva::class))->toBeTrue()
        ->and($this->coordinador->can('create', Reserva::class))->toBeTrue()
        ->and($this->admin->can('create', Reserva::class))->toBeFalse()
        ->and($this->superadmin->can('create', Reserva::class))->toBeFalse();
});

test('a teacher sees only their own reservations', function () {
    $propia = reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_ADMIN);
    $ajena = reserva($this->otroDocente, $this->licenciatura, EstadoReserva::PENDIENTE_ADMIN);

    expect($this->docente->can('view', $propia))->toBeTrue()
        ->and($this->docente->can('view', $ajena))->toBeFalse();
});

test('a coordinator sees sent reservations of their degree program only', function () {
    $enviada = reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR);
    $borrador = reserva($this->docente, $this->licenciatura, EstadoReserva::BORRADOR);

    expect($this->coordinador->can('view', $enviada))->toBeTrue()
        ->and($this->coordinador->can('view', $borrador))->toBeFalse()
        ->and($this->coordinadorAjeno->can('view', $enviada))->toBeFalse();
});

test('administration sees every reservation', function () {
    $reserva = reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR);

    expect($this->admin->can('view', $reserva))->toBeTrue()
        ->and($this->superadmin->can('view', $reserva))->toBeTrue();
});

test('a teacher edits their reservation only in draft or correction', function (int $estado, bool $puede) {
    expect($this->docente->can('update', reserva($this->docente, $this->licenciatura, $estado)))->toBe($puede);
})->with([
    'borrador' => [EstadoReserva::BORRADOR, true],
    'corrección' => [EstadoReserva::CORRECCION, true],
    'pendiente_coordinador' => [EstadoReserva::PENDIENTE_COORDINADOR, false],
    'pendiente_admin' => [EstadoReserva::PENDIENTE_ADMIN, false],
    'autorizada' => [EstadoReserva::AUTORIZADA, false],
    'denegada' => [EstadoReserva::DENEGADA, false],
]);

test('a teacher cannot edit another teacher\'s reservation', function () {
    expect($this->docente->can('update', reserva($this->otroDocente, $this->licenciatura, EstadoReserva::BORRADOR)))->toBeFalse();
});

test('the coordinator completes a reservation of their program while it is pending with them', function () {
    expect($this->coordinador->can('update', reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR)))->toBeTrue()
        ->and($this->coordinador->can('update', reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_ADMIN)))->toBeFalse()
        ->and($this->coordinadorAjeno->can('update', reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR)))->toBeFalse();
});

test('a teacher sends their draft to coordination', function (int $estado) {
    expect($this->docente->can('enviar', reserva($this->docente, $this->licenciatura, $estado)))->toBeTrue();
})->with([EstadoReserva::BORRADOR, EstadoReserva::CORRECCION]);

test('sending is blocked with a clear message when the teacher is not in the program', function () {
    $sinLicenciatura = User::factory()->rol('docente')->create();
    $respuesta = $sinLicenciatura->can('enviar', reserva($sinLicenciatura, $this->licenciatura, EstadoReserva::BORRADOR));

    expect($respuesta)->toBeFalse();

    $detalle = Gate::forUser($sinLicenciatura)->inspect('enviar', reserva($sinLicenciatura, $this->licenciatura, EstadoReserva::BORRADOR));
    expect($detalle->message())->toContain('No estás asignado a la licenciatura');
});

test('sending is blocked with a clear message when the program has no coordinator', function () {
    $sinCoordinador = Licenciatura::factory()->create();
    $this->docente->licenciaturas()->attach($sinCoordinador);

    $detalle = Gate::forUser($this->docente)->inspect('enviar', reserva($this->docente, $sinCoordinador, EstadoReserva::BORRADOR));

    expect($detalle->allowed())->toBeFalse()
        ->and($detalle->message())->toContain('no tiene un coordinador asignado');
});

test('an inactive coordinator does not count as the program coordinator', function () {
    $sinCoordinadorActivo = Licenciatura::factory()->create();
    $this->docente->licenciaturas()->attach($sinCoordinadorActivo);
    User::factory()->rol('coordinador')->inactivo()->create()->licenciaturas()->attach($sinCoordinadorActivo);

    expect($this->docente->can('enviar', reserva($this->docente, $sinCoordinadorActivo, EstadoReserva::BORRADOR)))->toBeFalse();
});

test('a teacher cannot send someone else\'s reservation nor one already sent', function () {
    expect($this->docente->can('enviar', reserva($this->otroDocente, $this->licenciatura, EstadoReserva::BORRADOR)))->toBeFalse()
        ->and($this->docente->can('enviar', reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR)))->toBeFalse();
});

test('a teacher never reviews, authorizes or denies', function () {
    $propia = reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR);
    $paraAdmin = reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_ADMIN);

    expect($this->docente->can('revisar', $propia))->toBeFalse()
        ->and($this->docente->can('autorizar', $paraAdmin))->toBeFalse();
});

test('only the program coordinator reviews, and only while pending with coordination', function () {
    $pendiente = reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR);

    expect($this->coordinador->can('revisar', $pendiente))->toBeTrue()
        ->and($this->coordinadorAjeno->can('revisar', $pendiente))->toBeFalse()
        ->and($this->admin->can('revisar', $pendiente))->toBeFalse()
        ->and($this->coordinador->can('revisar', reserva($this->docente, $this->licenciatura, EstadoReserva::BORRADOR)))->toBeFalse();
});

test('only administration authorizes, and only while pending with administration', function () {
    $pendiente = reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_ADMIN);

    expect($this->admin->can('autorizar', $pendiente))->toBeTrue()
        ->and($this->superadmin->can('autorizar', $pendiente))->toBeTrue()
        ->and($this->coordinador->can('autorizar', $pendiente))->toBeFalse()
        ->and($this->admin->can('autorizar', reserva($this->docente, $this->licenciatura, EstadoReserva::PENDIENTE_COORDINADOR)))->toBeFalse();
});
