<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cuatrimestre;
use App\Models\Licenciatura;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de licenciaturas (admin y superadmin). No se eliminan: se inactivan,
 * porque reservas y usuario_licenciaturas las referencian.
 */
class LicenciaturaController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('administrar');

        return Inertia::render('admin/licenciaturas/Index', [
            'licenciaturas' => Licenciatura::query()
                ->withCount([
                    'usuarios as coordinadores_count' => fn (Builder $q) => $q->whereHas('rol', fn (Builder $r) => $r->where('nombre', Rol::COORDINADOR)),
                    'usuarios as docentes_count' => fn (Builder $q) => $q->whereHas('rol', fn (Builder $r) => $r->where('nombre', Rol::DOCENTE)),
                ])
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'siglas', 'estatus', 'cantidad_cuatrimestres']),
            // Solo el superadministrador configura la cantidad de cuatrimestres.
            'puedeConfigurarCuatrimestres' => request()->user()?->esSuperadmin() ?? false,
            'maxCuatrimestres' => Licenciatura::MAX_CUATRIMESTRES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('administrar');

        $datos = $this->validar($request);

        DB::transaction(function () use ($datos): void {
            Licenciatura::create($datos);
            Cuatrimestre::asegurarHasta($datos['cantidad_cuatrimestres'] ?? Licenciatura::CUATRIMESTRES_PREDETERMINADOS);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Licenciatura creada.']);

        return back();
    }

    public function update(Request $request, Licenciatura $licenciatura): RedirectResponse
    {
        Gate::authorize('administrar');

        $datos = $this->validar($request, $licenciatura);

        DB::transaction(function () use ($datos, $licenciatura): void {
            $nueva = $datos['cantidad_cuatrimestres'] ?? null;

            // Bajar la cantidad no puede dejar fuera datos ya registrados (reservas, licenciaturas
            // participantes o asignaturas en un cuatrimestre mayor): se rechaza y no se cambia nada.
            if ($nueva !== null && $nueva < $licenciatura->cantidad_cuatrimestres) {
                Licenciatura::query()->whereKey($licenciatura->id)->lockForUpdate()->first();
                $usado = $licenciatura->cuatrimestreMasAltoUsado();

                if ($usado !== null && $usado['numero'] > $nueva) {
                    throw ValidationException::withMessages([
                        'cantidad_cuatrimestres' => "No puedes reducirla a {$nueva}: el ".mb_strtolower(Cuatrimestre::nombrePara($usado['numero']))
                            ." ya tiene grupos o reservaciones registrados ({$usado['donde']}). La cantidad mínima es {$usado['numero']}.",
                    ]);
                }
            }

            $licenciatura->update($datos);

            if ($nueva !== null) {
                Cuatrimestre::asegurarHasta($nueva);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Licenciatura actualizada.']);

        return back();
    }

    public function cambiarEstatus(Licenciatura $licenciatura): RedirectResponse
    {
        Gate::authorize('administrar');

        $licenciatura->update(['estatus' => $licenciatura->estatus === 'activa' ? 'inactiva' : 'activa']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $licenciatura->estatus === 'activa' ? 'Licenciatura activada.' : 'Licenciatura inactivada.',
        ]);

        return back();
    }

    /**
     * Las siglas son opcionales: si la petición no las trae, no se tocan; vacías = sin siglas.
     * Se normalizan (sin espacios a los lados y en mayúsculas) ANTES de validar la unicidad.
     * Cambiarlas solo afecta a las reservas que se creen después: los folios ya generados no cambian.
     *
     * La cantidad de cuatrimestres es exclusiva del superadministrador: si otro rol la envía
     * (aunque sea con una petición manipulada) se responde 403 y no se guarda nada. Si no se
     * envía, no se toca (al crear queda el valor predeterminado, 9).
     *
     * @return array{nombre: string, siglas?: string|null, cantidad_cuatrimestres?: int}
     */
    private function validar(Request $request, ?Licenciatura $licenciatura = null): array
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->exists('cantidad_cuatrimestres') && ! $user->esSuperadmin()) {
            abort(403, 'Solo el superadministrador puede configurar la cantidad de cuatrimestres.');
        }

        $request->merge(['nombre' => trim((string) $request->input('nombre'))]);

        if ($request->exists('siglas')) {
            $siglas = $request->input('siglas');
            $siglas = is_string($siglas) ? mb_strtoupper(trim($siglas)) : $siglas;
            $request->merge(['siglas' => $siglas === '' ? null : $siglas]);
        }

        /** @var array{nombre: string, siglas?: string|null, cantidad_cuatrimestres?: int} $datos */
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('licenciaturas', 'nombre')->ignore($licenciatura?->id)],
            'siglas' => ['sometimes', 'nullable', 'string', 'regex:'.Licenciatura::PATRON_SIGLAS, Rule::unique('licenciaturas', 'siglas')->ignore($licenciatura?->id)],
            'cantidad_cuatrimestres' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.Licenciatura::MAX_CUATRIMESTRES],
        ], [
            'cantidad_cuatrimestres.required' => 'Indica la cantidad de cuatrimestres.',
            'cantidad_cuatrimestres.integer' => 'La cantidad de cuatrimestres debe ser un número entero.',
            'cantidad_cuatrimestres.min' => 'La licenciatura debe tener al menos 1 cuatrimestre.',
            'cantidad_cuatrimestres.max' => 'La cantidad máxima es de '.Licenciatura::MAX_CUATRIMESTRES.' cuatrimestres.',
            'nombre.required' => 'Escribe el nombre de la licenciatura.',
            'nombre.unique' => 'Ya existe una licenciatura con ese nombre.',
            'siglas.string' => 'Las siglas deben ser texto.',
            'siglas.regex' => 'Las siglas deben tener de 2 a 10 letras (A-Z), sin espacios, números ni acentos.',
            'siglas.unique' => 'Otra licenciatura ya usa esas siglas.',
        ]);

        if (isset($datos['cantidad_cuatrimestres'])) {
            $datos['cantidad_cuatrimestres'] = (int) $datos['cantidad_cuatrimestres'];
        }

        return $datos;
    }
}
