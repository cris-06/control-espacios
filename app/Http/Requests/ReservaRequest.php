<?php

namespace App\Http\Requests;

use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaService;
use App\Services\ValidacionDetalleReserva;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Sin reglas para folio, fecha_creacion ni fecha_entrega_formato: las asigna el servidor
            // (al crear y al primer envío) y, al no validarse, nunca llegan a safe()/datosReserva().

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
            'detalles.*.docente_responsable_id' => ['nullable', 'integer'],
            'detalles.*.requerimientos' => ['nullable', 'array', 'max:30'],
            // Sin `distinct`: con comodines anidados compararía contra TODOS los bloques, y el
            // mismo requerimiento sí puede pedirse en bloques distintos. Se revisa por bloque en after().
            'detalles.*.requerimientos.*.requerimiento_id' => ['required', 'integer'],
            'detalles.*.requerimientos.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * Integridad que depende de otros datos (docente de la licenciatura, requerimientos
     * del espacio). Solo se revisa lo que ya pasó las reglas básicas.
     *
     * @return list<callable>
     */
    public function after(ValidacionDetalleReserva $validacion): array
    {
        return [function (Validator $validator) use ($validacion): void {
            $errores = $validator->errors();
            $licenciaturaId = (int) $this->input('licenciatura_id');

            foreach ((array) $this->input('detalles', []) as $i => $detalle) {
                if (! is_array($detalle)) {
                    continue;
                }

                $docenteId = $detalle['docente_responsable_id'] ?? null;

                if (is_numeric($docenteId) && ! $errores->has('licenciatura_id') && ! $errores->has("detalles.$i.docente_responsable_id")) {
                    $error = $validacion->errorDocente((int) $docenteId, $licenciaturaId);

                    if ($error !== null) {
                        $errores->add("detalles.$i.docente_responsable_id", $error);
                    }
                }

                $requerimientos = $detalle['requerimientos'] ?? null;

                if (is_array($requerimientos)) {
                    $this->marcarRepetidos($requerimientos, "detalles.$i.requerimientos", $errores);
                }

                $conErrores = collect($errores->keys())->contains(
                    fn (string $clave): bool => str_starts_with($clave, "detalles.$i.requerimientos") || $clave === "detalles.$i.espacio_id",
                );

                if (! is_array($requerimientos) || $requerimientos === [] || $conErrores) {
                    continue;
                }

                $fallos = $validacion->erroresRequerimientos((int) $detalle['espacio_id'], $this->normalizarRequerimientos($requerimientos));

                foreach ($fallos as $j => $mensaje) {
                    $errores->add("detalles.$i.requerimientos.$j", $mensaje);
                }
            }
        }];
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
            'detalles.*.docente_responsable_id.integer' => 'Selecciona un docente responsable válido.',
            'detalles.*.requerimientos.max' => 'Son demasiados requerimientos para un solo espacio.',
            'detalles.*.requerimientos.*.requerimiento_id.required' => 'Selecciona el requerimiento.',
            'detalles.*.requerimientos.*.cantidad.required' => 'Indica la cantidad.',
            'detalles.*.requerimientos.*.cantidad.min' => 'La cantidad debe ser al menos 1.',
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
                'docente_responsable_id' => $entero($detalle['docente_responsable_id'] ?? null),
                // null = no se enviaron: se conservan los requerimientos guardados.
                'requerimientos' => array_key_exists('requerimientos', $detalle)
                    ? $this->normalizarRequerimientos((array) $detalle['requerimientos'])
                    : null,
            ];
        }

        return $detalles;
    }

    /**
     * Un requerimiento no puede repetirse DENTRO de un mismo bloque (en bloques distintos
     * sí). Se marcan todas sus apariciones, como hacía la regla `distinct`.
     *
     * @param  array<mixed>  $requerimientos
     */
    private function marcarRepetidos(array $requerimientos, string $prefijo, MessageBag $errores): void
    {
        $ids = [];

        foreach ($requerimientos as $j => $requerimiento) {
            $id = is_array($requerimiento) ? ($requerimiento['requerimiento_id'] ?? null) : null;

            if (is_numeric($id)) {
                $ids[$j] = (string) (int) $id;
            }
        }

        $veces = array_count_values($ids);

        foreach ($ids as $j => $id) {
            $clave = "$prefijo.$j.requerimiento_id";

            if ($veces[$id] > 1 && ! $errores->has($clave)) {
                $errores->add($clave, 'El requerimiento está repetido.');
            }
        }
    }

    /**
     * @param  array<mixed>  $requerimientos
     * @return list<array{requerimiento_id: int, cantidad: int}>
     */
    private function normalizarRequerimientos(array $requerimientos): array
    {
        return array_values(array_map(fn (mixed $r): array => [
            'requerimiento_id' => (int) (is_array($r) ? ($r['requerimiento_id'] ?? 0) : 0),
            'cantidad' => (int) (is_array($r) ? ($r['cantidad'] ?? 0) : 0),
        ], $requerimientos));
    }
}
