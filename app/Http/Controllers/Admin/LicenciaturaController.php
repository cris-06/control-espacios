<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Licenciatura;
use App\Models\Rol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
                ->get(['id', 'nombre', 'siglas', 'estatus']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('administrar');

        Licenciatura::create($this->validar($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Licenciatura creada.']);

        return back();
    }

    public function update(Request $request, Licenciatura $licenciatura): RedirectResponse
    {
        Gate::authorize('administrar');

        $licenciatura->update($this->validar($request, $licenciatura));

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
     * @return array{nombre: string, siglas?: string|null}
     */
    private function validar(Request $request, ?Licenciatura $licenciatura = null): array
    {
        $request->merge(['nombre' => trim((string) $request->input('nombre'))]);

        if ($request->exists('siglas')) {
            $siglas = $request->input('siglas');
            $siglas = is_string($siglas) ? mb_strtoupper(trim($siglas)) : $siglas;
            $request->merge(['siglas' => $siglas === '' ? null : $siglas]);
        }

        /** @var array{nombre: string, siglas?: string|null} */
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('licenciaturas', 'nombre')->ignore($licenciatura?->id)],
            'siglas' => ['sometimes', 'nullable', 'string', 'regex:'.Licenciatura::PATRON_SIGLAS, Rule::unique('licenciaturas', 'siglas')->ignore($licenciatura?->id)],
        ], [
            'nombre.required' => 'Escribe el nombre de la licenciatura.',
            'nombre.unique' => 'Ya existe una licenciatura con ese nombre.',
            'siglas.string' => 'Las siglas deben ser texto.',
            'siglas.regex' => 'Las siglas deben tener de 2 a 10 letras (A-Z), sin espacios, números ni acentos.',
            'siglas.unique' => 'Otra licenciatura ya usa esas siglas.',
        ]);
    }
}
