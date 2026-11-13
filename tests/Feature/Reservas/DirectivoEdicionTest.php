<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva as H;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\User;
use App\Services\DisponibilidadEspacioService;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Edición directa del directivo (admin / superadmin) en solicitudes pendientes de administración:
 * sin el mínimo de 10 días (pero nunca en fechas pasadas), con las mismas comprobaciones que la
 * autorización, bajo bloqueo, sin cambios parciales y registrada como 'editada_admin'.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas', 'siglas' => 'LSC']);
    $this->derecho = Licenciatura::factory()->create(['nombre' => 'Derecho']);
    $this->inactiva = Licenciatura::factory()->create(['nombre' => 'Inactiva', 'estatus' => 'inactiva']);
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->lsc->id);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->lsc->id);
    $this->coordDerecho = User::factory()->rol('coordinador')->create();
    $this->coordDerecho->licenciaturas()->attach($this->derecho->id);
    $this->admin = User::factory()->rol('admin')->create(['nombre' => 'Alma', 'apellido_paterno' => 'Directiva']);
    $this->superadmin = User::factory()->rol('superadmin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->auditorio = Espacio::factory()->create(['nombre' => 'Auditorio', 'capacidad' => 100]);
    $this->aula = Espacio::factory()->create(['nombre' => 'Aula', 'capacidad' => 30]);
    $this->proyector = Requerimiento::create(['nombre' => 'Proyector', 'estatus' => 'activo']);
    $this->auditorio->requerimientos()->attach($this->proyector->id, ['cantidad_disponible' => 2]);
    $this->workflow = app(W::class);

    $this->datos = fn (array $bloques, array $extra = []): array => [
        'licenciatura_id' => $this->lsc->id, 'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Jornada', 'objetivo' => 'Objetivo de la jornada', 'detalles' => $bloques, ...$extra,
    ];
    $this->bloque = fn (string $fecha, array $extra = []): array => [
        'espacio_id' => $this->auditorio->id, 'fecha' => $fecha, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 40, ...$extra,
    ];
    // Solicitud de la docente llevada hasta pendiente_admin por el flujo real.
    $this->pendienteAdmin = function (?User $creador = null, string $fecha = '2026-12-15'): Reserva {
        $this->travelTo(Date::parse('2026-11-12 09:00:00'));
        $this->actingAs($creador ?? $this->docente)->post(route('reservas.store'), ($this->datos)([($this->bloque)($fecha)]))->assertSessionHasNoErrors();
        $r = Reserva::query()->latest('id')->firstOrFail();
        $this->workflow->ejecutar($r, $creador ?? $this->docente, W::ENVIAR);
        $this->workflow->ejecutar($r->refresh(), $this->coordinador, W::APROBAR);

        return $r->refresh();
    };
    $this->editar = fn (User $quien, Reserva $r, array $bloques, array $extra = []) => $this->actingAs($quien)
        ->put(route('reservas.update', $r), ($this->datos)($bloques, $extra));
    $this->detalleId = fn (Reserva $r) => $r->detalles()->value('id');
    // Resumen con valores simples (sin objetos) para comparar antes y después.
    $this->foto = fn (Reserva $r) => [
        'reserva' => [...$r->refresh()->only(['estado_id', 'licenciatura_id', 'folio', 'folio_institucional', 'objetivo']), 'fecha_entrega_formato' => $r->fecha_entrega_formato?->toDateString()],
        'creacion' => $r->fecha_creacion?->toDateTimeString(),
        'detalles' => DB::table('reserva_detalles')->where('reserva_id', $r->id)->orderBy('id')->get(['espacio_id', 'fecha', 'hora_inicio', 'hora_fin', 'cantidad_alumnos'])->map(fn ($d) => (array) $d)->all(),
        'historial' => $r->historial()->count(),
        'notificaciones' => Notificacion::query()->count(),
    ];
});

// --- Permisos ---

test('admin and superadmin can open the edit form of a request pending their decision', function () {
    $r = ($this->pendienteAdmin)();

    foreach ([$this->admin, $this->superadmin] as $directivo) {
        $this->actingAs($directivo)->get(route('reservas.edit', $r))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('reservas/Form')
                ->where('anticipacion.excepcion_directivo', true)
                ->where('anticipacion.fecha_minima', '2026-11-12')
                // Solo la licenciatura actual: se muestra de solo lectura.
                ->where('opciones.licenciaturas', fn ($lics) => collect($lics)->pluck('id')->all() === [$this->lsc->id]));
    }
});

