<?php

namespace Database\Factories;

use App\Models\Licenciatura;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Licenciatura>
 */
class LicenciaturaFactory extends Factory
{
    protected $model = Licenciatura::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Licenciatura en '.fake()->unique()->jobTitle(),
            'estatus' => 'activa',
        ];
    }
}
