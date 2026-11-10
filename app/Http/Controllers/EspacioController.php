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
    // Mostrar los espacios y los requerimientos disponibles
    public function index(): Response
    {
        $espacios = Espacio::with('requerimientos')
            ->orderBy('nombre')
            ->get();

        $requerimientos = Requerimiento::where('estatus', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return Inertia::render('espacios/Index', [
            'espacios' => $espacios,
            'requerimientos' => $requerimientos,
        ]);
    }

    // Registrar un espacio
    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validarDatos($request);

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

    // Editar un espacio existente
    public function update(
        Request $request,
        Espacio $espacio
    ): RedirectResponse {

        $datos = $this->validarDatos($request, $espacio);

        $rutaFotoAnterior = $espacio->foto;

        $rutaFotoNueva = $request->file('foto')
            ?->store('espacios', 'public');

        try {
            DB::transaction(function () use (
                $espacio,
                $datos,
                $rutaFotoNueva
            ) {
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

                // Actualizar los requerimientos del espacio
                $relaciones = [];

                foreach ($datos['requerimientos'] ?? [] as $item) {
                    $relaciones[$item['id']] = [
                        'cantidad_disponible' => $item['cantidad_disponible'],
                    ];
                }

                $espacio->requerimientos()->sync($relaciones);
            });
        } catch (\Throwable $error) {
            if ($rutaFotoNueva) {
                Storage::disk('public')->delete($rutaFotoNueva);
            }

            throw $error;
        }

        // Eliminar foto anterior después de guardar
        if ($rutaFotoNueva && $rutaFotoAnterior) {
            Storage::disk('public')->delete($rutaFotoAnterior);
        }

        return redirect()
            ->route('espacios.index')
            ->with('success', 'Espacio actualizado correctamente.');
    }

    // Cambiar el estatus de un espacio
    public function cambiarEstatus(
        Request $request,
        Espacio $espacio
    ): RedirectResponse {
        $datos = $request->validate([
            'estatus' => [
                'required',
                Rule::in([
                    'disponible',
                    'mantenimiento',
                    'inactivo',
                ]),
            ],
        ], [
            'estatus.required' => 'Debes seleccionar un estado.',
            'estatus.in' => 'El estado seleccionado no es válido.',
        ]);

        $espacio->update([
            'estatus' => $datos['estatus'],
        ]);

        return redirect()
            ->route('espacios.index')
            ->with(
                'success',
                'Estado del espacio actualizado correctamente.'
            );
    }

    /**
     * Validaciones compartidas para registrar y editar
     *
     * @return array<string, mixed>
     */
    private function validarDatos(
        Request $request,
        ?Espacio $espacio = null
    ): array {
        $reglaNombre = Rule::unique('espacios', 'nombre');

        if ($espacio !== null) {
            $reglaNombre->ignore($espacio->id);
        }

        return $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100',
                $reglaNombre,
            ],
            'descripcion' => [
                'nullable',
                'string',
            ],
            'ubicacion' => [
                'nullable',
                'string',
                'max:150',
            ],
            'capacidad' => [
                'required',
                'integer',
                'min:1',
            ],
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
            'requerimientos' => [
                'sometimes',
                'array',
            ],
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
        ], [
            // Mensajes generales
            'required' => 'El campo :attribute es obligatorio.',

            'string' => 'El campo :attribute debe contener texto.',

            'integer' => 'El campo :attribute debe ser un número entero.',

            'min' => 'El campo :attribute debe ser como mínimo :min.',

            'max' => 'El campo :attribute no debe superar :max.',

            'array' => 'Los requerimientos deben enviarse como una lista.',

            'distinct' => 'No puedes repetir el mismo requerimiento.',

            'exists' => 'El requerimiento seleccionado no es válido.',

            // Nombre
            'nombre.unique' => 'Ya existe un espacio registrado con ese nombre.',

            // Capacidad
            'minimo_alumnos.lte' => 'El mínimo de alumnos no puede superar el máximo de alumnos.',

            'maximo_alumnos.lte' => 'El máximo de alumnos no puede superar la capacidad total del espacio.',

            // Fotografía
            'foto.uploaded' => 'No se pudo subir la fotografía. Revisa la configuración de carga de PHP.',

            'foto.image' => 'El archivo seleccionado debe ser una imagen válida.',

            'foto.mimes' => 'Solo se permiten fotografías JPG, JPEG, PNG o WEBP.',

            'foto.max' => 'La fotografía no debe superar los 3 MB.',
        ], [
            // Nombres de campos en español
            'nombre' => 'nombre del espacio',
            'descripcion' => 'descripción',
            'ubicacion' => 'ubicación',
            'capacidad' => 'capacidad total',
            'minimo_alumnos' => 'mínimo de alumnos',
            'maximo_alumnos' => 'máximo de alumnos',
            'foto' => 'fotografía',
            'requerimientos.*.id' => 'requerimiento',
            'requerimientos.*.cantidad_disponible' => 'cantidad disponible',
        ]);
    }
}
