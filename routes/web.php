<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisponibilidadController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\SolicitudController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Todos los roles autenticados.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');
    Route::get('solicitudes', [SolicitudController::class, 'index'])->name('solicitudes.index');
});

// Coordinación y administración.
Route::middleware(['auth', 'verified', 'rol:coordinador,admin,superadmin'])->group(function () {
    Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
});

// Solo administración. Aquí irán las rutas de gestión de usuarios y catálogos (Fase 3).
Route::middleware(['auth', 'verified', 'rol:admin,superadmin'])->prefix('admin')->name('admin.')->group(function () {
    //
});

require __DIR__.'/settings.php';
