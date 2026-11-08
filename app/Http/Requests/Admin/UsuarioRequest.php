<?php

namespace App\Http\Requests\Admin;

use App\Models\Licenciatura;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos de un usuario al crearlo o editarlo desde administración.
 * La autorización (quién puede tocar a quién) la hace UserPolicy en el controlador.
 */
class UsuarioRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'correo' => is_string($this->correo) ? mb_strtolower(trim($this->correo)) : $this->correo,
            'licenciaturas' => $this->input('licenciaturas', []),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $actual */
        $actual = $this->user();
        $objetivo = $this->objetivo();

        // Licenciaturas activas, más las que el usuario ya tenía (aunque se hayan inactivado).
        $licenciaturasPermitidas = Licenciatura::query()
            ->where('estatus', 'activa')
            ->pluck('id')
            ->merge($objetivo?->licenciaturas()->pluck('licenciaturas.id') ?? [])
            ->unique()
            ->all();

        return [
            'nombre' => ['required', 'string', 'max:100'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['nullable', 'string', 'max:100'],
            'correo' => [
                'required', 'string', 'email', 'max:150',
                Rule::unique('usuarios', 'correo')->ignore($objetivo?->id),
            ],
            'telefono' => ['nullable', 'string', 'max:20'],
            'rol_id' => ['required', 'integer', Rule::in(Rol::query()->asignablesPor($actual)->pluck('id')->all())],
            'licenciaturas' => ['array'],
            'licenciaturas.*' => ['integer', 'distinct', Rule::in($licenciaturasPermitidas)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $objetivo = $this->objetivo();

                // Nadie cambia su propio rol (evita auto-ascensos y quedarse sin acceso).
                if ($objetivo !== null && $objetivo->is($this->user()) && (int) $this->input('rol_id') !== $objetivo->rol_id) {
                    $validator->errors()->add('rol_id', 'No puedes cambiar tu propio rol.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rol_id.in' => 'No puedes asignar un rol de nivel mayor al tuyo.',
            'licenciaturas.*.in' => 'Alguna licenciatura seleccionada no existe o está inactiva.',
            'correo.unique' => 'Ya existe un usuario con este correo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'apellido_paterno' => 'apellido paterno',
            'apellido_materno' => 'apellido materno',
            'correo' => 'correo',
            'telefono' => 'teléfono',
            'rol_id' => 'rol',
        ];
    }

    /**
     * Rol elegido, ya validado.
     */
    public function rol(): Rol
    {
        return Rol::findOrFail($this->integer('rol_id'));
    }

    /**
     * Ids de licenciaturas a sincronizar: solo docentes y coordinadores tienen.
     *
     * @return list<int>
     */
    public function licenciaturasParaSincronizar(): array
    {
        if (! $this->rol()->usaLicenciaturas()) {
            return [];
        }

        return array_values(array_map('intval', $this->input('licenciaturas', [])));
    }

    /**
     * Usuario que se edita (null al crear).
     */
    private function objetivo(): ?User
    {
        $usuario = $this->route('usuario');

        return $usuario instanceof User ? $usuario : null;
    }
}
