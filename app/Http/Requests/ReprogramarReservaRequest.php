<?php

namespace App\Http\Requests;

use App\Models\Reserva;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reprogramación de un bloque de una reserva autorizada (PATCH reservas/{reserva}/reprogramar).
 * Aquí se valida la forma de los datos; disponibilidad, capacidad y choques con otras
 * reservas autorizadas los valida ReservaWorkflowService::reprogramar dentro de la transacción.
 */
class ReprogramarReservaRequest extends FormRequest
{
    /**
     * Solo administración, sobre una reserva autorizada cuya fecha no haya pasado
     * (ReservaPolicy::reprogramar). El servicio lo vuelve a comprobar con la fila bloqueada.
     */
    public function authorize(): bool
    {
        $reserva = $this->route('reserva');

        return $reserva instanceof Reserva && ($this->user()?->can('reprogramar', $reserva) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Reserva $reserva */
        $reserva = $this->route('reserva');

        return [
            'detalle_id' => ['required', 'integer', Rule::exists('reserva_detalles', 'id')->where('reserva_id', $reserva->id)],
            'espacio_id' => ['required', 'integer', Rule::exists('espacios', 'id')],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'motivo' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'detalle_id.required' => 'Selecciona el bloque que quieres reprogramar.',
            'detalle_id.exists' => 'El bloque no pertenece a esta reserva.',
            'espacio_id.required' => 'Selecciona un espacio.',
            'espacio_id.exists' => 'El espacio seleccionado no existe.',
            'fecha.required' => 'Indica la nueva fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
            'fecha.after_or_equal' => 'La nueva fecha no puede haber pasado.',
            'hora_inicio.required' => 'Indica la hora de inicio.',
            'hora_inicio.date_format' => 'La hora de inicio no es válida (HH:MM).',
            'hora_fin.required' => 'Indica la hora de fin.',
            'hora_fin.date_format' => 'La hora de fin no es válida (HH:MM).',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio.',
            'motivo.required' => 'Escribe el motivo: es obligatorio para reprogramar.',
            'motivo.max' => 'El motivo no puede pasar de 5000 caracteres.',
        ];
    }
}
