<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Formato 10, versión imprimible (GET reservas/{reserva}/imprimir). Solo lectura: la misma
 * política que el detalle decide quién la ve, muestra los datos reales de la reserva y su
 * situación, y nunca presenta como autorizada una solicitud que no lo está.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-11 10:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas Computacionales', 'siglas' => 'LSC']);
    $this->sinSiglas = Licenciatura::factory()->create(['nombre' => 'Psicología']);
    $this->otraLicenciatura = Licenciatura::factory()->create(['nombre' => 'Derecho']);

    $this->docente = User::factory()->rol('docente')->create(['nombre' => 'Ana', 'apellido_paterno' => 'Docente', 'apellido_materno' => 'Prueba']);
    $this->docente->licenciaturas()->attach([$this->lsc->id, $this->sinSiglas->id]);
    $this->coordinador = User::factory()->rol('coordinador')->create(['nombre' => 'Carlos', 'apellido_paterno' => 'Coordinador', 'apellido_materno' => 'Prueba']);
    $this->coordinador->licenciaturas()->attach([$this->lsc->id, $this->sinSiglas->id]);
    $this->admin = User::factory()->rol('admin')->create(['nombre' => 'Alma', 'apellido_paterno' => 'Admin', 'apellido_materno' => 'Prueba']);
    $this->otroDocente = User::factory()->rol('docente')->create();
    $this->otroDocente->licenciaturas()->attach($this->lsc->id);
    $this->coordinadorAjeno = User::factory()->rol('coordinador')->create();
    $this->coordinadorAjeno->licenciaturas()->attach($this->otraLicenciatura->id);

    $this->actividad = Actividad::factory()->create(['nombre' => 'Conferencia']);
    $this->auditorio = Espacio::factory()->create(['nombre' => 'Auditorio 1', 'capacidad' => 100]);
    $this->anexo = Espacio::factory()->create(['nombre' => 'Anexo', 'capacidad' => 40]);
    $this->proyector = Requerimiento::create(['nombre' => 'Proyector', 'estatus' => 'activo']);
    $this->sillas = Requerimiento::create(['nombre' => 'Sillas', 'estatus' => 'activo']);
    $this->auditorio->requerimientos()->attach($this->proyector->id, ['cantidad_disponible' => 5]);
    $this->anexo->requerimientos()->attach($this->sillas->id, ['cantidad_disponible' => 60]);
    $this->workflow = app(W::class);

    $this->bloqueAuditorio = [
        'espacio_id' => $this->auditorio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        'cantidad_alumnos' => 80, 'lugar_especifico' => 'Escenario principal',
        'descripcion_actividad' => "Protocolo de inicio.\nPrimera conferencia.",
        'material_requerido' => 'Mampara roja del CUV',
        'observaciones' => 'Invitados: Director de licenciaturas',
        'requerimientos' => [['requerimiento_id' => $this->proyector->id, 'cantidad' => 2]],
    ];
    $this->bloqueAnexo = [
        'espacio_id' => $this->anexo->id, 'fecha' => '2026-12-16', 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        'cantidad_alumnos' => 30, 'descripcion_actividad' => 'Taller de análisis de datos.',
        'requerimientos' => [['requerimiento_id' => $this->sillas->id, 'cantidad' => 30]],
    ];

    // Crea por la ruta del formulario, como lo haría la docente.
    $this->crear = function (Licenciatura $licenciatura, array $detalles, array $extra = []): Reserva {
        $this->actingAs($this->docente)->post(route('reservas.store'), [
            'licenciatura_id' => $licenciatura->id,
            'actividad_id' => $this->actividad->id,
            'nombre_actividad' => 'Jornada Académica',
            'objetivo' => 'Brindar un espacio académico interdisciplinario.',
            'costo_alumno' => '220.00',
            'promocion_redes' => 'si',
            'detalles' => $detalles,
            ...$extra,
        ])->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };

    $this->imprimir = fn (User $usuario, Reserva $reserva) => $this->actingAs($usuario)->get(route('reservas.imprimir', $reserva));
});

// --- Permisos (los mismos que el detalle) ---

test('the creator can print her own draft', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);

    ($this->imprimir)($this->docente, $reserva)->assertOk()->assertViewIs('reservas.imprimir');
});

test('nobody else can print a draft, not even coordination or administration', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);

    foreach ([$this->coordinador, $this->admin, $this->otroDocente, $this->coordinadorAjeno] as $usuario) {
        ($this->imprimir)($usuario, $reserva)->assertForbidden();
    }
});

test('once sent, it follows the view policy: own coordination and administration yes, others no', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    ($this->imprimir)($this->docente, $reserva)->assertOk();
    ($this->imprimir)($this->coordinador, $reserva)->assertOk();
    ($this->imprimir)($this->admin, $reserva)->assertOk();
    ($this->imprimir)($this->otroDocente, $reserva)->assertForbidden();
    ($this->imprimir)($this->coordinadorAjeno, $reserva)->assertForbidden();
});