test('the director gets EDIT REQUEST in the detail, but not in the list', function () {
    $r = ($this->pendienteAdmin)();

    $this->actingAs($this->admin)->get(route('reservas.show', $r))
        ->assertInertia(fn (Assert $p) => $p->where('puede.editar', true));
    $this->actingAs($this->admin)->get(route('solicitudes.index'))
        ->assertInertia(fn (Assert $p) => $p->where('solicitudes', fn ($filas) => collect($filas)->firstWhere('id', $r->id)['acciones']['editar'] === false));
});

test('the director cannot edit in any other state', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([($this->bloque)('2026-12-15')]));
    $borrador = Reserva::query()->latest('id')->firstOrFail();

    $enCoordinacion = ($this->pendienteAdmin)();
    $enCoordinacion->forceFill(['estado_id' => E::PENDIENTE_COORDINADOR])->save();
    $enCorreccion = ($this->pendienteAdmin)();
    $this->workflow->ejecutar($enCorreccion, $this->admin, W::SOLICITAR_CORRECCION, 'Revisar');
    $autorizada = ($this->pendienteAdmin)(fecha: '2026-12-20');
    $this->workflow->ejecutar($autorizada, $this->admin, W::AUTORIZAR, 'Listo');
    $denegada = ($this->pendienteAdmin)(fecha: '2026-12-21');
    $this->workflow->ejecutar($denegada, $this->admin, W::DENEGAR, 'No procede');

    foreach ([$borrador, $enCoordinacion, $enCorreccion->refresh(), $autorizada->refresh(), $denegada->refresh()] as $r) {
        $antes = ($this->foto)($r);
        $this->actingAs($this->admin)->get(route('reservas.edit', $r))->assertForbidden();
        ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])])->assertForbidden();
        expect(($this->foto)($r))->toBe($antes);
    }
});

test('teachers and coordinators keep their current edit permissions', function () {
    $r = ($this->pendienteAdmin)();

    $this->actingAs($this->docente)->get(route('reservas.edit', $r))->assertForbidden();
    $this->actingAs($this->coordinador)->get(route('reservas.edit', $r))->assertForbidden();
    $this->actingAs($this->coordDerecho)->get(route('reservas.edit', $r))->assertForbidden();
});

// --- Excepción de los 10 días ---

test('the director can move a date to five days ahead and the request stays pending his decision', function () {
    $r = ($this->pendienteAdmin)();
    $antes = ($this->foto)($r);

    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])], ['motivo_edicion' => 'Disponibilidad del auditorio'])
        ->assertSessionHasNoErrors()->assertRedirect(route('reservas.show', $r));

    $r->refresh();
    $ultimo = $r->historial()->reorder()->latest('id')->firstOrFail();

    expect($r->detalles()->value('fecha')->toDateString())->toBe('2026-11-17')
        ->and($r->estado_id)->toBe(E::PENDIENTE_ADMIN)
        // Fechas automáticas y folios intactos.
        ->and($r->fecha_creacion?->toDateTimeString())->toBe($antes['creacion'])
        ->and($r->fecha_entrega_formato?->toDateString())->toBe($antes['reserva']['fecha_entrega_formato'])
        ->and($r->folio)->toBe($antes['reserva']['folio'])
        ->and($r->folio_institucional)->toBe($antes['reserva']['folio_institucional'])
        // Historial: editada_admin, con quién, cuándo y el motivo; no se atribuye a coordinación.
        ->and($ultimo->accion)->toBe(H::EDITADA_ADMIN)
        ->and($ultimo->usuario_id)->toBe($this->admin->id)
        ->and($ultimo->fecha?->toDateTimeString())->toBe('2026-11-12 09:00:00')
        ->and($ultimo->comentario)->toContain('espacios y horarios')->toContain('Motivo: Disponibilidad del auditorio')
        ->and($r->historial()->where('accion', H::EDITADA_COORDINADOR)->exists())->toBeFalse()
        // Sin notificaciones nuevas ni otros movimientos.
        ->and(Notificacion::query()->count())->toBe($antes['notificaciones'])
        ->and($r->historial()->count())->toBe($antes['historial'] + 1);
});

test('the exception cannot be switched on from the browser by teachers or coordinators', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([($this->bloque)('2026-12-15')]));
    $r = Reserva::query()->latest('id')->firstOrFail();
    $this->workflow->ejecutar($r, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($r->refresh(), $this->coordinador, W::SOLICITAR_CORRECCION, 'Cambia la fecha');

    // Docente en corrección: aunque mande los campos «de directivo», sigue el mínimo de 10 días.
    ($this->editar)($this->docente, $r->refresh(), [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])], ['excepcion_directivo' => 1, 'motivo_edicion' => 'x'])
        ->assertSessionHasErrors('detalles.0.fecha');
    expect($r->detalles()->value('fecha')->toDateString())->toBe('2026-12-15');

    // Coordinación en pendiente_coordinador: igual.
    $this->workflow->ejecutar($r->refresh(), $this->docente, W::ENVIAR);
    ($this->editar)($this->coordinador, $r->refresh(), [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])], ['excepcion_directivo' => 1])
        ->assertSessionHasErrors('detalles.0.fecha');
    expect($r->detalles()->value('fecha')->toDateString())->toBe('2026-12-15');
});

