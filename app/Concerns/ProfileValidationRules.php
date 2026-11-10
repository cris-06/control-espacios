<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

trait ProfileValidationRules
{
    /**
     * Reglas para los datos de perfil que el propio usuario puede editar.
     * El correo, el rol y el estatus los administra el admin.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ];
    }
}
