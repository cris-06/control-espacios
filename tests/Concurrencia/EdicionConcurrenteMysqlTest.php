<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva as H;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\Rol;
use App\Models\User;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

pest()->extend(TestCase::class);

/*
|--------------------------------------------------------------------------
| Prueba OPCIONAL de concurrencia real: edición frente a transiciones (MySQL / InnoDB)
|--------------------------------------------------------------------------
| Comprueba que ReservaService::actualizar bloquea la fila de la reserva y vuelve a validar
| estado, permiso y anticipación DESPUÉS de obtener el bloqueo. Un proceso PHP paralelo
| (soporte/transicion_en_paralelo.php) ejecuta el código real, retiene el bloqueo 2 s y
| confirma; este proceso intenta su operación mientras tanto y debe ESPERAR.
|
| No forma parte de `php artisan test` (tests/Concurrencia no está en phpunit.xml). Uso:
|
|   1. Crear la base VACÍA reservas_cuv_concurrencia_edicion_test (solo con autorización).
|   2. PowerShell:  $env:CONCURRENCIA_EDICION_DB = 'reservas_cuv_concurrencia_edicion_test'
|                   php artisan test tests/Concurrencia/EdicionConcurrenteMysqlTest.php --stop-on-failure
|   NO definas CONCURRENCIA_DB: esa variable es de AutorizacionConcurrenteTest.
|
| Antes de migrar exige: la variable exacta, que no sea una base protegida, DATABASE() igual
| en la conexión dedicada y una base VACÍA (o, dentro de la misma ejecución, solo las tablas que
| esta ejecución creó). Una base con datos de una ejecución anterior se rechaza: se conserva
| como evidencia. Solo ejecuta `migrate` (nunca migrate:fresh ni seeders).
*/

const BASE_EDICION = 'reservas_cuv_concurrencia_edicion_test';
const BASES_PROTEGIDAS_EDICION = ['reservas_cuv_2', 'reservas_cuv_ui_test', 'reservas_cuv_test', 'reservas_cuv_concurrencia_h7b_test'];
const MARCA_EDICION = '_marca_prueba_edicion_concurrente';
const RETENCION_SEGUNDOS = 2.0;
const ESPERA_MAXIMA_SEGUNDOS = 15.0;