test('after a director edit, a later teacher change must meet the 10 days again', function () {
    $r = ($this->pendienteAdmin)();
    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])])->assertSessionHasNoErrors();
    $this->workflow->ejecutar($r->refresh(), $this->admin, W::SOLICITAR_CORRECCION, 'Ajusta el horario');

    // Conservar el bloque que dejó el directivo es válido…
    ($this->editar)($this->docente, $r->refresh(), [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])])->assertSessionHasNoErrors();
    // …pero cambiarlo exige de nuevo los 10 días.
    ($this->editar)($this->docente, $r->refresh(), [($this->bloque)('2026-11-18', ['id' => ($this->detalleId)($r)])])->assertSessionHasErrors('detalles.0.fecha');
    expect($r->detalles()->value('fecha')->toDateString())->toBe('2026-11-17');
});

test('past dates remain forbidden for the director', function () {
    $r = ($this->pendienteAdmin)();
    $antes = ($this->foto)($r);

    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-11', ['id' => ($this->detalleId)($r)])])->assertSessionHasErrors('detalles.0.fecha');
    ($this->editar)($this->admin, $r, [($this->bloque)('2026-12-15', ['id' => ($this->detalleId)($r)]), ($this->bloque)('2026-11-10', ['espacio_id' => $this->aula->id, 'cantidad_alumnos' => 20])])
        ->assertSessionHasErrors('detalles.1.fecha');

    expect(($this->foto)($r))->toBe($antes);
});

// --- Validaciones conservadas, sin cambios parciales ---

test('the director cannot create an overlap with an authorized reservation', function () {
    // Otra solicitud que el directivo movió al 17/11 y autorizó.
    $autorizada = ($this->pendienteAdmin)();
    ($this->editar)($this->admin, $autorizada, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($autorizada)])])->assertSessionHasNoErrors();
    $this->workflow->ejecutar($autorizada->refresh(), $this->admin, W::AUTORIZAR, 'Autorizada');
    $r = ($this->pendienteAdmin)();
    $antes = ($this->foto)($r);

    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r), 'hora_inicio' => '11:00', 'hora_fin' => '13:00'])])
        ->assertSessionHasErrors('reserva.0');

    expect(($this->foto)($r))->toBe($antes)
        ->and(session('errors')->first('reserva.0'))->toContain('choca con la reserva autorizada');
});

test('capacity, schedule and requirements are still validated', function () {
    $r = ($this->pendienteAdmin)();
    $antes = ($this->foto)($r);
    $id = ($this->detalleId)($r);

    // Capacidad (100) superada.
    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => $id, 'cantidad_alumnos' => 150])])->assertSessionHasErrors('reserva.0');
    // Hora final anterior a la inicial.
    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => $id, 'hora_inicio' => '12:00', 'hora_fin' => '10:00'])])->assertSessionHasErrors('detalles.0.hora_fin');
    // Más requerimientos de los disponibles (2).
    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => $id, 'requerimientos' => [['requerimiento_id' => $this->proyector->id, 'cantidad' => 5]]])])->assertSessionHasErrors('detalles.0.requerimientos.0');
    // Dos bloques que se pisan dentro de la misma reserva.
    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => $id]), ($this->bloque)('2026-11-17', ['hora_inicio' => '11:00', 'hora_fin' => '13:00'])])->assertSessionHasErrors('reserva.0');

    expect(($this->foto)($r))->toBe($antes);
});

test('the edit form shows the program read-only for the director and keeps the selector for others', function () {
    $formulario = (string) file_get_contents(resource_path('js/pages/reservas/Form.vue'));

    expect($formulario)->toContain('<template v-if="anticipacion.excepcion_directivo">')
        ->toContain('aria-readonly="true"')
        ->toContain('El directivo no puede cambiar la licenciatura')
        // El selector sigue para docentes y coordinadores (v-else).
        ->toMatch('/<CampoSelect\s+v-else\s+id="licenciatura_id"/');

    // Docente y coordinación siguen recibiendo sus licenciaturas para elegir.
    $this->actingAs($this->docente)->get(route('reservas.create'))
        ->assertInertia(fn (Assert $p) => $p->where('anticipacion.excepcion_directivo', false)
            ->where('opciones.licenciaturas', fn ($lics) => collect($lics)->pluck('id')->all() === [$this->lsc->id]));
});

