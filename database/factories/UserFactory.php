<?php

namespace Database\Factories;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Genera registros para la tabla `usuarios`.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellido_paterno' => fake()->lastName(),
            'apellido_materno' => fake()->lastName(),
            'correo' => fake()->unique()->safeEmail(),
            'telefono' => fake()->numerify('##########'),
            'password' => static::$password ??= Hash::make('password'),
            'rol_id' => fn () => Rol::where('nombre', 'docente')->value('id'),
            'estatus' => 'activo',
            'debe_cambiar_password' => false,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Usuario con el rol indicado (superadmin, admin, coordinador o docente).
     */
    public function rol(string $nombre): static
    {
        return $this->state(fn (array $attributes) => [
            'rol_id' => Rol::where('nombre', $nombre)->value('id'),
        ]);
    }

    /**
     * Usuario con estatus inactivo (no puede iniciar sesión).
     */
    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'estatus' => 'inactivo',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
