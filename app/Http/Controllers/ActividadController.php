<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use App\Models\Actividad;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;

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
            'nombre' => ['required', 'string', 'max:150'],
        ]);

        $nombre = trim($datos['nombre']);

        // Buscar la actividad, aunque esté eliminada
        $existente = Actividad::where('nombre', $nombre)->first();

        if ($existente) {
            // Recuperar la actividad eliminada
            if ($existente->estatus === 'eliminada') {
                $existente->update([
                    'estatus' => 'activa',
                ]);

                return back()->with(
                    'success',
                    'Actividad recuperada correctamente.'
                );
            }

            // Evitar duplicados activos
            throw ValidationException::withMessages([
                'nombre' => 'Esta actividad ya está registrada.',
            ]);
        }

        Actividad::create([
            'nombre' => $nombre,
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
