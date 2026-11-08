<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use App\Models\Actividad;
use Inertia\Inertia;
use Inertia\Response;

class ActividadController extends Controller
{
    public function index(): Response
    {
        $actividades = Actividad::orderBy('nombre')->get();

        return Inertia::render('actividades/Index', [
            'actividades' => $actividades,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:150',
                Rule::unique('actividades', 'nombre'),
            ],
        ]);

        Actividad::create([
            'nombre' => $datos['nombre'],
            'estatus' => 'activa',
        ]);

        return back()->with(
            'success',
            'Actividad registrada correctamente.'
        );
    }

    public function update(
        Request $request,
        Actividad $actividad
    ): RedirectResponse {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:150',
                Rule::unique('actividades', 'nombre')
                    ->ignore($actividad->id),
            ],
        ]);

        $actividad->update($datos);

        return back()->with(
            'success',
            'Actividad actualizada correctamente.'
        );
    }

    public function cambiarEstatus(
        Request $request,
        Actividad $actividad
    ): RedirectResponse {
        $datos = $request->validate([
            'estatus' => [
                'required',
                Rule::in(['activa', 'eliminada']),
            ],
        ]);

        $actividad->update([
            'estatus' => $datos['estatus'],
        ]);

        return back()->with(
            'success',
            'Estado de la actividad actualizado.'
        );
    }
}
