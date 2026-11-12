<?php

/**
 * Proceso auxiliar de EdicionConcurrenteMysqlTest: otra persona que, en su propia conexión
 * y transacción, ejecuta el código REAL de la aplicación sobre una reserva, mantiene el
 * bloqueo unos segundos y después confirma (o revierte).
 *
 * Uso: php transicion_en_paralelo.php <modo> <reserva_id> <usuario_id> <segundos> <archivo_eventos> [archivo_datos]
 *   modo: enviar | aprobar | editar | bloquear
 *
 * Solo trabaja sobre reservas_cuv_concurrencia_edicion_test: antes de tocar nada comprueba
 * CONCURRENCIA_EDICION_DB y DATABASE() en la conexión que va a usar. Nunca imprime credenciales.
 * Los eventos (con su hora en microsegundos) se escriben, uno por línea en JSON, en <archivo_eventos>.
 */

use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const BASE_AUTORIZADA = 'reservas_cuv_concurrencia_edicion_test';
const BASES_PROTEGIDAS = ['reservas_cuv_2', 'reservas_cuv_ui_test', 'reservas_cuv_test', 'reservas_cuv_concurrencia_h7b_test'];

[, $modo, $reservaId, $usuarioId, $segundos, $archivoEventos] = $argv;
$archivoDatos = $argv[6] ?? null;

$evento = function (string $nombre, array $extra = []) use ($archivoEventos): void {
    file_put_contents($archivoEventos, json_encode(['evento' => $nombre, 't' => microtime(true), ...$extra], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
};

try {
    if (getenv('CONCURRENCIA_EDICION_DB') !== BASE_AUTORIZADA || getenv('DB_DATABASE') !== BASE_AUTORIZADA) {
        $evento('CANCELADO', ['motivo' => 'variables de entorno distintas de la base autorizada']);
        exit(2);
    }

    require __DIR__.'/../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if ($app->configurationIsCached()) {
        $evento('CANCELADO', ['motivo' => 'configuración en caché']);
        exit(2);
    }

    // Conexión explícita: sin URL heredada y con la base autorizada.
    config([
        'database.connections.mysql.url' => null,
        'database.connections.mysql.database' => BASE_AUTORIZADA,
        'database.default' => 'mysql',
    ]);
    DB::purge('mysql');

    $db = DB::connection('mysql');
    $actual = $db->selectOne('SELECT DATABASE() AS db')->db;

    if ($actual !== BASE_AUTORIZADA || in_array($actual, BASES_PROTEGIDAS, true) || $db->getDatabaseName() !== BASE_AUTORIZADA) {
        $evento('CANCELADO', ['motivo' => "DATABASE()='{$actual}' no es la base autorizada"]);
        exit(3);
    }

    // Nunca esperar indefinidamente un bloqueo.
    $db->statement('SET SESSION innodb_lock_wait_timeout = 10');
    $evento('INICIO', ['db' => $actual, 'modo' => $modo]);

    $reserva = Reserva::query()->findOrFail((int) $reservaId);
    $usuario = User::query()->findOrFail((int) $usuarioId);
    $datos = $archivoDatos !== null ? json_decode((string) file_get_contents($archivoDatos), true) : null;

    $db->beginTransaction();

    match ($modo) {
        'enviar' => app(W::class)->ejecutar($reserva, $usuario, W::ENVIAR),
        'aprobar' => app(W::class)->ejecutar($reserva, $usuario, W::APROBAR),
        'editar' => app(ReservaService::class)->actualizar($reserva, $usuario, $datos['datos'], $datos['detalles']),
        'bloquear' => Reserva::query()->whereKey($reserva->id)->lockForUpdate()->firstOrFail(),
    };

    // La operación ya terminó DENTRO de la transacción externa: la fila sigue bloqueada.
    $evento('BLOQUEADO');
    usleep((int) ((float) $segundos * 1_000_000));

    if ($modo === 'bloquear') {
        $db->rollBack();
        $evento('LIBERADO', ['como' => 'rollback']);
    } else {
        $db->commit();
        $evento('LIBERADO', ['como' => 'commit']);
    }

    exit(0);
} catch (Throwable $e) {
    $evento('ERROR', ['clase' => $e::class, 'mensaje' => mb_substr($e->getMessage(), 0, 300)]);
    exit(1);
}