beforeEach(function () {
    $base = (string) env('CONCURRENCIA_EDICION_DB', '');

    if ($base === '') {
        $this->markTestSkipped('Define CONCURRENCIA_EDICION_DB='.BASE_EDICION.' para ejecutar esta prueba.');
    }

    if ($base !== BASE_EDICION || in_array($base, BASES_PROTEGIDAS_EDICION, true)) {
        $this->fail("CANCELADA: CONCURRENCIA_EDICION_DB='{$base}' no es la base autorizada. No se tocó ninguna base.");
    }

    if (app()->configurationIsCached()) {
        $this->fail('CANCELADA: hay configuración en caché. No se tocó ninguna base.');
    }

    // Conexión dedicada: la de mysql (host, puerto, usuario) SIN url heredada y con la base autorizada.
    $this->conexion = [...config('database.connections.mysql'), 'url' => null, 'database' => BASE_EDICION];
    config(['database.connections.concurrencia_edicion' => $this->conexion]);
    DB::purge('concurrencia_edicion');
    $db = DB::connection('concurrencia_edicion');

    $servidor = $db->selectOne('SELECT DATABASE() AS db')->db;
    if ($servidor !== BASE_EDICION || $db->getDatabaseName() !== BASE_EDICION || in_array($servidor, BASES_PROTEGIDAS_EDICION, true)) {
        $this->fail("CANCELADA: la conexión apunta a config='{$db->getDatabaseName()}', DATABASE()='{$servidor}'. No se tocó ninguna base.");
    }

    // Identificador de ESTA ejecución (un proceso de Pest): solo ella puede reutilizar la base.
    $GLOBALS['__ejecucion_edicion'] ??= bin2hex(random_bytes(8));
    $ejecucion = $GLOBALS['__ejecucion_edicion'];

    $tablas = collect($db->select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))
        ->pluck('t')->sort()->values()->all();

    if ($tablas === []) {
        config(['database.default' => 'concurrencia_edicion']);
        Artisan::call('migrate', ['--database' => 'concurrencia_edicion', '--force' => true]);

        $creadas = collect($db->select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))
            ->pluck('t')->sort()->values()->all();
        $db->statement('CREATE TABLE `'.MARCA_EDICION.'` (id INT PRIMARY KEY, base VARCHAR(64) NOT NULL, ejecucion VARCHAR(32) NOT NULL, tablas TEXT NOT NULL, creada_en TIMESTAMP NOT NULL)');
        $db->table(MARCA_EDICION)->insert(['id' => 1, 'base' => BASE_EDICION, 'ejecucion' => $ejecucion, 'tablas' => json_encode($creadas), 'creada_en' => now()]);
    } else {
        $marca = in_array(MARCA_EDICION, $tablas, true) ? $db->table(MARCA_EDICION)->first() : null;
        $esperadas = $marca !== null ? collect([...json_decode($marca->tablas, true), MARCA_EDICION])->sort()->values()->all() : null;

        if ($marca === null || $marca->ejecucion !== $ejecucion || $esperadas !== $tablas) {
            $this->fail('CANCELADA: '.BASE_EDICION.' no está vacía (datos de una ejecución anterior o tablas desconocidas). Se conserva sin tocar.');
        }
    }

    config(['database.default' => 'concurrencia_edicion']);
    $db->statement('SET SESSION innodb_lock_wait_timeout = 10');

    // Datos de cada escenario (en la base aislada).
    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40]);
    $this->servicio = app(ReservaService::class);
    $this->workflow = app(W::class);
    $this->hoy = Date::today();
    $this->dia = fn (int $dias): string => Date::today()->addDays($dias)->toDateString();

    $this->datos = fn (array $extra = []): array => [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Prueba concurrente',
        'objetivo' => 'Objetivo original',
        ...$extra,
    ];

    // Borrador creado con el servicio real (registra «creada»).
    $this->borrador = fn (string $fecha): Reserva => $this->servicio->crear($this->docente, ($this->datos)(), [[
        'espacio_id' => $this->espacio->id, 'fecha' => $fecha, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20,
    ]]);

    $this->bloques = function (Reserva $reserva, array $cambios = []): array {
        $d = ReservaDetalle::query()->where('reserva_id', $reserva->id)->firstOrFail();

        return [[
            'id' => $d->id, 'espacio_id' => $d->espacio_id, 'fecha' => $d->fecha->toDateString(),
            'hora_inicio' => ReservaDetalle::hhmm($d->hora_inicio), 'hora_fin' => ReservaDetalle::hhmm($d->hora_fin),
            'cantidad_alumnos' => $d->cantidad_alumnos, ...$cambios,
        ]];
    };

    $this->estado = fn (Reserva $reserva): array => [
        'estado_id' => Reserva::query()->findOrFail($reserva->id)->estado_id,
        'fecha_entrega' => Reserva::query()->findOrFail($reserva->id)->fecha_entrega_formato?->toDateString(),
        'objetivo' => Reserva::query()->findOrFail($reserva->id)->objetivo,
        'detalles' => ReservaDetalle::query()->where('reserva_id', $reserva->id)->orderBy('id')->get()
            ->map(fn (ReservaDetalle $d): string => $d->fecha->toDateString().' '.ReservaDetalle::hhmm($d->hora_inicio).'-'.ReservaDetalle::hhmm($d->hora_fin))->all(),
        'historial' => H::query()->where('reserva_id', $reserva->id)->orderBy('id')->pluck('accion')->all(),
        'notificaciones' => Notificacion::query()->where('reserva_id', $reserva->id)->count(),
    ];

    /**
     * Lanza el proceso paralelo, espera a que retenga el bloqueo, ejecuta $operacion en ESTE
     * proceso y devuelve tiempos, eventos y el resultado (o la excepción) de $operacion.
     */
    $this->competir = function (string $modo, Reserva $reserva, User $usuario, Closure $operacion, ?array $datosParalelo = null): array {
        $eventos = tempnam(sys_get_temp_dir(), 'conc_ev_');
        $datosArchivo = null;

        if ($datosParalelo !== null) {
            $datosArchivo = tempnam(sys_get_temp_dir(), 'conc_dt_');
            file_put_contents($datosArchivo, json_encode($datosParalelo));
        }

        $c = $this->conexion;
        $entorno = [
            ...getenv(),
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_DATABASE' => BASE_EDICION,
            'CONCURRENCIA_EDICION_DB' => BASE_EDICION, 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
        ];
        $argumentos = [PHP_BINARY, __DIR__.'/soporte/transicion_en_paralelo.php', $modo, (string) $reserva->id, (string) $usuario->id, (string) RETENCION_SEGUNDOS, $eventos];
        if ($datosArchivo !== null) {
            $argumentos[] = $datosArchivo;
        }

        $proceso = proc_open($argumentos, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, null, $entorno);
        $leer = fn (): array => array_values(array_filter(array_map(fn (string $l) => json_decode($l, true), file($eventos, FILE_IGNORE_NEW_LINES) ?: [])));
        $buscar = fn (array $lista, string $nombre): ?array => collect($lista)->firstWhere('evento', $nombre);

        // 1. A adquiere el bloqueo (con límite de tiempo).
        $limite = microtime(true) + ESPERA_MAXIMA_SEGUNDOS;
        while ($buscar($leer(), 'BLOQUEADO') === null) {
            if (($error = $buscar($leer(), 'ERROR') ?? $buscar($leer(), 'CANCELADO')) !== null || microtime(true) > $limite) {
                proc_terminate($proceso);
                $this->fail('Proceso paralelo sin bloqueo: '.json_encode($error ?? 'tiempo agotado', JSON_UNESCAPED_UNICODE));
            }
            usleep(20_000);
        }

        // 2. B intenta su operación mientras A retiene la fila: debe esperar.
        $inicio = microtime(true);
        $resultado = null;
        $excepcion = null;

        try {
            $resultado = $operacion();
        } catch (Throwable $e) {
            $excepcion = $e;
        }

        $fin = microtime(true);

        // 3. A termina (con límite de tiempo).
        $limite = microtime(true) + ESPERA_MAXIMA_SEGUNDOS;
        while ($buscar($leer(), 'LIBERADO') === null && $buscar($leer(), 'ERROR') === null && microtime(true) < $limite) {
            usleep(20_000);
        }
        $salida = stream_get_contents($tuberias[1]).stream_get_contents($tuberias[2]);
        array_map('fclose', $tuberias);
        $codigo = proc_close($proceso);
        $lista = $leer();
        @unlink($eventos);
        if ($datosArchivo !== null) {
            @unlink($datosArchivo);
        }

        $bloqueo = $buscar($lista, 'BLOQUEADO');
        $liberado = $buscar($lista, 'LIBERADO');

        return [
            'modo' => $modo,
            'db_paralelo' => $buscar($lista, 'INICIO')['db'] ?? null,
            'codigo_paralelo' => $codigo,
            'salida_paralelo' => trim($salida),
            'eventos' => array_column($lista, 'evento'),
            'liberado_como' => $liberado['como'] ?? null,
            'error_paralelo' => $buscar($lista, 'ERROR'),
            'espera_segundos' => round($fin - $inicio, 3),
            // Orden: A bloquea < B empieza < A libera <= B termina.
            'orden_correcto' => $bloqueo !== null && $liberado !== null
                && $bloqueo['t'] < $inicio && $inicio < $liberado['t'] && $liberado['t'] <= $fin,
            'b_termino_tras_liberar_ms' => $liberado !== null ? round(($fin - $liberado['t']) * 1000, 1) : null,
            'excepcion' => $excepcion,
            'resultado' => $resultado,
        ];
    };

    // Reporte opcional fuera del repositorio.
    $this->reportar = function (string $escenario, array $medicion, array $antes, array $despues): void {
        if (($reporte = env('CONCURRENCIA_EDICION_REPORTE')) === null) {
            return;
        }

        $todo = is_file($reporte) ? json_decode((string) file_get_contents($reporte), true) : [];
        $todo[$escenario] = [
            'base' => DB::connection()->selectOne('SELECT DATABASE() AS db')->db,
            ...collect($medicion)->except(['excepcion', 'resultado'])->all(),
            'excepcion' => $medicion['excepcion'] !== null ? [$medicion['excepcion']::class, mb_substr($medicion['excepcion']->getMessage(), 0, 300)] : null,
            'errores_validacion' => $medicion['excepcion'] instanceof ValidationException ? $medicion['excepcion']->errors() : null,
            'antes' => $antes,
            'despues' => $despues,
        ];
        file_put_contents((string) $reporte, json_encode($todo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    };

    // Comprobaciones comunes a toda competencia por la misma fila.
    $this->esperoDeVerdad = function (array $m): void {
        expect($m['db_paralelo'])->toBe(BASE_EDICION)
            ->and($m['codigo_paralelo'])->toBe(0)
            ->and($m['error_paralelo'])->toBeNull()
            ->and($m['orden_correcto'])->toBeTrue()
            ->and($m['espera_segundos'])->toBeGreaterThan(RETENCION_SEGUNDOS - 0.5)
            ->and($m['espera_segundos'])->toBeLessThan(ESPERA_MAXIMA_SEGUNDOS);
    };
});

