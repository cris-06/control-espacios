<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CatalogoEspaciosController extends Controller
{
    public function __invoke(): Response
    {
        $espacios = Espacio::query()
            ->where('estatus', 'disponible')
            ->with([
                'requerimientos' => fn($consulta) =>
                $consulta->where('estatus', 'activo'),
            ])
            ->orderBy('nombre')
            ->get()
            ->map(fn(Espacio $espacio): array => [
                'id' => $espacio->id,
                'nombre' => $espacio->nombre,
                'ubicacion' => $espacio->ubicacion,
                'foto' => $espacio->foto
                    ? Storage::disk('public')->url($espacio->foto)
                    : null,
                'capacidad' => $espacio->capacidad,
                'minimo_alumnos' => $espacio->minimo_alumnos,
                'maximo_alumnos' => $espacio->maximo_alumnos,
                'requerimientos' => $espacio->requerimientos
                    ->map(fn($requerimiento): array => [
                        'nombre' => $requerimiento->nombre,
                        'cantidad_disponible' =>
                        $requerimiento->pivot->cantidad_disponible,
                    ])
                    ->values(),
            ])
            ->values();

        return Inertia::render('espacios/Catalogo', [
            'espacios' => $espacios,
        ]);
    }
}