test('guests are sent to the login', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);
    auth()->logout();

    $this->get(route('reservas.imprimir', $reserva))->assertRedirect(route('login'));
});

test('an unknown reservation is not found', function () {
    $this->actingAs($this->docente)->get('/reservas/999999/imprimir')->assertNotFound();
});

// --- Folio y fechas ---

test('it shows the institutional folio with the internal one, and the automatic dates', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee('No. FOLIO:')
        ->assertSee($reserva->folio_institucional)
        ->assertSee('Folio interno: '.$reserva->folio)
        ->assertSeeText('FECHA DE ELABORACIÓN: 11 NOVIEMBRE DEL 2026')
        ->assertSeeText('FECHA DE ENTREGA DE FORMATO: No registrada')
        // El apartado del Word se conserva, sin inventar una fecha que el sistema no registra.
        ->assertSee('ACTUALIZACIÓN DE ESPACIOS:')
        ->assertSee('Sin fecha registrada');

    expect($reserva->folio_institucional)->toStartWith('LSC-20261111-');
});

test('without an acronym it shows only the RES folio', function () {
    $reserva = ($this->crear)($this->sinSiglas, [$this->bloqueAuditorio]);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee('No. FOLIO:')
        ->assertSee($reserva->folio)
        ->assertDontSee('Folio interno');
});

test('the delivery date appears once sent, and the creation date never changes', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);
    $this->travelTo(Date::parse('2026-11-13 09:00:00'));
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSeeText('FECHA DE ELABORACIÓN: 11 NOVIEMBRE DEL 2026')
        ->assertSeeText('FECHA DE ENTREGA DE FORMATO: 13 NOVIEMBRE DEL 2026');
});

// --- Datos generales y bloques ---

test('it shows the general data of the request', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee('Jornada Académica')
        ->assertSee('Tipo de actividad: Conferencia')
        ->assertSee('Brindar un espacio académico interdisciplinario.')
        ->assertSee('Sistemas Computacionales')
        ->assertSee('$ 220.00')
        ->assertSee('SI')
        ->assertSee('Este formato se deberá enviar impreso con al menos 10 días de anticipación.');
});

test('a single block shows its date, schedule, place and the number of students as the total', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee('15-12-2026')
        ->assertSeeInOrder(['Hora de inicio:', '08:00', 'Hora de final:', '12:00'])
        ->assertSee('martes 15 de diciembre de 2026: Auditorio 1 — Escenario principal')
        ->assertSee('80 alumnos')
        ->assertDontSee('Suma por bloque');
});

test('several blocks get an inner table and the students are a per-block sum, not distinct people', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio, $this->bloqueAnexo]);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSeeInOrder(['Fecha', 'Horario', 'Espacio', 'Lugar específico', 'Alumnos'])
        ->assertSeeInOrder(['15-12-2026', '08:00 a 12:00', 'Auditorio 1', 'Escenario principal', '80'])
        ->assertSeeInOrder(['16-12-2026', '09:00 a 11:00', 'Anexo', '30'])
        ->assertSee('Suma por bloque: 110')
        ->assertSee('no equivale a personas distintas')
        ->assertDontSee('110 alumnos')
        // Horarios distintos: no se inventa un horario común.
        ->assertSee('Varía por bloque');
});

test('the description lists each block with its responsible teacher', function () {
    $reserva = ($this->crear)($this->lsc, [
        [...$this->bloqueAuditorio, 'docente_responsable_id' => $this->docente->id],
        $this->bloqueAnexo,
    ]);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSeeInOrder(['Martes 15 de diciembre de 2026', 'Docente responsable:', $this->docente->name, 'Protocolo de inicio.'])
        ->assertSeeInOrder(['Miércoles 16 de diciembre de 2026', 'Taller de análisis de datos.'])
        ->assertSee('Invitados: Director de licenciaturas');
});

test('it shows every requested requirement with its quantity and does not invent categories', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio, $this->bloqueAnexo]);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee('Proyector: 2')
        ->assertSee('Sillas: 30')
        ->assertSee('Material adicional:')
        ->assertSee('Mampara roja del CUV')
        // El Word separa «Dirección General» y «Coordinación», pero el sistema no guarda esa clasificación.
        ->assertDontSee('Dirección General')
        ->assertDontSee('Dirección general');
});

test('user text is escaped', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio], ['objetivo' => '<script>alert(1)</script>']);

    ($this->imprimir)($this->docente, $reserva)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('the official title appears in the header and in the footer, and the footer has no status line', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    $html = ($this->imprimir)($this->docente, $reserva)->assertOk()->getContent();

    expect(substr_count($html, 'FORMATO 10 DE ACTIVIDADES O EVENTOS'))->toBe(2)
        ->and($html)->not->toContain('FORMATO DE ACTIVIDADES O EVENTOS')
        ->and($html)->not->toContain('FORMATO 10 ACTIVIDADES O EVENTOS')
        // El pie solo lleva el nombre del formato: ni folio, ni estado, ni fecha de impresión.
        ->and($html)->not->toContain('Impreso')
        ->and($html)->not->toContain('sin autorizar')
        ->and(preg_match('#<div class="pie">\s*<span class="formato">FORMATO 10 DE ACTIVIDADES O EVENTOS</span>\s*</div>#', $html))->toBe(1);

    // El aviso de estado bajo el título se conserva.
    expect($html)->toContain('PENDIENTE DE REVISIÓN POR COORDINACIÓN')
        ->and($html)->toContain('Este documento no es una autorización.');
});