afterEach(function () {
    if (config('database.default') === 'concurrencia_edicion') {
        DB::purge('concurrencia_edicion');
    }
});

test('A: an edit racing the first send waits and is then rejected by the new state', function () {
    $reserva = ($this->borrador)(($this->dia)(15));
    $leida = Reserva::query()->findOrFail($reserva->id); // la edición ya pasó el permiso con esta copia
    $antes = ($this->estado)($reserva);

    $m = ($this->competir)('enviar', $reserva, $this->docente,
        fn () => $this->servicio->actualizar($leida, $this->docente, ($this->datos)(['objetivo' => 'No debe guardarse']), ($this->bloques)($reserva, ['fecha' => ($this->dia)(2)])));
    $despues = ($this->estado)($reserva);
    ($this->reportar)('A_envio_vs_edicion', $m, $antes, $despues);

    ($this->esperoDeVerdad)($m);
    expect($m['liberado_como'])->toBe('commit')
        ->and($m['excepcion'])->toBeInstanceOf(AuthorizationException::class)
        ->and($despues)->toBe([
            'estado_id' => E::PENDIENTE_COORDINADOR,
            'fecha_entrega' => $this->hoy->toDateString(),
            'objetivo' => 'Objetivo original',
            'detalles' => [($this->dia)(15).' 10:00-12:00'],
            'historial' => [H::CREADA, H::ENVIADA_COORDINADOR],
            'notificaciones' => 1, // solo la del envío, al coordinador
        ]);
});

