<?php

use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

pest()->extend(TestCase::class);

/*
|--------------------------------------------------------------------------
| Prueba OPCIONAL de concurrencia real (MySQL / InnoDB)
|--------------------------------------------------------------------------
| SQLite no permite dos transacciones con bloqueo de filas a la vez, así que esta
| prueba no forma parte de `php artisan test` (tests/Concurrencia no está en
| phpunit.xml). Para ejecutarla:
|
|   1. Crea la base MySQL VACÍA y exclusiva `reservas_cuv_test`. La prueba BORRA y
|      recrea sus tablas.
|   2. PowerShell:  $env:CONCURRENCIA_DB = 'reservas_cuv_test'
|                   php artisan test tests/Concurrencia
|
| Usa host, puerto y usuario de la conexión `mysql` configurada, pero SIEMPRE con
| la base `reservas_cuv_test`. Antes de borrar exige que: CONCURRENCIA_DB sea
| exactamente ese nombre, la configuración y DATABASE() coincidan, y la base esté
| vacía o contenga solo las tablas que dejó esta prueba (tabla
| `_marca_prueba_concurrencia`). Si algo no cuadra, se cancela sin tocar nada.
*/

// Única base que esta prueba puede vaciar. No basta con que el nombre termine en _test.
const BASE_CONCURRENCIA = 'reservas_cuv_test';

// Tabla que la prueba crea al terminar de migrar, con la lista exacta de tablas que dejó.
const MARCA_CONCURRENCIA = '_marca_prueba_concurrencia';

beforeEach(function () {
    $base = (string) env('CONCURRENCIA_DB', '');

    if ($base === '') {
        $this->markTestSkipped('Define CONCURRENCIA_DB='.BASE_CONCURRENCIA.' para ejecutar la prueba de concurrencia.');
    }

    if ($base !== BASE_CONCURRENCIA) {
        $this->fail("CANCELADA: CONCURRENCIA_DB='{$base}' no es '".BASE_CONCURRENCIA."'. No se tocó ninguna base.");
    }

    $this->conexion = [...config('database.connections.mysql'), 'url' => null, 'database' => BASE_CONCURRENCIA];
    config(['database.connections.concurrencia' => $this->conexion]);
    $db = DB::connection('concurrencia');

    // 1. La configuración y el servidor deben apuntar exactamente a la base de concurrencia.
    $servidor = $db->selectOne('SELECT DATABASE() AS db')->db;
    if ($db->getDatabaseName() !== BASE_CONCURRENCIA || $servidor !== BASE_CONCURRENCIA) {
        $this->fail("CANCELADA: la conexión apunta a config='{$db->getDatabaseName()}', DATABASE()='{$servidor}'. No se tocó ninguna base.");
    }

    // 2. Solo se vacía una base vacía o una que esta misma prueba dejó (marca + exactamente sus tablas).
    $tablas = collect($db->select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))
        ->pluck('t')->sort()->values()->all();

    if ($tablas !== []) {
        if (! in_array(MARCA_CONCURRENCIA, $tablas, true)) {
            $this->fail('CANCELADA: '.BASE_CONCURRENCIA.' tiene '.count($tablas).' tablas y no la marca de la prueba. No se tocó ninguna base.');
        }

        $esperadas = json_decode((string) $db->table(MARCA_CONCURRENCIA)->value('tablas'), true);
        $esperadas = is_array($esperadas) ? collect([...$esperadas, MARCA_CONCURRENCIA])->sort()->values()->all() : null;

        if ($esperadas !== $tablas) {
            $desconocidas = $esperadas === null ? $tablas : array_values(array_diff($tablas, $esperadas));
            $this->fail('CANCELADA: tablas desconocidas o marca inconsistente en '.BASE_CONCURRENCIA.': '.implode(', ', $desconocidas ?: ['(faltan tablas esperadas)']).'. No se tocó ninguna base.');
        }
    }

    // Comprobaciones superadas: solo ahora se vacía y se recrea la base de concurrencia.
    config(['database.default' => 'concurrencia']);
    Artisan::call('migrate:fresh', ['--database' => 'concurrencia', '--force' => true]);

    $creadas = collect($db->select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))
        ->pluck('t')->sort()->values()->all();
    $db->statement('CREATE TABLE `'.MARCA_CONCURRENCIA.'` (id INT PRIMARY KEY, base VARCHAR(64) NOT NULL, tablas TEXT NOT NULL, creada_en TIMESTAMP NOT NULL)');
    $db->table(MARCA_CONCURRENCIA)->insert(['id' => 1, 'base' => BASE_CONCURRENCIA, 'tablas' => json_encode($creadas), 'creada_en' => now()]);
});