// --- Estados y firmas ---

test('only an authorized request is presented as authorized', function (string $estado, array $acciones, string $titulo) {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);

    foreach ($acciones as [$quien, $accion, $observacion]) {
        $this->workflow->ejecutar($reserva->refresh(), $this->{$quien}, $accion, $observacion);
    }

    expect(E::etiqueta($reserva->refresh()->estado_id))->toBe(E::etiqueta(constant(E::class.'::'.$estado)));

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee($titulo)
        ->assertSee('Este documento no es una autorización.')
        ->assertDontSee('SOLICITUD AUTORIZADA')
        ->assertDontSee('Autorizada en el sistema');
})->with([
    'borrador' => ['BORRADOR', [], 'BORRADOR'],
    'pendiente de coordinación' => ['PENDIENTE_COORDINADOR', [['docente', W::ENVIAR, null]], 'PENDIENTE DE REVISIÓN POR COORDINACIÓN'],
    'en corrección' => ['CORRECCION', [['docente', W::ENVIAR, null], ['coordinador', W::SOLICITAR_CORRECCION, 'Falta el programa']], 'EN CORRECCIÓN'],
    'pendiente de administración' => ['PENDIENTE_ADMIN', [['docente', W::ENVIAR, null], ['coordinador', W::APROBAR, null]], 'PENDIENTE DE AUTORIZACIÓN'],
    'rechazada' => ['DENEGADA', [['docente', W::ENVIAR, null], ['coordinador', W::APROBAR, null], ['admin', W::DENEGAR, 'Sin disponibilidad']], 'SOLICITUD RECHAZADA'],
]);

test('an authorized request shows the real names of who sent, approved and authorized it', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->coordinador, W::APROBAR);
    $this->travelTo(Date::parse('2026-11-12 16:30:00'));
    $this->workflow->ejecutar($reserva->refresh(), $this->admin, W::AUTORIZAR, 'Espacios confirmados.');

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee('SOLICITUD AUTORIZADA')
        ->assertSee('Autorizada en el sistema el 12/11/2026 16:30.')
        ->assertDontSee('Este documento no es una autorización.')
        ->assertSeeInOrder([
            'Nombre y firma del docente responsable', 'Nombre y firma del coordinador responsable', 'Firma de Autorización',
            $this->docente->name, 'Entregó la solicitud',
            $this->coordinador->name, 'Aprobada por coordinación',
            $this->admin->name, 'Autorizada el 12/11/2026 16:30',
        ]);
});

test('a pending request leaves the authorization signature without a name', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    ($this->imprimir)($this->docente, $reserva)
        ->assertSee('Firma de Autorización')
        ->assertSee('Pendiente')
        ->assertDontSee($this->admin->name);
});

test('there are three physical signature spaces and no signature images or seals', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio]);
    $html = ($this->imprimir)($this->docente, $reserva)->assertOk()->getContent();

    expect(substr_count($html, 'class="espacio-firma"'))->toBe(3)
        // La única imagen es el membrete institucional.
        ->and(substr_count($html, '<img'))->toBe(1)
        ->and($html)->toContain('images/membrete_formato10.jpg')
        ->and(public_path('images/membrete_formato10.jpg'))->toBeFile();
});

// --- Solo lectura ---

test('printing writes nothing and has no forms', function () {
    $reserva = ($this->crear)($this->lsc, [$this->bloqueAuditorio, $this->bloqueAnexo]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $antes = [
        'reserva' => $reserva->refresh()->getAttributes(),
        'historial' => $reserva->historial()->count(),
        'notificaciones' => Notificacion::query()->count(),
        'detalles' => DB::table('reserva_detalles')->where('reserva_id', $reserva->id)->get()->toArray(),
    ];

    $escrituras = [];
    DB::listen(function ($consulta) use (&$escrituras): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $consulta->sql) === 1) {
            $escrituras[] = $consulta->sql;
        }
    });

    $this->travel(5)->minutes();
    $html = ($this->imprimir)($this->coordinador, $reserva)->assertOk()->getContent();

    expect($escrituras)->toBe([])
        ->and($reserva->refresh()->getAttributes())->toBe($antes['reserva'])
        ->and($reserva->historial()->count())->toBe($antes['historial'])
        ->and(Notificacion::query()->count())->toBe($antes['notificaciones'])
        ->and(DB::table('reserva_detalles')->where('reserva_id', $reserva->id)->get()->toArray())->toEqual($antes['detalles'])
        ->and($html)->not->toContain('<form')
        ->and($html)->not->toContain('<input')
        ->and($html)->not->toContain('_token');
});
