<?php

namespace App\Http\Controllers;

use App\Support\DatosEjemplo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SolicitudController extends Controller
{
    public function index(Request $request): Response
    {
        $reservas = DatosEjemplo::reservasVisibles(DatosEjemplo::rolActual($request));
        $estadoId = $request->integer('estado_id');

        return Inertia::render('solicitudes/Index', [
            'filtros' => ['estado_id' => $estadoId ?: null],
            'opcionesEstados' => collect(DatosEjemplo::ESTADOS)
                ->only($reservas->pluck('estado_id')->unique()->all())
                ->map(fn (string $nombre, int $id): array => ['id' => $id, 'nombre' => $nombre])
                ->values(),
            'solicitudes' => $reservas
                ->when($estadoId, fn ($coleccion) => $coleccion->where('estado_id', $estadoId))
                ->sortByDesc('fecha')
                ->values(),
        ]);
    }
}
