<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\Requerimiento;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EspacioController extends Controller
{
    public function index(): Response
    {
        // Consultar los espacios registrados
        $espacios = Espacio::with('requerimientos')->orderBy('nombre')->get();

        // Consultar únicamente requerimientos activos
        $requerimientos = Requerimiento::where('estatus', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        // Enviar los datos a la pantalla Vue
        return Inertia::render('espacios/Index', [
            'espacios' => $espacios,
            'requerimientos' => $requerimientos,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Validar los datos recibidos del formulario
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

        // Guardar la fotografía si se seleccionó una
        $rutaFoto = $request->file('foto')
            ?->store('espacios', 'public');

        try {
            DB::transaction(function () use ($datos, $rutaFoto) {
                // Registrar el espacio
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

                // Asignar los requerimientos seleccionados
                foreach ($datos['requerimientos'] ?? [] as $item) {
                    DB::table('espacio_requerimientos')->insert([
                        'espacio_id' => $espacio->id,
                        'requerimiento_id' => $item['id'],
                        'cantidad_disponible' =>
                        $item['cantidad_disponible'],
                    ]);
                }
            });
        } catch (\Throwable $error) {
            // Evitar dejar una foto sin espacio asociado
            if ($rutaFoto) {
                Storage::disk('public')->delete($rutaFoto);
            }

            throw $error;
        }

        return redirect()
            ->route('espacios.index')
            ->with('success', 'Espacio registrado correctamente.');
    }
}
