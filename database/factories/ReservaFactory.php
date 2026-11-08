<?php

namespace Database\Factories;

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * Reserva completa (pasa las validaciones estrictas si se le agrega un detalle válido).
 *
 * @extends Factory<Reserva>
 */
class ReservaFactory extends Factory
{
    protected $model = Reserva::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'usuario_creador_id' => User::factory()->rol('docente'),
            'licenciatura_id' => Licenciatura::factory(),
            'actividad_id' => Actividad::factory(),
            'nombre_actividad' => fake()->sentence(4),
            'objetivo' => fake()->sentence(10),
            'estado_id' => EstadoReserva::BORRADOR,
        ];
    }

    public function enEstado(int $estadoId): static
    {
        return $this->state(fn (array $attributes) => ['estado_id' => $estadoId]);
    }

    /**
     * Agrega un detalle (por defecto: mañana de 10:00 a 12:00, 20 alumnos).
     *
     * @param  array<string, mixed>  $datos
     */
    public function conDetalle(array $datos = []): static
    {
        return $this->afterCreating(function (Reserva $reserva) use ($datos): void {
            $detalle = new ReservaDetalle([
                'espacio_id' => $datos['espacio_id'] ?? Espacio::factory()->create()->id,
                'fecha' => $datos['fecha'] ?? Date::tomorrow()->toDateString(),
                'hora_inicio' => $datos['hora_inicio'] ?? '10:00:00',
                'hora_fin' => $datos['hora_fin'] ?? '12:00:00',
                'cantidad_alumnos' => array_key_exists('cantidad_alumnos', $datos) ? $datos['cantidad_alumnos'] : 20,
            ]);
            $detalle->reserva_id = $reserva->id;
            $detalle->estado_detalle = $datos['estado_detalle'] ?? ReservaDetalle::PENDIENTE;
            $detalle->save();
        });
    }
}
