<?php

use App\Http\Controllers\ActividadController;
use App\Http\Controllers\Admin\LicenciaturaController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisponibilidadController;
use App\Http\Controllers\EspacioController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RequerimientoController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\ReservaTransicionController;
use App\Http\Controllers\SolicitudController;
use App\Http\Controllers\VerificarDisponibilidadController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Todos los roles autenticados.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');
    // Consulta en vivo desde el formulario de solicitud (JSON). La autoriza el propio controlador.
    Route::get('disponibilidad/verificar', VerificarDisponibilidadController::class)
        ->middleware('throttle:60,1')
        ->name('disponibilidad.verificar');
    Route::get('solicitudes', [SolicitudController::class, 'index'])->name('solicitudes.index');

    // Reservas: cada acción la autoriza ReservaPolicy; los estados, ReservaWorkflowService.
    Route::resource('reservas', ReservaController::class)->only(['create', 'store', 'show', 'edit', 'update']);
    Route::post('reservas/{reserva}/transicion', ReservaTransicionController::class)
        ->middleware('throttle:30,1')
        ->name('reservas.transicion');

    // Notificaciones propias (tabla `notificaciones`).
    Route::get('notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
    Route::post('notificaciones/leer-todas', [NotificacionController::class, 'leerTodas'])->name('notificaciones.leer-todas');
    Route::post('notificaciones/{notificacion}/leer', [NotificacionController::class, 'leer'])
        ->whereNumber('notificacion')
        ->name('notificaciones.leer');
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

/*
|--------------------------------------------------------------------------
| Administración de espacios y catálogos
|--------------------------------------------------------------------------
| Solo SuperAdmin y Admin pueden gestionar estos módulos.
*/

Route::middleware([
    'auth',
    'verified',
    'rol:admin,superadmin',
    'can:administrar',
])->group(function () {

    // ADMINISTRACIÓN DE ESPACIOS

    Route::get('espacios', [
        EspacioController::class,
        'index',
    ])->name('espacios.index');

    Route::post('espacios', [
        EspacioController::class,
        'store',
    ])->name('espacios.store');

    Route::put('espacios/{espacio}', [
        EspacioController::class,
        'update',
    ])->name('espacios.update');

    Route::patch('espacios/{espacio}/estatus', [
        EspacioController::class,
        'cambiarEstatus',
    ])->name('espacios.estatus');

    // CATÁLOGO DE REQUERIMIENTOS

    Route::get('requerimientos', [
        RequerimientoController::class,
        'index',
    ])->name('requerimientos.index');

    Route::post('requerimientos', [
        RequerimientoController::class,
        'store',
    ])->name('requerimientos.store');

    Route::put('requerimientos/{requerimiento}', [
        RequerimientoController::class,
        'update',
    ])->name('requerimientos.update');

    Route::patch('requerimientos/{requerimiento}/estatus', [
        RequerimientoController::class,
        'cambiarEstatus',
    ])->name('requerimientos.estatus');

    // CATÁLOGO DE ACTIVIDADES

    Route::get('actividades', [
        ActividadController::class,
        'index',
    ])->name('actividades.index');

    Route::post('actividades', [
        ActividadController::class,
        'store',
    ])->name('actividades.store');

    Route::put('actividades/{actividad}', [
        ActividadController::class,
        'update',
    ])->name('actividades.update');

    Route::patch('actividades/{actividad}/estatus', [
        ActividadController::class,
        'cambiarEstatus',
    ])->name('actividades.estatus');
});

require __DIR__.'/settings.php';