test('a manipulated request that changes the program is rejected without writing anything', function () {
    $r = ($this->pendienteAdmin)();
    $coordinador = $r->coordinador_id;
    $antes = ($this->foto)($r);
    $bloque = [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])];

    foreach ([$this->derecho, $this->inactiva] as $otra) {
        foreach (['put', 'patch'] as $metodo) {
            $this->actingAs($this->admin)->{$metodo}(route('reservas.update', $r), ($this->datos)($bloque, ['licenciatura_id' => $otra->id]))
                ->assertSessionHasErrors(['licenciatura_id' => 'El directivo no puede cambiar la licenciatura de la solicitud.']);
        }
    }

    // Ni siquiera la fecha a cinco días se guardó: el rechazo es completo.
    expect(($this->foto)($r))->toBe($antes)
        ->and($r->refresh()->coordinador_id)->toBe($coordinador);
});

test('the service also refuses a program change by the director, under lock and before writing', function () {
    $r = ($this->pendienteAdmin)();
    $antes = ($this->foto)($r);

    expect(fn () => app(ReservaService::class)->actualizar($r, $this->admin, [...($this->datos)([]), 'licenciatura_id' => $this->derecho->id], [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])]))
        ->toThrow(ValidationException::class, 'El directivo no puede cambiar la licenciatura de la solicitud.');
    expect(($this->foto)($r))->toBe($antes);
});

test('a valid director edit keeps the original program and coordinator', function () {
    $r = ($this->pendienteAdmin)();
    $coordinador = $r->coordinador_id;

    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r), 'cantidad_alumnos' => 35])], ['objetivo' => 'Objetivo ajustado por el directivo'])
        ->assertSessionHasNoErrors();

    expect($r->refresh()->licenciatura_id)->toBe($this->lsc->id)
        ->and($r->coordinador_id)->toBe($coordinador)
        ->and($r->coordinador_id)->toBe($this->coordinador->id)
        ->and($r->objetivo)->toBe('Objetivo ajustado por el directivo')
        ->and($r->detalles()->value('cantidad_alumnos'))->toBe(35)
        ->and($r->historial()->reorder()->latest('id')->value('accion'))->toBe(H::EDITADA_ADMIN);
});

// --- Concurrencia ---

test('the edit re-checks state and permission under lock: a stale copy cannot edit an authorized reservation', function () {
    $r = ($this->pendienteAdmin)();
    $copiaVieja = Reserva::query()->findOrFail($r->id);
    $this->workflow->ejecutar($r, $this->admin, W::AUTORIZAR, 'Autorizada mientras tanto');
    $antes = ($this->foto)($r);

    expect(fn () => app(ReservaService::class)->actualizar($copiaVieja, $this->admin, ($this->datos)([]), [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])]))
        ->toThrow(AuthorizationException::class);
    expect(($this->foto)($r))->toBe($antes);
});

test('the director edit locks the spaces after the reservation, like the authorization', function () {
    $r = ($this->pendienteAdmin)();
    $espia = Mockery::spy(app(DisponibilidadEspacioService::class))->makePartial();
    app()->instance(DisponibilidadEspacioService::class, $espia);

    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r), 'espacio_id' => $this->aula->id, 'cantidad_alumnos' => 20])])
        ->assertSessionHasNoErrors();

    $espia->shouldHaveReceived('bloquearEspacios')->once()->with([$this->aula->id]);
});

// --- Las acciones del directivo siguen funcionando después de editar ---

test('after editing, the director can still authorize, reject or send back', function () {
    $r = ($this->pendienteAdmin)();
    ($this->editar)($this->admin, $r, [($this->bloque)('2026-11-17', ['id' => ($this->detalleId)($r)])])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('reservas.transicion', $r), ['accion' => W::AUTORIZAR, 'observacion' => 'Autorizada'])
        ->assertRedirect(route('reservas.show', $r));

    expect($r->refresh()->estado_id)->toBe(E::AUTORIZADA)
        ->and($r->historial()->pluck('accion')->all())->toContain(H::EDITADA_ADMIN, H::AUTORIZADA);
    $this->actingAs($this->admin)->get(route('reservas.edit', $r))->assertForbidden();
});

test('the historial accepts editada_admin and keeps the previous values (migration)', function () {
    $r = ($this->pendienteAdmin)();

    foreach ([H::CREADA, H::EDITADA_COORDINADOR, H::EDITADA_ADMIN] as $accion) {
        $r->historial()->create(['usuario_id' => $this->admin->id, 'accion' => $accion]);
    }

    expect($r->historial()->whereIn('accion', [H::EDITADA_COORDINADOR, H::EDITADA_ADMIN])->count())->toBe(2);
});
