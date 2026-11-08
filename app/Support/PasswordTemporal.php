<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Contraseñas temporales que administración entrega a un usuario. Se muestran
 * una sola vez y el usuario debe cambiarlas al entrar (debe_cambiar_password = 1).
 */
class PasswordTemporal
{
    public static function generar(): string
    {
        // Sin símbolos para poder dictarla o copiarla sin confusiones.
        return Str::password(12, symbols: false);
    }
}
