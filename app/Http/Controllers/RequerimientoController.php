<?php

namespace App\Http\Controllers;

use App\Models\Requerimiento;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;

class RequerimientoController extends Controller
{
    // Mostrar todos los requerimientos
    public function index(): Response
    {
        $requerimientos = Requerimiento::orderBy('nombre')
            ->get();

        return Inertia::render('requerimientos/Index', [
            'requerimientos' => $requerimientos,
        ]);
    }

    // Registrar un requerimiento

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
        ]);

        $nombre = trim($datos['nombre']);

        // Buscar el nombre, incluso si fue eliminado
        $existente = Requerimiento::where('nombre', $nombre)->first();

        if ($existente) {
            // Si estaba eliminado, recuperarlo
            if ($existente->estatus === 'eliminado') {
                $existente->update([
                    'estatus' => 'activo',
                ]);

                return back()->with(
                    'success',
                    'Requerimiento recuperado correctamente.'
                );
            }

            // Si ya está activo, evitar duplicarlo
            throw ValidationException::withMessages([
                'nombre' => 'Este requerimiento ya está registrado.',
            ]);
        }

        Requerimiento::create([
            'nombre' => $nombre,
            'estatus' => 'activo',
        ]);

        return back()->with(
            'success',
            'Requerimiento registrado correctamente.'
        );
    }

    // Editar el nombre de un requerimiento
    public function update(
        Request $request,
        Requerimiento $requerimiento
    ): RedirectResponse {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('requerimientos', 'nombre')
                    ->ignore($requerimiento->id),
            ],
        ]);

        $requerimiento->update($datos);

        return back()->with(
            'success',
            'Requerimiento actualizado correctamente.'
        );
    }

    // Activar o desactivar un requerimiento
    public function cambiarEstatus(
        Request $request,
        Requerimiento $requerimiento
    ): RedirectResponse {
        $datos = $request->validate([
            'estatus' => [
                'required',
                Rule::in(['activo', 'eliminado']),
            ],
        ]);

        $requerimiento->update([
            'estatus' => $datos['estatus'],
        ]);

        return back()->with(
            'success',
            'Estado del requerimiento actualizado.'
        );
    }
}
