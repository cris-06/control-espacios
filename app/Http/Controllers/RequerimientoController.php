<?php

namespace App\Http\Controllers;

use App\Models\Requerimiento;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('requerimientos', 'nombre'),
            ],
        ]);

        Requerimiento::create([
            'nombre' => $datos['nombre'],
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
