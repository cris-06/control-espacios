<?php

namespace Database\Factories;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellido_paterno' => fake()->lastName(),
            'apellido_materno' => fake()->lastName(),
            'correo' => fake()->unique()->safeEmail(),
            'telefono' => fake()->numerify('##########'),
            'password' => 'password', // el cast 'hashed' del modelo la encripta
            'rol_id' => Rol::where('nombre', 'docente')->value('id'),
            'estatus' => 'activo',
            'debe_cambiar_password' => false,
        ];
    }
}
