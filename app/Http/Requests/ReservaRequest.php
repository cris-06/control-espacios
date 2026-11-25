<?php

namespace App\Http\Requests;

use App\Models\Cuatrimestre;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaLicenciatura;
use App\Models\User;
use App\Services\RecurrenciaReserva;
use App\Services\ReservaService;
use App\Services\ValidacionDetalleReserva;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Guardar una reserva (crear o editar). Desde el primer guardado se exigen el nombre de la
 * actividad, el objetivo y, en cada espacio, la cantidad de alumnos dentro del mínimo y el
 * máximo del espacio. El resto puede quedar incompleto en el borrador; lo estricto se exige al
 * enviar a administración (ReservaWorkflowService::validarCompleta). Los permisos vienen
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

        // Grupos de cada cuatrimestre: en mayúsculas, sin espacios ni repetidos.
        if (is_array($this->input('cuatrimestres'))) {
            $this->merge([
                'cuatrimestres' => array_map(fn (mixed $c): mixed => is_array($c) && is_array($c['grupos'] ?? null)
                    ? [...$c, 'grupos' => array_values(array_unique(array_map(
                        fn (mixed $g): mixed => is_string($g) ? mb_strtoupper(trim($g)) : $g,
                        $c['grupos'],
                    ), SORT_REGULAR))]
                    : $c, array_values($this->input('cuatrimestres'))),
            ]);
        }

        // Lo mismo para los grupos de cada licenciatura adicional.
        if (is_array($this->input('licenciaturas_adicionales'))) {
            $this->merge([
                'licenciaturas_adicionales' => array_map(fn (mixed $l): mixed => is_array($l) && is_array($l['cuatrimestres'] ?? null)
                    ? [...$l, 'cuatrimestres' => array_map(fn (mixed $c): mixed => is_array($c) && is_array($c['grupos'] ?? null)
                        ? [...$c, 'grupos' => array_values(array_unique(array_map(
                            fn (mixed $g): mixed => is_string($g) ? mb_strtoupper(trim($g)) : $g,
                            $c['grupos'],
                        ), SORT_REGULAR))]
                        : $c, array_values($l['cuatrimestres']))]
                    : $l, array_values($this->input('licenciaturas_adicionales'))),
            ]);
        }

        // Asignaturas libres: sin espacios sobrantes ni renglones vacíos.
        if (is_array($this->input('asignaturas'))) {
            $this->merge([
                'asignaturas' => array_values(array_filter(
                    array_map(fn (mixed $a): mixed => is_string($a) ? trim(preg_replace('/\s+/u', ' ', $a) ?? $a) : $a, $this->input('asignaturas')),
                    fn (mixed $a): bool => $a !== '' && $a !== null,
                )),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $reserva = $this->route('reserva');
        // Solo los bloques capturados: las fechas generadas por la repetición no se editan directamente.
        $detallesExistentes = $reserva instanceof Reserva ? $reserva->detalles()->whereNull('detalle_origen_id')->pluck('id')->all() : [];

        return [
            'licenciatura_id' => ['required', 'integer', Rule::in($this->licenciaturasPermitidas($user, $reserva))],
            // Motivo de la edición del directivo: solo se usa en esa operación (historial 'editada_admin').
            'motivo_edicion' => ['nullable', 'string', 'max:1000'],
            // actividad_id es NOT NULL en la base: hasta un borrador necesita el tipo de actividad.
            'actividad_id' => ['required', 'integer', Rule::exists('actividades', 'id')->where('estatus', 'activa')],
            'nombre_actividad' => ['required', 'string', 'max:255'],
            'objetivo' => ['required', 'string', 'max:5000'],
            // tipo_formato ya no se captura: la columna conserva su valor (por omisión 'normal').
            // Casillas de cuatrimestre (varios) y asignaturas escritas a mano (varias, opcionales).
            'cuatrimestres' => ['nullable', 'array', 'max:20'],
            'cuatrimestres.*' => ['array:id,grupos'],
            'cuatrimestres.*.id' => ['required', 'integer', 'distinct', Rule::exists('cuatrimestres', 'id')],
            'cuatrimestres.*.grupos' => ['nullable', 'array', 'max:26'],
            'cuatrimestres.*.grupos.*' => ['string', 'regex:/^[A-Z]$/'],
            'asignaturas' => ['nullable', 'array', 'max:10'],
            'asignaturas.*' => ['string', 'max:150', 'distinct:ignore_case'],
            'costo_alumno' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'promocion_redes' => ['nullable', Rule::in(['si', 'no'])],
            // Sin reglas para folio, folio_institucional, fecha_creacion ni fecha_entrega_formato: las
            // asigna el servidor (al crear y al primer envío) y, al no validarse, nunca llegan a
            // safe()/datosReserva().
            // Responsables: deben ser usuarios activos de la licenciatura (se comprueba en after()).
            'docente_responsable_id' => ['nullable', 'integer'],
            'coordinador_id' => ['nullable', 'integer'],
            // Alumnos distintos de toda la actividad (no la suma de los espacios; ver after()).
            'cantidad_total_alumnos' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'descripcion_actividad' => ['nullable', 'string', 'max:10000'],
            'observaciones' => ['nullable', 'string', 'max:5000'],

            // Licenciaturas que participan además de la principal (solo informativas; ver after()).
            'licenciaturas_adicionales' => ['nullable', 'array', 'max:10'],
            'licenciaturas_adicionales.*' => ['array:licenciatura_id,tipo,cuatrimestres'],
            'licenciaturas_adicionales.*.licenciatura_id' => ['required', 'integer'],
            'licenciaturas_adicionales.*.tipo' => ['required', Rule::in(ReservaLicenciatura::TIPOS)],
            'licenciaturas_adicionales.*.cuatrimestres' => ['nullable', 'array', 'max:20'],
            'licenciaturas_adicionales.*.cuatrimestres.*' => ['array:id,grupos'],
            'licenciaturas_adicionales.*.cuatrimestres.*.id' => ['required', 'integer', Rule::exists('cuatrimestres', 'id')],
            'licenciaturas_adicionales.*.cuatrimestres.*.grupos' => ['nullable', 'array', 'max:26'],
            'licenciaturas_adicionales.*.cuatrimestres.*.grupos.*' => ['string', 'regex:/^[A-Z]$/'],

            // «Esta reserva se repite» (fechas y límites en RecurrenciaReserva; ver after()).
            'repite' => ['nullable', 'boolean'],
            'repeticion_frecuencia' => ['nullable', 'required_if_accepted:repite', Rule::in(RecurrenciaReserva::FRECUENCIAS)],
            'repeticion_cantidad' => ['nullable', 'integer', 'min:1', 'max:'.RecurrenciaReserva::MAX_REPETICIONES],
            'repeticion_hasta' => ['nullable', 'date_format:Y-m-d'],

            'detalles' => ['array', 'max:20'],
            'detalles.*.id' => ['nullable', 'integer', Rule::in($detallesExistentes)],
            'detalles.*.espacio_id' => ['required', 'integer', Rule::exists('espacios', 'id')],
            'detalles.*.fecha' => ['required', 'date_format:Y-m-d'],
            'detalles.*.hora_inicio' => ['required', 'date_format:H:i'],
            'detalles.*.hora_fin' => ['required', 'date_format:H:i', 'after:detalles.*.hora_inicio'],
            'detalles.*.cantidad_alumnos' => ['required', 'integer', 'min:1'],
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
     * Licenciaturas que se pueden elegir. Docentes y coordinadores: solo las asignadas.
     * El directivo, al editar (la política solo se lo permite en pendiente_admin), NO puede
     * cambiar la licenciatura: solo se acepta la que ya tiene la reserva. Cambiarla podía dejar
     * la solicitud con un coordinador elegido al azar o sin coordinador, o impedir que la
     * docente la corrija y reenvíe. ReservaService lo vuelve a comprobar bajo bloqueo.
     *
     * @return list<int>
     */
    private function licenciaturasPermitidas(User $user, mixed $reserva): array
    {
        if ($this->esEdicionDelDirectivo($user, $reserva)) {
            /** @var Reserva $reserva */
            return [$reserva->licenciatura_id];
        }

        return array_values(array_map('intval', $user->licenciaturas()->pluck('licenciaturas.id')->all()));
    }

    private function esEdicionDelDirectivo(?User $user, mixed $reserva): bool
    {
        return $user !== null && $user->esAdministrador() && $reserva instanceof Reserva;
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

            $this->validarResponsables($validacion, $errores, $licenciaturaId);
            $this->validarTotalAlumnos($errores);
            $this->validarLicenciaturasAdicionales($errores, $licenciaturaId);
            $this->validarCuatrimestresDeLicenciaturas($errores, $licenciaturaId);
            $this->validarRepeticion($errores);

            foreach ((array) $this->input('detalles', []) as $i => $detalle) {
                if (! is_array($detalle)) {
                    continue;
                }

                $alumnos = $detalle['cantidad_alumnos'] ?? null;

                if (is_numeric($alumnos) && ! $errores->has("detalles.$i.espacio_id") && ! $errores->has("detalles.$i.cantidad_alumnos")) {
                    $error = $validacion->errorAlumnos((int) $detalle['espacio_id'], (int) $alumnos);

                    if ($error !== null) {
                        $errores->add("detalles.$i.cantidad_alumnos", $error);
                    }
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
     * Docente responsable de la actividad: docente o coordinador activo de la licenciatura (las
     * mismas reglas que el responsable de cada espacio). Coordinador responsable: un coordinador
     * activo de esa licenciatura; ReservaWorkflowService::asignarCoordinador lo vuelve a
     * confirmar al guardar. Ninguno de los dos concede permisos: los permisos siguen dependiendo
     * de la licenciatura (ReservaPolicy).
     */
    private function validarResponsables(ValidacionDetalleReserva $validacion, MessageBag $errores, int $licenciaturaId): void
    {
        if ($errores->has('licenciatura_id')) {
            return;
        }

        $docenteId = $this->input('docente_responsable_id');

        if (is_numeric($docenteId) && ! $errores->has('docente_responsable_id')) {
            $error = $validacion->errorDocente((int) $docenteId, $licenciaturaId);

            if ($error !== null) {
                $errores->add('docente_responsable_id', $error);
            }
        }

        $coordinadorId = $this->input('coordinador_id');

        if (is_numeric($coordinadorId) && ! $errores->has('coordinador_id')) {
            $valido = Licenciatura::query()->find($licenciaturaId)
                ?->coordinadores()
                ->where('usuarios.id', (int) $coordinadorId)
                ->exists() ?? false;

            if (! $valido) {
                $errores->add('coordinador_id', 'El coordinador responsable debe ser un coordinador activo de la licenciatura.');
            }
        }
    }

    /**
     * Licenciaturas adicionales (participantes e invitadas). Son solo informativas: no dan
     * permisos ni intervienen en la aprobación. Reglas:
     *  - activas, distintas de la principal y sin repetirse entre sí;
     *  - 'participante': una de las licenciaturas de quien edita (o una que ya participaba);
     *  - 'invitada': cualquier licenciatura activa;
     *  - cada cuatrimestre una sola vez dentro de su licenciatura.
     */
    private function validarLicenciaturasAdicionales(MessageBag $errores, int $licenciaturaId): void
    {
        $lista = $this->input('licenciaturas_adicionales');

        if (! is_array($lista) || $lista === []) {
            return;
        }

        /** @var User $user */
        $user = $this->user();
        $reserva = $this->route('reserva');
        $activas = Licenciatura::query()->where('estatus', 'activa')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $propias = array_map('intval', $user->licenciaturas()->pluck('licenciaturas.id')->all());
        $yaParticipaban = $reserva instanceof Reserva
            ? $reserva->licenciaturasAdicionales()->where('tipo', ReservaLicenciatura::PARTICIPANTE)->pluck('licenciatura_id')->map(fn ($id): int => (int) $id)->all()
            : [];
        $vistas = [];

        foreach ($lista as $i => $bloque) {
            if (! is_array($bloque) || ! is_numeric($bloque['licenciatura_id'] ?? null) || $errores->has("licenciaturas_adicionales.$i.licenciatura_id")) {
                continue;
            }

            $id = (int) $bloque['licenciatura_id'];
            $clave = "licenciaturas_adicionales.$i.licenciatura_id";

            if ($id === $licenciaturaId) {
                $errores->add($clave, 'Esa es la licenciatura principal de la solicitud; no se agrega de nuevo.');
            } elseif (in_array($id, $vistas, true)) {
                $errores->add($clave, 'La licenciatura ya está agregada.');
            } elseif (! in_array($id, $activas, true)) {
                $errores->add($clave, 'La licenciatura no existe o no está activa.');
            } elseif (($bloque['tipo'] ?? null) === ReservaLicenciatura::PARTICIPANTE && ! in_array($id, [...$propias, ...$yaParticipaban], true)) {
                $errores->add($clave, 'Solo puedes agregar como participantes tus licenciaturas. Usa «Invitar licenciatura» para las demás.');
            }

            $vistas[] = $id;
            $cuatrimestres = array_filter(array_map(
                fn (mixed $c): ?int => is_array($c) && is_numeric($c['id'] ?? null) ? (int) $c['id'] : null,
                (array) ($bloque['cuatrimestres'] ?? []),
            ));

            if (count($cuatrimestres) !== count(array_unique($cuatrimestres))) {
                $errores->add("licenciaturas_adicionales.$i.cuatrimestres", 'Un cuatrimestre está repetido en esta licenciatura.');
            }
        }
    }

    /**
     * Cada licenciatura (principal, participantes e invitadas) solo admite del primer cuatrimestre
     * a su cantidad configurada (licenciaturas.cantidad_cuatrimestres). Así una petición
     * manipulada no puede registrar, p. ej., un décimo cuatrimestre en una licenciatura de nueve.
     */
    private function validarCuatrimestresDeLicenciaturas(MessageBag $errores, int $licenciaturaId): void
    {
        $listas = [];

        if (! $errores->has('licenciatura_id') && $licenciaturaId > 0) {
            $listas['cuatrimestres'] = [$licenciaturaId, $this->input('cuatrimestres')];
        }

        foreach ((array) $this->input('licenciaturas_adicionales', []) as $i => $bloque) {
            if (is_array($bloque) && is_numeric($bloque['licenciatura_id'] ?? null) && ! $errores->has("licenciaturas_adicionales.$i.licenciatura_id")) {
                $listas["licenciaturas_adicionales.$i.cuatrimestres"] = [(int) $bloque['licenciatura_id'], $bloque['cuatrimestres'] ?? null];
            }
        }

        $ids = [];

        foreach ($listas as [, $lista]) {
            foreach (is_array($lista) ? $lista : [] as $c) {
                if (is_array($c) && is_numeric($c['id'] ?? null)) {
                    $ids[] = (int) $c['id'];
                }
            }
        }

        if ($ids === []) {
            return;
        }

        $cuatrimestres = Cuatrimestre::query()->whereKey(array_unique($ids))->get(['id', 'numero', 'nombre'])->keyBy('id');
        $licenciaturas = Licenciatura::query()->whereKey(array_unique(array_column($listas, 0)))->get(['id', 'nombre', 'cantidad_cuatrimestres'])->keyBy('id');

        foreach ($listas as $clave => [$id, $lista]) {
            $licenciatura = $licenciaturas->get($id);

            if ($licenciatura === null || $errores->has($clave)) {
                continue;
            }

            foreach (is_array($lista) ? $lista : [] as $c) {
                $cuatrimestre = is_array($c) && is_numeric($c['id'] ?? null) ? $cuatrimestres->get((int) $c['id']) : null;

                if ($cuatrimestre !== null && $cuatrimestre->numero > $licenciatura->cantidad_cuatrimestres) {
                    $errores->add($clave, "{$licenciatura->nombre} tiene {$licenciatura->cantidad_cuatrimestres} cuatrimestres: el ".mb_strtolower($cuatrimestre->nombre).' no está disponible.');

                    break;
                }
            }
        }
    }

    /**
     * «Esta reserva se repite»: frecuencia, y número de repeticiones o fecha de fin (que tiene
     * prioridad). Límites de RecurrenciaReserva: repeticiones por bloque, fecha de fin a lo más un
     * año después de la primera fecha y bloques totales de la solicitud.
     */
    private function validarRepeticion(MessageBag $errores): void
    {
        if (! $this->boolean('repite') || $errores->has('repeticion_frecuencia') || $errores->has('repeticion_cantidad') || $errores->has('repeticion_hasta')) {
            return;
        }

        $cantidad = $this->input('repeticion_cantidad');
        $hasta = $this->input('repeticion_hasta');
        $hasta = is_string($hasta) && $hasta !== '' ? $hasta : null;

        if ($hasta === null && ! is_numeric($cantidad)) {
            $errores->add('repeticion_cantidad', 'Indica el número de repeticiones o la fecha de fin.');

            return;
        }

        $fechas = array_values(array_filter(array_map(
            fn (mixed $d): ?string => is_array($d) && is_string($d['fecha'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['fecha']) ? $d['fecha'] : null,
            (array) $this->input('detalles', []),
        )));

        if ($fechas === []) {
            $errores->add('repeticion_frecuencia', 'Agrega al menos un espacio con fecha para repetir la reserva.');

            return;
        }

        sort($fechas);
        $primera = $fechas[0];

        if ($hasta !== null) {
            if ($hasta <= $primera) {
                $errores->add('repeticion_hasta', 'La fecha de fin debe ser posterior a la primera fecha de la actividad.');

                return;
            }

            if (Date::parse($primera)->addDays(RecurrenciaReserva::MAX_DIAS_HASTA)->toDateString() < $hasta) {
                $errores->add('repeticion_hasta', 'La fecha de fin puede ser a lo más un año después de la primera fecha.');

                return;
            }
        }

        $recurrencia = app(RecurrenciaReserva::class);
        $frecuencia = (string) $this->input('repeticion_frecuencia');
        $total = 0;
        $repeticiones = 0;

        foreach ($fechas as $fecha) {
            $n = count($recurrencia->fechas($fecha, $frecuencia, is_numeric($cantidad) ? (int) $cantidad : null, $hasta));

            if ($n > RecurrenciaReserva::MAX_REPETICIONES) {
                $errores->add('repeticion_hasta', 'Con esa fecha de fin habría más de '.RecurrenciaReserva::MAX_REPETICIONES.' repeticiones por espacio. Elige una fecha más cercana o una frecuencia menor.');

                return;
            }

            $repeticiones += $n;
            $total += 1 + $n;
        }

        if ($repeticiones === 0) {
            $errores->add('repeticion_hasta', 'Con esa fecha de fin no se genera ninguna repetición.');
        } elseif ($total > RecurrenciaReserva::MAX_BLOQUES) {
            $errores->add('repeticion_cantidad', "Se generarían {$total} actividades; el máximo por solicitud es ".RecurrenciaReserva::MAX_BLOQUES.'. Reduce las repeticiones o los espacios.');
        }
    }

    /**
     * La cantidad total son los alumnos distintos de la actividad. No se calcula sumando los
     * espacios (un mismo grupo puede usar varios espacios en distintos horarios), pero no puede
     * ser menor que la de ningún espacio: todos los que usan un espacio participan.
     */
    private function validarTotalAlumnos(MessageBag $errores): void
    {
        $total = $this->input('cantidad_total_alumnos');

        if (! is_numeric($total) || $errores->has('cantidad_total_alumnos')) {
            return;
        }

        $porEspacio = array_filter(array_map(
            fn (mixed $d): int => is_array($d) && is_numeric($d['cantidad_alumnos'] ?? null) ? (int) $d['cantidad_alumnos'] : 0,
            (array) $this->input('detalles', []),
        ));

        if ($porEspacio !== [] && (int) $total < max($porEspacio)) {
            $errores->add('cantidad_total_alumnos', 'La cantidad total no puede ser menor que la de un espacio ('.max($porEspacio).' alumnos).');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'licenciatura_id.required' => 'Selecciona la licenciatura.',
            'licenciatura_id.in' => $this->esEdicionDelDirectivo($this->user(), $this->route('reserva'))
                ? 'El directivo no puede cambiar la licenciatura de la solicitud.'
                : 'Solo puedes registrar reservas de tus licenciaturas.',
            'actividad_id.required' => 'Selecciona el tipo de actividad.',
            'actividad_id.exists' => 'El tipo de actividad no existe o ya no está vigente.',
            'cuatrimestres.*.array' => 'El periodo cuatrimestral no es válido.',
            'cuatrimestres.*.id.required' => 'Selecciona el cuatrimestre.',
            'cuatrimestres.*.id.exists' => 'Uno de los cuatrimestres no existe.',
            'cuatrimestres.*.id.distinct' => 'Un cuatrimestre está repetido.',
            'cuatrimestres.*.grupos.*.regex' => 'Cada grupo es una sola letra (A, B, C, …).',
            'asignaturas.max' => 'Puedes registrar hasta 10 asignaturas.',
            'asignaturas.*.max' => 'El nombre de la asignatura es demasiado largo (máximo 150 caracteres).',
            'asignaturas.*.distinct' => 'La asignatura está repetida.',
            'detalles.*.espacio_id.required' => 'Selecciona el espacio.',
            'detalles.*.espacio_id.exists' => 'El espacio no existe.',
            'detalles.*.fecha.required' => 'Indica la fecha.',
            'detalles.*.fecha.date_format' => 'La fecha no es válida.',
            'detalles.*.hora_inicio.required' => 'Indica la hora de inicio.',
            'detalles.*.hora_inicio.date_format' => 'La hora de inicio no es válida.',
            'detalles.*.hora_fin.required' => 'Indica la hora de fin.',
            'detalles.*.hora_fin.date_format' => 'La hora de fin no es válida.',
            'detalles.*.hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio.',
            'licenciaturas_adicionales.max' => 'Puedes agregar hasta 10 licenciaturas.',
            'licenciaturas_adicionales.*.licenciatura_id.required' => 'Selecciona la licenciatura.',
            'licenciaturas_adicionales.*.tipo.in' => 'El tipo de licenciatura no es válido.',
            'licenciaturas_adicionales.*.cuatrimestres.*.id.exists' => 'Uno de los cuatrimestres no existe.',
            'licenciaturas_adicionales.*.cuatrimestres.*.grupos.*.regex' => 'Cada grupo es una sola letra (A, B, C, …).',
            'repeticion_frecuencia.required_if_accepted' => 'Elige la frecuencia de la repetición.',
            'repeticion_frecuencia.in' => 'La frecuencia no es válida.',
            'repeticion_cantidad.integer' => 'El número de repeticiones debe ser un número entero.',
            'repeticion_cantidad.min' => 'El número de repeticiones debe ser al menos 1.',
            'repeticion_cantidad.max' => 'Puedes repetir la reserva hasta '.RecurrenciaReserva::MAX_REPETICIONES.' veces.',
            'repeticion_hasta.date_format' => 'La fecha de fin no es válida.',
            'docente_responsable_id.integer' => 'Selecciona un docente responsable válido.',
            'coordinador_id.integer' => 'Selecciona un coordinador responsable válido.',
            'cantidad_total_alumnos.integer' => 'La cantidad total de alumnos debe ser un número entero.',
            'cantidad_total_alumnos.min' => 'La cantidad total de alumnos debe ser al menos 1.',
            'costo_alumno.numeric' => 'El costo por alumno debe ser una cantidad (usa 0 si es gratuita).',
            'costo_alumno.min' => 'El costo por alumno no puede ser negativo (usa 0 si es gratuita).',
            'descripcion_actividad.max' => 'La descripción es demasiado larga (máximo 10,000 caracteres).',
            'observaciones.max' => 'Las observaciones son demasiado largas (máximo 5,000 caracteres).',
            'nombre_actividad.required' => 'Escribe el nombre de la actividad.',
            'objetivo.required' => 'Escribe el objetivo de la actividad.',
            'detalles.*.cantidad_alumnos.required' => 'Indica la cantidad de alumnos.',
            'detalles.*.cantidad_alumnos.integer' => 'La cantidad de alumnos debe ser un número entero.',
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
        $datos = $this->safe()->except(['detalles', 'motivo_edicion', 'coordinador_id', 'licenciaturas_adicionales', 'repite', 'repeticion_frecuencia', 'repeticion_cantidad', 'repeticion_hasta']);

        // La repetición solo cambia si el formulario la envió (las peticiones sin «repite» la conservan).
        if ($this->has('repite')) {
            $repeticion = $this->repeticion();
            $datos['repeticion_frecuencia'] = $repeticion['frecuencia'] ?? null;
            $datos['repeticion_cantidad'] = $repeticion['cantidad'] ?? null;
            $datos['repeticion_hasta'] = $repeticion['hasta'] ?? null;
        }

        return $datos;
    }

    /**
     * Configuración de la repetición validada, o null si la reserva no se repite. Con fecha de
     * fin no se guarda la cantidad: la fecha tiene prioridad.
     *
     * @return array{frecuencia: string, cantidad: int|null, hasta: string|null}|null
     */
    public function repeticion(): ?array
    {
        if (! $this->boolean('repite')) {
            return null;
        }

        $hasta = $this->safe()->input('repeticion_hasta');
        $hasta = is_string($hasta) && $hasta !== '' ? $hasta : null;
        $cantidad = $this->safe()->input('repeticion_cantidad');

        return [
            'frecuencia' => (string) $this->safe()->input('repeticion_frecuencia'),
            'cantidad' => $hasta === null && is_numeric($cantidad) ? (int) $cantidad : null,
            'hasta' => $hasta,
        ];
    }

    /**
     * Licenciaturas adicionales validadas, o null si el formulario no las envió (se conservan).
     *
     * @return list<array{licenciatura_id: int, tipo: string, cuatrimestres: list<array{id: int, grupos: list<string>}>|null}>|null
     */
    public function licenciaturasAdicionales(): ?array
    {
        if (! $this->has('licenciaturas_adicionales')) {
            return null;
        }

        return array_values(array_map(fn (array $l): array => [
            'licenciatura_id' => (int) $l['licenciatura_id'],
            'tipo' => (string) $l['tipo'],
            'cuatrimestres' => isset($l['cuatrimestres']) ? array_values(array_map(fn (array $c): array => [
                'id' => (int) $c['id'],
                'grupos' => array_values(array_map('strval', (array) ($c['grupos'] ?? []))),
            ], $l['cuatrimestres'])) : null,
        ], (array) $this->safe()->input('licenciaturas_adicionales', [])));
    }

    /**
     * Coordinador responsable elegido (ya validado contra la licenciatura), o null si no se
     * eligió. No se asigna en masa: lo aplica ReservaService.
     */
    public function coordinadorElegido(): ?int
    {
        $id = $this->safe()->input('coordinador_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Motivo de la edición del directivo (solo lo usa ReservaService en esa operación).
     */
    public function motivoEdicion(): ?string
    {
        $motivo = $this->safe()->input('motivo_edicion');

        return is_string($motivo) && trim($motivo) !== '' ? trim($motivo) : null;
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
