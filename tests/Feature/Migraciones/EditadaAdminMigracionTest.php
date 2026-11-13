<?php

use App\Models\HistorialReserva as H;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Migración 2026_11_12_000001 (editada_admin). En SQLite se comprueban el comportamiento y la
 * idempotencia; el SQL de MySQL se obtiene de la propia migración (su ejecución real en MySQL
 * se verifica aparte, en una base aislada).
 */
beforeEach(function () {
    $this->migracion = require database_path('migrations/2026_11_12_000001_add_editada_admin_to_historial_reservas_accion.php');
    $this->privado = fn (string $metodo) => (new ReflectionMethod($this->migracion, $metodo))->invoke($this->migracion);
    $this->reserva = Reserva::factory()->create();
    $this->admin = User::factory()->rol('admin')->create();
});

test('the historial accepts editada_admin and every previous value', function () {
    foreach ([H::CREADA, H::ENVIADA_COORDINADOR, H::CORRECCION_SOLICITADA, H::APROBADA_COORDINADOR, H::ENVIADA_ADMIN, H::AUTORIZADA, H::DENEGADA, H::CANCELADA, H::EDITADA_COORDINADOR, H::EDITADA_ADMIN] as $accion) {
        $this->reserva->historial()->create(['usuario_id' => $this->admin->id, 'accion' => $accion]);
    }

    expect($this->reserva->historial()->count())->toBe(10);
});

test('running up() again keeps the rows and the column', function () {
    $this->reserva->historial()->create(['usuario_id' => $this->admin->id, 'accion' => H::EDITADA_ADMIN]);
    $antes = $this->reserva->historial()->get(['id', 'accion'])->toArray();

    $this->migracion->up();

    expect($this->reserva->historial()->get(['id', 'accion'])->toArray())->toBe($antes)
        ->and(Schema::hasColumn('historial_reservas', 'accion'))->toBeTrue();
});

test('down() refuses while a row uses editada_admin and changes nothing', function () {
    $this->reserva->historial()->create(['usuario_id' => $this->admin->id, 'accion' => H::EDITADA_ADMIN, 'comentario' => 'Evidencia']);

    expect(fn () => $this->migracion->down())->toThrow(RuntimeException::class, 'Hay registros con la acción editada_admin');
    expect($this->reserva->historial()->where('accion', H::EDITADA_ADMIN)->value('comentario'))->toBe('Evidencia');
});

test('the MySQL statement keeps the nine values in order, appends editada_admin and forces INSTANT', function () {
    $agregar = ($this->privado)('sentenciaAgregar');
    $quitar = ($this->privado)('sentenciaQuitar');
    $anteriores = "'creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador', 'enviada_admin', 'autorizada', 'denegada', 'cancelada', 'editada_coordinador'";

    expect($agregar)->toBe("ALTER TABLE `historial_reservas` MODIFY `accion` ENUM({$anteriores}, 'editada_admin') NOT NULL, ALGORITHM=INSTANT, LOCK=DEFAULT")
        ->and($agregar)->not->toContain('LOCK=NONE')
        ->and($quitar)->toBe("ALTER TABLE `historial_reservas` MODIFY `accion` ENUM({$anteriores}) NOT NULL, ALGORITHM=COPY, LOCK=SHARED");
});

test('the migration never retries without INSTANT and restores the lock timeout in a finally block', function () {
    $codigo = (string) file_get_contents(database_path('migrations/2026_11_12_000001_add_editada_admin_to_historial_reservas_accion.php'));

    // Un único ALTER de MySQL por operación (sin segundo intento con otro algoritmo).
    expect(substr_count($codigo, 'DB::statement($this->sentenciaAgregar())'))->toBe(1)
        ->and($codigo)->not->toContain('ALGORITHM=INPLACE')
        ->and($codigo)->toContain("SET SESSION lock_wait_timeout = '.self::ESPERA_BLOQUEO")
        ->and($codigo)->toMatch('/finally\s*\{\s*DB::statement\(\'SET SESSION lock_wait_timeout = \'\.\$anterior\);/');
});
