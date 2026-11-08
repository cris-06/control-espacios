<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\Requerimiento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EspacioController extends Controller
{
    public function index(): Response
    {
        // Consultar los espacios registrados con sus requerimientos.
        $espacios = Espacio::with('requerimientos')
            ->orderBy('nombre')
            ->get();

        // Consultar únicamente los requerimientos activos para los formularios.
        $requerimientos = Requerimiento::where('estatus', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return Inertia::render('espacios/Index', [
            'espacios' => $espacios,
            'requerimientos' => $requerimientos,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('espacios', 'nombre'),
            ],
            'descripcion' => ['nullable', 'string'],
            'ubicacion' => ['nullable', 'string', 'max:150'],
            'capacidad' => ['required', 'integer', 'min:1'],
            'minimo_alumnos' => [
                'required',
                'integer',
                'min:1',
                'lte:maximo_alumnos',
            ],
            'maximo_alumnos' => [
                'required',
                'integer',
                'min:1',
                'lte:capacidad',
            ],
            'foto' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:3072',
            ],
            'requerimientos' => ['sometimes', 'array'],
            'requerimientos.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('requerimientos', 'id')
                    ->where('estatus', 'activo'),
            ],
            'requerimientos.*.cantidad_disponible' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $rutaFoto = $request->file('foto')
            ?->store('espacios', 'public');

        try {
            DB::transaction(function () use ($datos, $rutaFoto) {
                $espacio = Espacio::create([
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'] ?? null,
                    'ubicacion' => $datos['ubicacion'] ?? null,
                    'capacidad' => $datos['capacidad'],
                    'minimo_alumnos' => $datos['minimo_alumnos'],
                    'maximo_alumnos' => $datos['maximo_alumnos'],
                    'foto' => $rutaFoto,
                    'color_calendario' => '#dc2626',
                    'estatus' => 'disponible',
                ]);

                foreach ($datos['requerimientos'] ?? [] as $item) {
                    DB::table('espacio_requerimientos')->insert([
                        'espacio_id' => $espacio->id,
                        'requerimiento_id' => $item['id'],
                        'cantidad_disponible' => $item['cantidad_disponible'],
                    ]);
                }
            });
        } catch (\Throwable $error) {
            if ($rutaFoto) {
                Storage::disk('public')->delete($rutaFoto);
            }

            throw $error;
        }

        return redirect()
            ->route('espacios.index')
            ->with('success', 'Espacio registrado correctamente.');
    }

    /**
     * Actualizar los datos y requerimientos de un espacio existente.
     * La fotografía anterior se conserva si no se sube una nueva.
     */
    public function update(Request $request, Espacio $espacio): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('espacios', 'nombre')->ignore($espacio->id),
            ],
            'descripcion' => ['nullable', 'string'],
            'ubicacion' => ['nullable', 'string', 'max:150'],
            'capacidad' => ['required', 'integer', 'min:1'],
            'minimo_alumnos' => [
                'required',
                'integer',
                'min:1',
                'lte:maximo_alumnos',
            ],
            'maximo_alumnos' => [
                'required',
                'integer',
                'min:1',
                'lte:capacidad',
            ],
            'foto' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:3072',
            ],
            // Un arreglo vacío se omite al enviar multipart/form-data.
            // En ese caso se sincroniza como [] para retirar las asignaciones.
            'requerimientos' => ['sometimes', 'array'],
            'requerimientos.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('requerimientos', 'id')
                    ->where('estatus', 'activo'),
            ],
            'requerimientos.*.cantidad_disponible' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $rutaFotoAnterior = $espacio->foto;
        $rutaFotoNueva = $request->file('foto')
            ?->store('espacios', 'public');

        try {
            DB::transaction(function () use ($espacio, $datos, $rutaFotoNueva) {
                $campos = [
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'] ?? null,
                    'ubicacion' => $datos['ubicacion'] ?? null,
                    'capacidad' => $datos['capacidad'],
                    'minimo_alumnos' => $datos['minimo_alumnos'],
                    'maximo_alumnos' => $datos['maximo_alumnos'],
                ];

                if ($rutaFotoNueva) {
                    $campos['foto'] = $rutaFotoNueva;
                }

                $espacio->update($campos);

                // Sincroniza cantidades: actualiza, agrega o retira relaciones.
                $relaciones = [];
                foreach ($datos['requerimientos'] ?? [] as $item) {
                    $relaciones[$item['id']] = [
                        'cantidad_disponible' => $item['cantidad_disponible'],
                    ];
                }

                $espacio->requerimientos()->sync($relaciones);
            });
        } catch (\Throwable $error) {
            // Si falla la BD, no dejar una fotografía nueva sin utilizar.
            if ($rutaFotoNueva) {
                Storage::disk('public')->delete($rutaFotoNueva);
            }

            throw $error;
        }

        // Borrar la fotografía anterior solo después de guardar los cambios.
        if ($rutaFotoNueva && $rutaFotoAnterior) {
            Storage::disk('public')->delete($rutaFotoAnterior);
        }

        return redirect()
            ->route('espacios.index')
            ->with('success', 'Espacio actualizado correctamente.');
    }

    /**
     * Cambiar entre disponible, mantenimiento e inactivo.
     */
    public function cambiarEstatus(Request $request, Espacio $espacio): RedirectResponse
    {
        $datos = $request->validate([
            'estatus' => [
                'required',
                Rule::in(['disponible', 'mantenimiento', 'inactivo']),
            ],
        ]);

        $espacio->update([
            'estatus' => $datos['estatus'],
        ]);

        return redirect()
            ->route('espacios.index')
            ->with('success', 'Estado del espacio actualizado correctamente.');
    }
}
