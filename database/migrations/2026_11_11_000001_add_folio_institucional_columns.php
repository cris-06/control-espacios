<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formato 10: folio institucional {SIGLAS}-{AAAAMMDD}-{ID}.
 *
 * - licenciaturas.siglas: siglas oficiales (las registra administración). NULL = sin siglas.
 * - reservas.folio_institucional: se genera una sola vez al crear la reserva, si su
 *   licenciatura tiene siglas. NULL = solo tiene folio RES (reservas anteriores incluidas).
 *
 * Solo agrega columnas NULL (compatible con los registros existentes) y no rellena datos:
 * no se inventan siglas ni se asignan folios retroactivamente. UNIQUE admite varios NULL
 * tanto en MySQL como en SQLite. Cada columna se agrega solo si no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('licenciaturas', 'siglas')) {
            Schema::table('licenciaturas', function (Blueprint $table) {
                $table->string('siglas', 10)->nullable()->after('nombre');
                $table->unique('siglas', 'uq_licenciaturas_siglas');
            });
        }

        if (! Schema::hasColumn('reservas', 'folio_institucional')) {
            Schema::table('reservas', function (Blueprint $table) {
                $table->string('folio_institucional', 40)->nullable()->after('folio');
                $table->unique('folio_institucional', 'uq_reservas_folio_inst');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservas', 'folio_institucional')) {
            Schema::table('reservas', function (Blueprint $table) {
                $table->dropUnique('uq_reservas_folio_inst');
                $table->dropColumn('folio_institucional');
            });
        }

        if (Schema::hasColumn('licenciaturas', 'siglas')) {
            Schema::table('licenciaturas', function (Blueprint $table) {
                $table->dropUnique('uq_licenciaturas_siglas');
                $table->dropColumn('siglas');
            });
        }
    }
};
