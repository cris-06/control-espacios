<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 'editada_admin' AL FINAL del ENUM historial_reservas.accion, para registrar la
 * edición directa de administración (directivo) en una solicitud pendiente de autorización.
 *
 * Mismo valor que 2026_11_08_000003 (editada_coordinador), de la que depende: conserva los
 * nueve valores anteriores en el mismo orden y agrega el nuevo al final, así que los registros
 * existentes no cambian. Si el valor ya existe, no hace nada.
 *
 * MySQL / MariaDB (probado en MySQL 8.0.46 en una base aislada):
 * - El ALTER pide ALGORITHM=INSTANT, LOCK=DEFAULT: solo cambia metadatos. Si el servidor no
 *   puede hacerlo así (p. ej. otro orden de valores), la migración FALLA con un mensaje claro
 *   y no se reintenta sin INSTANT: nunca reconstruye la tabla en silencio.
 * - Antes del ALTER se limita lock_wait_timeout de la sesión a 10 s (y se restaura siempre):
 *   si otra transacción tiene la tabla abierta, falla en vez de dejar en cola las consultas
 *   al historial durante la espera.
 * SQLite (pruebas automatizadas): Schema::change(), como antes.
 *
 * Despliegue: aplicarla ANTES de activar el código de edición del directivo, con respaldo y en
 * un horario sin actividad; sin ella, MySQL rechazaría el registro 'editada_admin'.
 */
return new class extends Migration
{
    private const ANTERIORES = [
        'creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador',
        'enviada_admin', 'autorizada', 'denegada', 'cancelada', 'editada_coordinador',
    ];

    private const NUEVA = 'editada_admin';

    /** Tiempo máximo (segundos) que el ALTER espera el bloqueo de metadatos de la tabla. */
    private const ESPERA_BLOQUEO = 10;

    /** Errores de MySQL: INSTANT no admitido (1845/1846) y espera de bloqueo agotada (1205). */
    private const ERRORES_INSTANT = [1845, 1846];

    private const ERROR_TIEMPO_AGOTADO = 1205;

    private string $tabla = 'historial_reservas';

    public function up(): void
    {
        if ($this->tipoActual() !== null && str_contains((string) $this->tipoActual(), "'".self::NUEVA."'")) {
            return;
        }

        if (! $this->esMysql()) {
            Schema::table($this->tabla, function (Blueprint $table) {
                $table->enum('accion', [...self::ANTERIORES, self::NUEVA])->change();
            });

            return;
        }

        $this->conEsperaLimitada(function (): void {
            try {
                DB::statement($this->sentenciaAgregar());
            } catch (QueryException $e) {
                throw $this->explicar($e, 'agregar '.self::NUEVA);
            }
        });
    }

    /**
     * Quitar un valor de un ENUM NO puede ser instantáneo: MySQL reconstruye la tabla completa
     * (ALGORITHM=COPY) y bloquea las escrituras mientras dura, así que debe hacerse en un horario
     * sin actividad. Nunca borra, convierte ni modifica registros:
     * - si alguno usa 'editada_admin', se niega;
     * - en MySQL exige un modo estricto (STRICT_TRANS_TABLES o STRICT_ALL_TABLES): así, si entre
     *   la comprobación y el ALTER se registrara un 'editada_admin', MySQL rechaza el cambio
     *   (error 1265) en lugar de dejar el valor vacío sin avisar.
     */
    public function down(): void
    {
        if (DB::table($this->tabla)->where('accion', self::NUEVA)->exists()) {
            throw new RuntimeException('Hay registros con la acción '.self::NUEVA.'; no se puede quitar del ENUM sin perder datos.');
        }

        if (! $this->esMysql()) {
            Schema::table($this->tabla, function (Blueprint $table) {
                $table->enum('accion', self::ANTERIORES)->change();
            });

            return;
        }

        $modo = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS modo')->modo;

        if (! str_contains($modo, 'STRICT_TRANS_TABLES') && ! str_contains($modo, 'STRICT_ALL_TABLES')) {
            throw new RuntimeException('No se revierte '.self::NUEVA.': la sesión de MySQL no tiene un modo estricto (STRICT_TRANS_TABLES o STRICT_ALL_TABLES); sin él, un registro con ese valor quedaría vacío sin aviso.');
        }

        $this->conEsperaLimitada(function (): void {
            try {
                DB::statement($this->sentenciaQuitar());
            } catch (QueryException $e) {
                throw $this->explicar($e, 'quitar '.self::NUEVA);
            }
        });
    }

    /** ALTER de up(): los nueve valores anteriores, en su orden, más el nuevo al final. */
    private function sentenciaAgregar(): string
    {
        return $this->sentencia([...self::ANTERIORES, self::NUEVA], 'ALGORITHM=INSTANT, LOCK=DEFAULT');
    }

    /** ALTER de down(): reconstrucción explícita; LOCK=SHARED permite lecturas, no escrituras. */
    private function sentenciaQuitar(): string
    {
        return $this->sentencia(self::ANTERIORES, 'ALGORITHM=COPY, LOCK=SHARED');
    }

    /**
     * @param  list<string>  $valores  constantes de esta clase (sin datos externos)
     */
    private function sentencia(array $valores, string $algoritmo): string
    {
        $lista = implode(', ', array_map(fn (string $v): string => "'".$v."'", $valores));

        return "ALTER TABLE `{$this->tabla}` MODIFY `accion` ENUM({$lista}) NOT NULL, {$algoritmo}";
    }

    /**
     * Ejecuta $operacion con lock_wait_timeout de sesión limitado y lo restaura siempre.
     */
    private function conEsperaLimitada(Closure $operacion): void
    {
        $anterior = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS espera')->espera;
        DB::statement('SET SESSION lock_wait_timeout = '.self::ESPERA_BLOQUEO);

        try {
            $operacion();
        } finally {
            DB::statement('SET SESSION lock_wait_timeout = '.$anterior);
        }
    }

    private function explicar(QueryException $e, string $operacion): RuntimeException
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0);

        return match (true) {
            in_array($codigo, self::ERRORES_INSTANT, true) => new RuntimeException(
                "No se pudo {$operacion} sin reconstruir {$this->tabla}: MySQL no admite ALGORITHM=INSTANT para este cambio (¿los valores del ENUM están en otro orden?). No se modificó nada ni se reintenta con otro algoritmo; revisa la definición de la columna.",
                $codigo,
                $e,
            ),
            $codigo === self::ERROR_TIEMPO_AGOTADO => new RuntimeException(
                "No se pudo {$operacion}: otra transacción mantiene abierta {$this->tabla} y se agotó la espera de ".self::ESPERA_BLOQUEO.' s. No se modificó nada; repite la migración cuando no haya actividad.',
                $codigo,
                $e,
            ),
            default => new RuntimeException("No se pudo {$operacion} en {$this->tabla}: ".$e->getMessage(), $codigo, $e),
        };
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Definición actual de la columna en MySQL (p. ej. "enum('creada',...)").
     * En SQLite el tipo es varchar con CHECK, así que se aplica el cambio siempre.
     */
    private function tipoActual(): ?string
    {
        foreach (Schema::getColumns($this->tabla) as $columna) {
            if ($columna['name'] === 'accion') {
                return str_starts_with((string) $columna['type'], 'enum(') ? (string) $columna['type'] : null;
            }
        }

        return null;
    }
};
