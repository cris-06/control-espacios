<?php

namespace App\Http\Controllers;

use App\Support\DatosEjemplo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TEMPORAL: permite revisar la interfaz de cada rol hasta que exista la autorización real.
 */
class DemoRolController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_if(app()->isProduction(), 404);

        $datos = $request->validate([
            'rol' => ['required', Rule::in(DatosEjemplo::ROLES)],
        ]);

        $request->session()->put('rol_demo', $datos['rol']);

        // La página actual puede no estar permitida para el rol nuevo.
        return to_route('dashboard');
    }
}
