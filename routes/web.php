<?php

use App\Http\Controllers\Admin\LicenciaturaController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisponibilidadController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\ReservaTransicionController;
use App\Http\Controllers\SolicitudController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Todos los roles autenticados.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');
    Route::get('solicitudes', [SolicitudController::class, 'index'])->name('solicitudes.index');

    // Reservas: cada acción la autoriza ReservaPolicy; los estados, ReservaWorkflowService.
    Route::resource('reservas', ReservaController::class)->only(['create', 'store', 'show', 'edit', 'update']);
    Route::post('reservas/{reserva}/transicion', ReservaTransicionController::class)
        ->middleware('throttle:30,1')
        ->name('reservas.transicion');
});

// Coordinación y administración.
Route::middleware(['auth', 'verified', 'rol:coordinador,admin,superadmin'])->group(function () {
    Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
});

// Solo administración. Cada acción además pasa por UserPolicy / Gate 'administrar'.
Route::middleware(['auth', 'verified', 'rol:admin,superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('usuarios', UsuarioController::class)
        ->except(['show', 'destroy'])
        ->parameters(['usuarios' => 'usuario']);
    Route::patch('usuarios/{usuario}/estatus', [UsuarioController::class, 'cambiarEstatus'])->name('usuarios.estatus');
    Route::post('usuarios/{usuario}/password', [UsuarioController::class, 'restablecerPassword'])->name('usuarios.password');

    Route::get('licenciaturas', [LicenciaturaController::class, 'index'])->name('licenciaturas.index');
    Route::post('licenciaturas', [LicenciaturaController::class, 'store'])->name('licenciaturas.store');
    Route::put('licenciaturas/{licenciatura}', [LicenciaturaController::class, 'update'])->name('licenciaturas.update');
    Route::patch('licenciaturas/{licenciatura}/estatus', [LicenciaturaController::class, 'cambiarEstatus'])->name('licenciaturas.estatus');
});

require __DIR__.'/settings.php';