test('B: a coordinator edit racing the approval waits and is then rejected', function () {
    $reserva = ($this->borrador)(($this->dia)(15));
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $leida = Reserva::query()->findOrFail($reserva->id);
    $antes = ($this->estado)($reserva);
    $administradores = User::query()->where('estatus', 'activo')->whereHas('rol', fn ($r) => $r->where('nivel', '>=', Rol::NIVEL_ADMIN))->count();

    $m = ($this->competir)('aprobar', $reserva, $this->coordinador,
        fn () => $this->servicio->actualizar($leida, $this->coordinador, ($this->datos)(['objetivo' => 'No debe guardarse']), ($this->bloques)($reserva, ['hora_fin' => '13:00'])));
    $despues = ($this->estado)($reserva);
    ($this->reportar)('B_aprobacion_vs_edicion', $m, $antes, $despues);

    ($this->esperoDeVerdad)($m);
    expect($m['liberado_como'])->toBe('commit')
        ->and($m['excepcion'])->toBeInstanceOf(AuthorizationException::class)
        ->and($despues)->toBe([
            'estado_id' => E::PENDIENTE_ADMIN,
            'fecha_entrega' => $this->hoy->toDateString(),
            'objetivo' => 'Objetivo original',
            'detalles' => [($this->dia)(15).' 10:00-12:00'],
            'historial' => [H::CREADA, H::ENVIADA_COORDINADOR, H::APROBADA_COORDINADOR], // sin «editada_coordinador»
            'notificaciones' => 1 + $administradores,
        ]);
});

