<?php

namespace Database\Factories;

use App\Models\Actividad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Actividad>
 */
class ActividadFactory extends Factory
{
    protected $model = Actividad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->randomElement(['Práctica', 'Conferencia', 'Taller', 'Examen', 'Foro']).' '.fake()->unique()->numberBetween(1, 9999),
            'estatus' => 'activa',
        ];
    }
}
