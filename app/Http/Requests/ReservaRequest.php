<?php

namespace App\Http\Requests;

use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guardar una reserva (crear o editar). Es intencionalmente flexible: un docente
 * puede guardar una propuesta incompleta. Lo estricto se exige al enviar a
 * administración (ReservaWorkflowService::validarCompleta). Los permisos vienen
 * de ReservaPolicy (authorize()).
 *
 * @phpstan-import-type DatosDetalle from ReservaService
 */
class ReservaRequest extends FormRequest
{
    /**
     * Se autoriza antes de validar: quien no puede crear/editar recibe 403, no errores de formulario.
     */
    public function authorize(): bool
    {
        $reserva = $this->route('reserva');

        return $reserva instanceof Reserva
            ? (bool) $this->user()?->can('update', $reserva)
            : (bool) $this->user()?->can('create', Reserva::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'detalles' => array_values((array) $this->input('detalles', [])),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $reserva = $this->route('reserva');
        $detallesExistentes = $reserva instanceof Reserva ? $reserva->detalles()->pluck('id')->all() : [];

        return [
            'licenciatura_id' => ['required', 'integer', Rule::in($user->licenciaturas()->pluck('licenciaturas.id')->all())],
            // actividad_id es NOT NULL en la base: hasta un borrador necesita el tipo de actividad.
            'actividad_id' => ['required', 'integer', Rule::exists('actividades', 'id')->where('estatus', 'activa')],
            'nombre_actividad' => ['nullable', 'string', 'max:255'],
            'objetivo' => ['nullable', 'string', 'max:5000'],
            'tipo_formato' => ['nullable', Rule::in(['normal', 'ext'])],
            'cuatrimestre_id' => ['nullable', 'integer', Rule::exists('cuatrimestres', 'id')],
            'asignatura_id' => [
                'nullable', 'integer',
                Rule::exists('asignaturas', 'id')->where('licenciatura_id', (int) $this->input('licenciatura_id')),
            ],
            'costo_alumno' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'promocion_redes' => ['nullable', Rule::in(['si', 'no'])],
            'limite_min_usuarios' => ['nullable', 'integer', 'min:0'],
            'limite_max_usuarios' => ['nullable', 'integer', 'min:0', 'gte:limite_min_usuarios'],
            'fecha_entrega_formato' => ['nullable', 'date_format:Y-m-d'],

            'detalles' => ['array', 'max:20'],
            'detalles.*.id' => ['nullable', 'integer', Rule::in($detallesExistentes)],
            'detalles.*.espacio_id' => ['required', 'integer', Rule::exists('espacios', 'id')],
            'detalles.*.fecha' => ['required', 'date_format:Y-m-d'],
            'detalles.*.hora_inicio' => ['required', 'date_format:H:i'],
            'detalles.*.hora_fin' => ['required', 'date_format:H:i', 'after:detalles.*.hora_inicio'],
            'detalles.*.cantidad_alumnos' => ['nullable', 'integer', 'min:1'],
            'detalles.*.lugar_especifico' => ['nullable', 'string', 'max:150'],
            'detalles.*.descripcion_actividad' => ['nullable', 'string', 'max:5000'],
            'detalles.*.material_requerido' => ['nullable', 'string', 'max:5000'],
            'detalles.*.observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'licenciatura_id.required' => 'Selecciona la licenciatura.',
            'licenciatura_id.in' => 'Solo puedes registrar reservas de tus licenciaturas.',
            'actividad_id.required' => 'Selecciona el tipo de actividad.',
            'actividad_id.exists' => 'El tipo de actividad no existe o ya no está vigente.',
            'asignatura_id.exists' => 'La asignatura no pertenece a la licenciatura seleccionada.',
            'limite_max_usuarios.gte' => 'El máximo no puede ser menor que el mínimo.',
            'detalles.*.espacio_id.required' => 'Selecciona el espacio.',
            'detalles.*.espacio_id.exists' => 'El espacio no existe.',
            'detalles.*.fecha.required' => 'Indica la fecha.',
            'detalles.*.fecha.date_format' => 'La fecha no es válida.',
            'detalles.*.hora_inicio.required' => 'Indica la hora de inicio.',
            'detalles.*.hora_inicio.date_format' => 'La hora de inicio no es válida.',
            'detalles.*.hora_fin.required' => 'Indica la hora de fin.',
            'detalles.*.hora_fin.date_format' => 'La hora de fin no es válida.',
            'detalles.*.hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio.',
            'detalles.*.cantidad_alumnos.min' => 'La cantidad de alumnos debe ser al menos 1.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datosReserva(): array
    {
        return $this->safe()->except('detalles');
    }

    /**
     * Detalles validados con sus tipos (los formularios envían los ids como texto).
     *
     * @return list<DatosDetalle>
     */
    public function datosDetalles(): array
    {
        $texto = fn (mixed $valor): ?string => is_string($valor) && trim($valor) !== '' ? $valor : null;
        $entero = fn (mixed $valor): ?int => is_numeric($valor) ? (int) $valor : null;

        $detalles = [];

        foreach ((array) $this->validated('detalles', []) as $detalle) {
            $detalles[] = [
                'id' => $entero($detalle['id'] ?? null),
                'espacio_id' => (int) $detalle['espacio_id'],
                'fecha' => (string) $detalle['fecha'],
                'hora_inicio' => (string) $detalle['hora_inicio'],
                'hora_fin' => (string) $detalle['hora_fin'],
                'cantidad_alumnos' => $entero($detalle['cantidad_alumnos'] ?? null),
                'lugar_especifico' => $texto($detalle['lugar_especifico'] ?? null),
                'descripcion_actividad' => $texto($detalle['descripcion_actividad'] ?? null),
                'material_requerido' => $texto($detalle['material_requerido'] ?? null),
                'observaciones' => $texto($detalle['observaciones'] ?? null),
            ];
        }

        return $detalles;
    }
}
