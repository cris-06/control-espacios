<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoRolController;
use App\Http\Controllers\DisponibilidadController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\SolicitudController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');
    Route::get('solicitudes', [SolicitudController::class, 'index'])->name('solicitudes.index');
    Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
    Route::post('demo/rol', DemoRolController::class)->name('demo.rol');
});

require __DIR__.'/settings.php';