afterEach(function () {
    if (config('database.default') === 'concurrencia') {
        DB::purge('concurrencia');
    }
});

test('two administrators cannot authorize the same space, date and time at once', function () {
    $licenciatura = Licenciatura::factory()->create();
    $espacio = Espacio::factory()->create();
    $admin = User::factory()->rol('admin')->create();
    $manana = Date::tomorrow()->toDateString();
    $nueva = fn () => Reserva::factory()->enEstado(E::PENDIENTE_ADMIN)
        ->conDetalle(['espacio_id' => $espacio->id, 'fecha' => $manana])
        ->create(['licenciatura_id' => $licenciatura->id]);
    $primera = $nueva();
    $segunda = $nueva();

    // Otro administrador autoriza $primera en paralelo: bloquea el espacio y tarda 2 s en confirmar.
    $c = $this->conexion;
    $proceso = proc_open(
        [PHP_BINARY, __DIR__.'/soporte/autorizar_en_paralelo.php', (string) $espacio->id, (string) $primera->id, '2'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $tuberias,
        null,
        [...getenv(), 'CONC_DSN' => "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", 'CONC_USER' => $c['username'], 'CONC_PASS' => (string) $c['password']],
    );
    expect(trim((string) fgets($tuberias[1])))->toBe('BLOQUEADO');

    // Al mismo tiempo, este administrador autoriza $segunda: debe ESPERAR el bloqueo del
    // espacio y, ya dentro de su misma transacción, ver la autorización recién confirmada.
    $inicio = microtime(true);

    try {
        app(W::class)->ejecutar($segunda, $admin, W::AUTORIZAR, 'Autorizada.');
        $this->fail('La segunda autorización debió rechazarse.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain('choca con la reserva autorizada');
    } finally {
        $salida = stream_get_contents($tuberias[1]).stream_get_contents($tuberias[2]);
        array_map('fclose', $tuberias);
        proc_close($proceso);
    }

    $espera = microtime(true) - $inicio;

    // Reporte opcional (fuera del repositorio) con el tiempo medido y el estado final.
    if (($reporte = env('CONCURRENCIA_REPORTE')) !== null) {
        file_put_contents((string) $reporte, json_encode([
            'base' => DB::connection()->selectOne('SELECT DATABASE() AS db')->db,
            'espera_segundos' => round($espera, 3),
            'proceso_paralelo' => trim($salida),
            'primera' => ['folio' => $primera->refresh()->folio, 'estado_id' => $primera->estado_id, 'detalles' => $primera->detalles()->pluck('estado_detalle')->all(), 'historial' => $primera->historial()->pluck('accion')->all()],
            'segunda' => ['folio' => $segunda->refresh()->folio, 'estado_id' => $segunda->estado_id, 'detalles' => $segunda->detalles()->pluck('estado_detalle')->all(), 'historial' => $segunda->historial()->pluck('accion')->all(), 'observaciones_admin' => $segunda->observaciones_admin],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    expect($espera)->toBeGreaterThan(1.5)
        ->and($salida)->toContain('CONFIRMADO')
        ->and($primera->refresh()->estado_id)->toBe(E::AUTORIZADA)
        ->and($primera->detalles()->pluck('estado_detalle')->all())->toBe(['autorizada'])
        ->and($segunda->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($segunda->detalles()->pluck('estado_detalle')->all())->toBe(['pendiente'])
        ->and($segunda->observaciones_admin)->toBeNull()
        ->and($segunda->historial()->count())->toBe(0)
        ->and(DB::table('notificaciones')->where('reserva_id', $segunda->id)->count())->toBe(0);
});
