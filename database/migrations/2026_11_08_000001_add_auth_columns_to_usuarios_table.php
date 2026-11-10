<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `usuarios` las columnas que necesitan Laravel/Fortify
 * (verificación de correo, 2FA, "recordarme" y updated_at).
 * Cada columna se agrega solo si no existe; todas son NULL, no tocan datos.
 */
return new class extends Migration
{
    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnas(): array
    {
        return [
            'email_verified_at' => fn (Blueprint $t) => $t->timestamp('email_verified_at')->nullable(),
            'two_factor_secret' => fn (Blueprint $t) => $t->text('two_factor_secret')->nullable(),
            'two_factor_recovery_codes' => fn (Blueprint $t) => $t->text('two_factor_recovery_codes')->nullable(),
            'two_factor_confirmed_at' => fn (Blueprint $t) => $t->timestamp('two_factor_confirmed_at')->nullable(),
            'remember_token' => fn (Blueprint $t) => $t->string('remember_token', 100)->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ];
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $faltantes = array_filter(
            $this->columnas(),
            fn (string $columna) => ! Schema::hasColumn('usuarios', $columna),
            ARRAY_FILTER_USE_KEY,
        );

        if ($faltantes === []) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) use ($faltantes) {
            foreach ($faltantes as $definir) {
                $definir($table);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $existentes = array_values(array_filter(
            array_keys($this->columnas()),
            fn (string $columna) => Schema::hasColumn('usuarios', $columna),
        ));

        if ($existentes === []) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) use ($existentes) {
            $table->dropColumn($existentes);
        });
    }
};
