<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ─── Rutas de autenticación ───
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ─── Rutas protegidas ───
Route::middleware('auth')->group(function () {
    // --- Acceso para todos los usuarios autenticados ---
    Route::get('/', [ConsultaController::class, 'index'])->name('consultas.index');
    Route::get('/progreso/{consulta}', [ConsultaController::class, 'progreso'])->name('consultas.progreso');
    Route::get('/buscar-cedula', [ConsultaController::class, 'buscarCedula'])->name('consultas.buscar');
    Route::get('/consultar', [ConsultaController::class, 'consultar'])->name('consultas.consultar');
    Route::get('/descargar/{consulta}/resultado', [ConsultaController::class, 'descargarResultado'])->name('consultas.descargar.resultado');
    Route::get('/descargar/{consulta}/original', [ConsultaController::class, 'descargarOriginal'])->name('consultas.descargar.original');

    // --- Solo administradores ---
    Route::middleware('admin')->group(function () {
        Route::post('/validar', [ConsultaController::class, 'validar'])->name('consultas.validar');
        Route::post('/procesar', [ConsultaController::class, 'procesar'])->name('consultas.procesar');
        Route::post('/reprocesar/{consulta}', [ConsultaController::class, 'reprocesar'])->name('consultas.reprocesar');
        Route::delete('/consultas/{consulta}', [ConsultaController::class, 'eliminar'])->name('consultas.eliminar');

        // Gestión de Usuarios
        Route::get('/usuarios', [UserController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UserController::class, 'store'])->name('usuarios.store');
        Route::put('/usuarios/{user}', [UserController::class, 'update'])->name('usuarios.update');
        Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])->name('usuarios.destroy');
    });
});
