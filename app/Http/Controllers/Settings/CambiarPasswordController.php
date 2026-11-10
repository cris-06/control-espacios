<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cambio obligatorio de la contraseña temporal asignada por administración.
 */
class CambiarPasswordController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->debe_cambiar_password) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/CambiarPassword', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => $request->password,
            'debe_cambiar_password' => false,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contraseña actualizada. ¡Bienvenido!']);

        return to_route('dashboard');
    }
}