test('C: a stale resubmission of an old near date after a concurrent change must meet the 10 days', function () {
    // En corrección, con su fecha original (ya enviada) a 5 días: válida mientras no cambie.
    $reserva = Reserva::factory()->enEstado(E::CORRECCION)
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => ($this->dia)(5)])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id, 'actividad_id' => $this->actividad->id, 'objetivo' => 'Objetivo original', 'fecha_entrega_formato' => ($this->dia)(-10)]);
    $formularioViejo = ($this->bloques)($reserva); // otra pestaña, con la fecha a 5 días
    $leida = Reserva::query()->findOrFail($reserva->id);
    $antes = ($this->estado)($reserva);

    // A: la docente mueve el bloque a 20 días (cambio válido) y retiene la fila.
    $m = ($this->competir)('editar', $reserva, $this->docente,
        fn () => $this->servicio->actualizar($leida, $this->docente, ($this->datos)(['objetivo' => 'Objetivo de la pestaña vieja']), $formularioViejo),
        ['datos' => ($this->datos)(['objetivo' => 'Objetivo de la pestaña nueva']), 'detalles' => ($this->bloques)($reserva, ['fecha' => ($this->dia)(20)])]);
    $despues = ($this->estado)($reserva);
    ($this->reportar)('C_reenvio_fecha_vieja_vs_cambio', $m, $antes, $despues);

    ($this->esperoDeVerdad)($m);
    expect($m['liberado_como'])->toBe('commit')
        ->and($m['excepcion'])->toBeInstanceOf(ValidationException::class)
        ->and($m['excepcion']->errors())->toHaveKey('detalles.0.fecha')
        ->and($despues)->toBe([
            'estado_id' => E::CORRECCION,
            'fecha_entrega' => ($this->dia)(-10),
            'objetivo' => 'Objetivo de la pestaña nueva',
            'detalles' => [($this->dia)(20).' 10:00-12:00'],
            'historial' => [], // la creadora no deja «editada_coordinador»
            'notificaciones' => 0,
        ]);
});

test('D: sending a draft while it is being saved reads the saved block and enforces the 10 days', function () {
    $reserva = ($this->borrador)(($this->dia)(15));
    $antes = ($this->estado)($reserva);

    // A: guarda el borrador con una fecha a 3 días (permitido en borrador) y retiene la fila.
    $m = ($this->competir)('editar', $reserva, $this->docente,
        fn () => $this->workflow->ejecutar(Reserva::query()->findOrFail($reserva->id), $this->docente, W::ENVIAR),
        ['datos' => ($this->datos)(), 'detalles' => ($this->bloques)($reserva, ['fecha' => ($this->dia)(3)])]);
    $despues = ($this->estado)($reserva);
    ($this->reportar)('D_envio_vs_guardado_borrador', $m, $antes, $despues);

    ($this->esperoDeVerdad)($m);
    expect($m['liberado_como'])->toBe('commit')
        ->and($m['excepcion'])->toBeInstanceOf(ValidationException::class)
        ->and(collect($m['excepcion']->errors())->flatten()->implode(' '))->toContain('10 días de anticipación')
        ->and($despues)->toBe([
            'estado_id' => E::BORRADOR,
            'fecha_entrega' => null,
            'objetivo' => 'Objetivo original',
            'detalles' => [($this->dia)(3).' 10:00-12:00'],
            'historial' => [H::CREADA],
            'notificaciones' => 0,
        ]);
});

test('E: a valid edit waits for a lock to be released and then succeeds', function () {
    $reserva = ($this->borrador)(($this->dia)(15));
    $leida = Reserva::query()->findOrFail($reserva->id);
    $antes = ($this->estado)($reserva);

    $m = ($this->competir)('bloquear', $reserva, $this->docente,
        fn () => $this->servicio->actualizar($leida, $this->docente, ($this->datos)(['objetivo' => 'Objetivo actualizado']), ($this->bloques)($reserva, ['hora_fin' => '13:00'])));
    $despues = ($this->estado)($reserva);
    ($this->reportar)('E_edicion_valida_espera', $m, $antes, $despues);

    ($this->esperoDeVerdad)($m);
    expect($m['liberado_como'])->toBe('rollback')
        ->and($m['excepcion'])->toBeNull()
        ->and($despues)->toBe([
            'estado_id' => E::BORRADOR,
            'fecha_entrega' => null,
            'objetivo' => 'Objetivo actualizado',
            'detalles' => [($this->dia)(15).' 10:00-13:00'],
            'historial' => [H::CREADA],
            'notificaciones' => 0,
        ]);
});
