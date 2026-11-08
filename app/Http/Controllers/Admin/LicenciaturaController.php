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
                ->get(['id', 'nombre', 'estatus']),
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
     * @return array{nombre: string}
     */
    private function validar(Request $request, ?Licenciatura $licenciatura = null): array
    {
        $request->merge(['nombre' => trim((string) $request->input('nombre'))]);

        /** @var array{nombre: string} */
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('licenciaturas', 'nombre')->ignore($licenciatura?->id)],
        ], [
            'nombre.required' => 'Escribe el nombre de la licenciatura.',
            'nombre.unique' => 'Ya existe una licenciatura con ese nombre.',
        ]);
    }
}
