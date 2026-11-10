<?php

namespace Database\Factories;

use App\Models\Espacio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Espacio>
 */
class EspacioFactory extends Factory
{
    protected $model = Espacio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Aula '.fake()->unique()->numberBetween(100, 999),
            'ubicacion' => 'Edificio '.fake()->randomLetter(),
            'capacidad' => 40,
            'color_calendario' => '#dc2626',
            'estatus' => 'disponible',
        ];
    }
}
