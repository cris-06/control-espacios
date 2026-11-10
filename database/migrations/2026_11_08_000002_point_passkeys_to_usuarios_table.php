<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En la base existente, passkeys.user_id es BIGINT UNSIGNED con llave foránea
 * hacia users.id. Esta migración la redirige a usuarios.id:
 *   1. elimina la llave foránea hacia `users`
 *   2. cambia user_id a INT con signo (mismo tipo que usuarios.id)
 *   3. crea la llave foránea hacia usuarios.id (ON DELETE CASCADE)
 *
 * Si la llave ya apunta a `usuarios` (instalación nueva), no hace nada.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->llaveHacia('usuarios')) {
            return;
        }

        $llaveUsers = $this->llaveHacia('users');

        Schema::table('passkeys', function (Blueprint $table) use ($llaveUsers) {
            if ($llaveUsers !== null) {
                $table->dropForeign($llaveUsers);
            }
        });

        Schema::table('passkeys', function (Blueprint $table) {
            $table->integer('user_id')->change();
            $table->foreign('user_id')->references('id')->on('usuarios')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $llaveUsuarios = $this->llaveHacia('usuarios');

        if ($llaveUsuarios === null) {
            return;
        }

        Schema::table('passkeys', function (Blueprint $table) use ($llaveUsuarios) {
            $table->dropForeign($llaveUsuarios);
        });

        Schema::table('passkeys', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->change();

            if (Schema::hasTable('users')) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            }
        });
    }

    /**
     * Nombre de la llave foránea de passkeys.user_id hacia la tabla dada, o null.
     */
    private function llaveHacia(string $tabla): ?string
    {
        foreach (Schema::getForeignKeys('passkeys') as $llave) {
            if ($llave['columns'] === ['user_id'] && $llave['foreign_table'] === $tabla) {
                return $llave['name'];
            }
        }

        return null;
    }
};
